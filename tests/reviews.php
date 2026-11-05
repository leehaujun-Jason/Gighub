<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
foreach ([
    'Core/Csrf',
    'Core/Notification',
    'Core/AuthSession',
    'Core/AuthGuard',
    'Core/Router',
    'Repositories/UserRepository',
    'Services/LoginValidator',
    'Services/AuthenticationService',
    'Repositories/ReviewRepository',
    'Services/ReviewException',
    'Services/ReviewSubmissionValidator',
    'Services/ReviewService',
    'Controllers/ReviewController',
    'Repositories/MilestoneRepository',
    'Services/MilestoneException',
    'Services/MilestoneService',
    'Controllers/MilestoneController',
    'Repositories/FreelancerProfileRepository',
    'Repositories/SkillRepository',
    'Services/FreelancerProfileException',
    'Services/FreelancerProfileValidator',
    'Services/FreelancerProfileService',
    'Controllers/FreelancerProfileController',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

use GigHub\Core\Csrf;
use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Core\Notification;
use GigHub\Repositories\UserRepository;
use GigHub\Services\AuthenticationService;
use GigHub\Repositories\ReviewRepository;
use GigHub\Services\ReviewException;
use GigHub\Services\ReviewSubmissionValidator;
use GigHub\Services\ReviewService;
use GigHub\Controllers\ReviewController;

$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    ++$count;
}

function denied(callable $call, int $status, string $label): void {
    try {
        $call();
        check(false, $label);
    } catch (ReviewException $e) {
        check($e->status === $status, $label . ' (expected ' . $status . ', got ' . $e->status . ': ' . $e->getMessage() . ')');
    }
}

// -------------------------------------------------------------
// 1. Validator Unit Tests
// -------------------------------------------------------------
echo "Starting Module 17 Two-Way Reviews & Ratings Offline Test Suite...\n";

// Valid ratings 1 to 5
for ($r = 1; $r <= 5; $r++) {
    $res = ReviewSubmissionValidator::validate(['rating' => (string)$r, 'comment' => 'Test comment']);
    check($res['ok'] === true, "Valid rating $r accepted");
    check($res['data']['rating'] === $r, "Rating parsed as integer $r");
}

// Invalid ratings
foreach ([null, '', '0', '6', '-1', 'abc', '4.5'] as $badRating) {
    $res = ReviewSubmissionValidator::validate(['rating' => $badRating]);
    check($res['ok'] === false, "Invalid rating " . var_export($badRating, true) . " rejected");
    check(isset($res['errors']['rating']), "Error key rating set");
}

// Comment handling
$emptyCommentRes = ReviewSubmissionValidator::validate(['rating' => '5', 'comment' => '   ']);
check($emptyCommentRes['ok'] === true, 'Whitespace comment is valid');
check($emptyCommentRes['data']['comment'] === null, 'Whitespace comment normalized to null');

$nullCommentRes = ReviewSubmissionValidator::validate(['rating' => '5']);
check($nullCommentRes['ok'] === true, 'Omitted comment is valid');
check($nullCommentRes['data']['comment'] === null, 'Omitted comment is null');

$validComment = "Excellent work on the project! Delivered on time. 🚀";
$commentRes = ReviewSubmissionValidator::validate(['rating' => '5', 'comment' => $validComment]);
check($commentRes['ok'] === true, 'Valid comment with Unicode accepted');
check($commentRes['data']['comment'] === $validComment, 'Comment preserved');

// Exceeding 1000 characters
$longComment = str_repeat('a', 1001);
$longRes = ReviewSubmissionValidator::validate(['rating' => '5', 'comment' => $longComment]);
check($longRes['ok'] === false, 'Comment exceeding 1,000 characters rejected');
check(isset($longRes['errors']['comment']), 'Error key comment set');

// Exactly 1000 characters
$exactComment = str_repeat('★', 1000);
$exactRes = ReviewSubmissionValidator::validate(['rating' => '5', 'comment' => $exactComment]);
check($exactRes['ok'] === true, '1,000 Unicode characters accepted');
check(mb_strlen($exactRes['data']['comment'], 'UTF-8') === 1000, 'Length is 1000');

// -------------------------------------------------------------
// Mock PDO & Mock ReviewRepository
// -------------------------------------------------------------
class MockReviewPDO extends PDO {
    public int $txLevel = 0;
    public array $events = [];
    public ?MockReviewRepository $repo = null;
    public function __construct() {}
    public function beginTransaction(): bool {
        $this->events[] = 'tx:begin';
        ++$this->txLevel;
        return true;
    }
    public function commit(): bool {
        $this->events[] = 'tx:commit';
        --$this->txLevel;
        return true;
    }
    public function rollBack(): bool {
        $this->events[] = 'tx:rollback';
        --$this->txLevel;
        return true;
    }
    public function inTransaction(): bool { return $this->txLevel > 0; }
}

class MockReviewRepository extends ReviewRepository {
    public array $events = [];
    public ?array $job = [
        'job_id' => 2,
        'job_title' => 'Cafe Ordering System development',
        'job_status' => 'completed',
        'client_id' => 6,
        'client_name' => 'CafeOwner',
        'client_status' => 'active',
        'client_verified_at' => '2026-01-01 00:00:00',
        'awarded_bid_id' => 1,
        'bid_status' => 'accepted',
        'freelancer_id' => 116,
        'freelancer_name' => 'TechConsultant',
        'freelancer_status' => 'active',
        'freelancer_verified_at' => '2026-01-01 00:00:00',
    ];

    public array $reviews = [];
    public array $notifications = [];
    public bool $duplicateRace = false;
    public bool $failInsert = false;

    public function __construct(PDO $pdo) {
        parent::__construct($pdo);
    }

    public function getJobContext(int $jobId, bool $lock = false): array|false {
        $this->events[] = 'getJobContext:' . $jobId . ($lock ? ':lock' : '');
        return ($this->job !== null && $this->job['job_id'] === $jobId) ? $this->job : false;
    }

    public function getReview(int $jobId, int $reviewerId, bool $lock = false): array|false {
        $this->events[] = 'getReview:' . $jobId . ':' . $reviewerId . ($lock ? ':lock' : '');
        foreach ($this->reviews as $r) {
            if ($r['job_id'] === $jobId && $r['reviewer_id'] === $reviewerId) {
                return $r;
            }
        }
        return false;
    }

    public function getReviewsForJob(int $jobId): array {
        $this->events[] = 'getReviewsForJob:' . $jobId;
        return array_values(array_filter($this->reviews, fn($r) => $r['job_id'] === $jobId));
    }

    public function getFreelancerReputation(int $freelancerId): array {
        $this->events[] = 'getFreelancerReputation:' . $freelancerId;
        $matched = array_values(array_filter($this->reviews, function($r) use ($freelancerId) {
            return $r['reviewee_id'] === $freelancerId && $r['reviewer_id'] === 6;
        }));
        $count = count($matched);
        if ($count === 0) return ['count' => 0, 'average' => null];
        $sum = array_sum(array_column($matched, 'rating'));
        return ['count' => $count, 'average' => round($sum / $count, 1)];
    }

    public function getFreelancerReviews(int $freelancerId, int $limit = 20): array {
        $this->events[] = 'getFreelancerReviews:' . $freelancerId;
        $matched = array_values(array_filter($this->reviews, function($r) use ($freelancerId) {
            return $r['reviewee_id'] === $freelancerId && $r['reviewer_id'] === 6;
        }));
        return array_slice($matched, 0, $limit);
    }

    public function insertReview(int $jobId, int $reviewerId, int $revieweeId, int $rating, ?string $comment): int {
        $this->events[] = 'insertReview:' . $jobId . ':' . $reviewerId . ':' . $revieweeId;
        if ($this->duplicateRace) {
            $e = new PDOException("Duplicate entry '2-{$reviewerId}' for key 'uq_reviews_job_reviewer'", 23000);
            $e->errorInfo = ['23000', 1062, "Duplicate entry '2-{$reviewerId}' for key 'uq_reviews_job_reviewer'"];
            throw $e;
        }
        if ($this->failInsert) {
            throw new PDOException("General SQL error", 42000);
        }
        $id = count($this->reviews) + 1;
        $entry = [
            'id' => $id,
            'job_id' => $jobId,
            'reviewer_id' => $reviewerId,
            'reviewee_id' => $revieweeId,
            'rating' => $rating,
            'comment' => $comment,
            'created_at' => '2026-11-06 10:00:00',
            'reviewer_name' => $reviewerId === 6 ? 'CafeOwner' : 'TechConsultant',
            'reviewer_role' => $reviewerId === 6 ? 'client' : 'freelancer',
            'reviewee_name' => $revieweeId === 6 ? 'CafeOwner' : 'TechConsultant',
            'reviewee_role' => $revieweeId === 6 ? 'client' : 'freelancer',
            'client_name' => 'CafeOwner',
            'job_title' => 'Cafe Ordering System development',
        ];
        $this->reviews[] = $entry;
        return $id;
    }

    public function createNotification(int $recipientId, int $jobId, string $message): void {
        $this->events[] = 'createNotification:' . $recipientId;
        $this->notifications[] = [
            'recipient_id' => $recipientId,
            'job_id' => $jobId,
            'type' => 'review_received',
            'message' => $message,
            'created_at' => '2026-11-06 10:00:00',
        ];
    }
}

$pdo = new MockReviewPDO();
$repo = new MockReviewRepository($pdo);
$pdo->repo = $repo;
$service = new ReviewService($pdo, $repo);

// -------------------------------------------------------------
// 2. Precondition & Eligibility Tests
// -------------------------------------------------------------
// Non-existent job
denied(fn() => $service->getReviewContext(999, 6, 'client'), 404, 'Context: Non-existent job rejected');
denied(fn() => $service->submitReview(999, 6, 'client', ['rating' => 5]), 404, 'Submit: Non-existent job rejected');

// Non-completed job
foreach (['draft', 'open', 'awarded', 'in_progress', 'under_review', 'disputed', 'cancelled'] as $invalidStatus) {
    $repo->job['job_status'] = $invalidStatus;
    denied(fn() => $service->getReviewContext(2, 6, 'client'), 409, "Context: Job status '$invalidStatus' rejected");
    denied(fn() => $service->submitReview(2, 6, 'client', ['rating' => 5]), 409, "Submit: Job status '$invalidStatus' rejected");
}
$repo->job['job_status'] = 'completed';

// Missing awarded bid
$repo->job['awarded_bid_id'] = null;
denied(fn() => $service->submitReview(2, 6, 'client', ['rating' => 5]), 409, 'Missing awarded_bid_id rejected');
$repo->job['awarded_bid_id'] = 1;

// Non-accepted bid
$repo->job['bid_status'] = 'pending';
denied(fn() => $service->submitReview(2, 6, 'client', ['rating' => 5]), 409, 'Non-accepted bid rejected');
$repo->job['bid_status'] = 'accepted';

// Third-party client (not job owner)
denied(fn() => $service->getReviewContext(2, 99, 'client'), 403, 'Context: Third-party client rejected');
denied(fn() => $service->submitReview(2, 99, 'client', ['rating' => 5]), 403, 'Submit: Third-party client rejected');

// Third-party freelancer (not winning bidder)
denied(fn() => $service->getReviewContext(2, 117, 'freelancer'), 403, 'Context: Third-party freelancer rejected');
denied(fn() => $service->submitReview(2, 117, 'freelancer', ['rating' => 5]), 403, 'Submit: Third-party freelancer rejected');

// Losing bidder attempting review
denied(fn() => $service->submitReview(2, 200, 'freelancer', ['rating' => 5]), 403, 'Losing bidder rejected with 403');

// Inactive / unverified client
$repo->job['client_status'] = 'pending_verification';
denied(fn() => $service->submitReview(2, 6, 'client', ['rating' => 5]), 403, 'Unverified client rejected');
$repo->job['client_status'] = 'active';

// Inactive / unverified freelancer
$repo->job['freelancer_status'] = 'suspended';
denied(fn() => $service->submitReview(2, 116, 'freelancer', ['rating' => 5]), 403, 'Suspended freelancer rejected');
$repo->job['freelancer_status'] = 'active';

// Validation error inside service
denied(fn() => $service->submitReview(2, 6, 'client', ['rating' => '']), 422, 'Missing rating rejected with 422');
denied(fn() => $service->submitReview(2, 6, 'client', ['rating' => 6]), 422, 'Rating 6 rejected with 422');

// Outer transaction collision
$pdo->beginTransaction();
denied(fn() => $service->submitReview(2, 6, 'client', ['rating' => 5]), 500, 'Active transaction call rejected');
$pdo->rollBack();

// -------------------------------------------------------------
// 3. Successful Two-Way Review Flow
// -------------------------------------------------------------
$pdo->events = [];
$repo->events = [];

// 3a. Client reviews Freelancer
$clientReview = $service->submitReview(2, 6, 'client', [
    'rating' => 5,
    'comment' => 'Outstanding work on the Cafe Ordering System! Clean code and fast delivery.',
]);

check($clientReview['review_id'] === 1, 'Client review ID is 1');
check($clientReview['rating'] === 5, 'Client review rating is 5');
check($clientReview['counterpart_name'] === 'TechConsultant', 'Counterpart name is TechConsultant');

// Verify lock events order
check(in_array('getJobContext:2:lock', $repo->events, true), 'Job context locked FOR UPDATE');
check(in_array('getReview:2:6:lock', $repo->events, true), 'Existing review checked with lock');
check(in_array('insertReview:2:6:116', $repo->events, true), 'Review inserted for reviewer 6 -> reviewee 116');
check(in_array('createNotification:116', $repo->events, true), 'Notification sent to freelancer 116');
check(in_array('tx:begin', $pdo->events, true), 'Transaction begin executed');
check(in_array('tx:commit', $pdo->events, true), 'Transaction commit executed');

// Verify stored review
check(count($repo->reviews) === 1, 'Exactly one review stored');
$r1 = $repo->reviews[0];
check($r1['job_id'] === 2, 'Stored review job_id is 2');
check($r1['reviewer_id'] === 6, 'Stored review reviewer_id is 6 (Client)');
check($r1['reviewee_id'] === 116, 'Stored review reviewee_id is 116 (Freelancer)');
check($r1['rating'] === 5, 'Stored review rating is 5');

// Verify stored notification
check(count($repo->notifications) === 1, 'Exactly one notification stored');
$n1 = $repo->notifications[0];
check($n1['recipient_id'] === 116, 'Notification recipient is freelancer 116');
check($n1['type'] === 'review_received', 'Notification type is review_received');
check(str_contains($n1['message'], 'Cafe Ordering System'), 'Notification mentions job title');
check(str_contains($n1['message'], '5/5 stars'), 'Notification mentions 5/5 stars');

// 3b. Client attempts duplicate review on same job -> 409 Conflict
denied(fn() => $service->submitReview(2, 6, 'client', ['rating' => 4]), 409, 'Duplicate client review rejected with 409');
check(count($repo->reviews) === 1, 'Review count remains 1 after rejected repeat');

// 3c. Winning Freelancer reviews Client on same job (Two-Way Independence)
$pdo->events = [];
$repo->events = [];

$freelancerReview = $service->submitReview(2, 116, 'freelancer', [
    'rating' => 5,
    'comment' => 'Great client to work with! Clear requirements and prompt escrow payment.',
]);

check($freelancerReview['review_id'] === 2, 'Freelancer review ID is 2');
check($freelancerReview['rating'] === 5, 'Freelancer review rating is 5');
check($freelancerReview['counterpart_name'] === 'CafeOwner', 'Counterpart name is CafeOwner');

check(in_array('insertReview:2:116:6', $repo->events, true), 'Review inserted for reviewer 116 -> reviewee 6');
check(in_array('createNotification:6', $repo->events, true), 'Notification sent to client 6');
check(count($repo->reviews) === 2, 'Both reviews now exist independently for Job 2');

$r2 = $repo->reviews[1];
check($r2['reviewer_id'] === 116, 'Second review reviewer is Freelancer');
check($r2['reviewee_id'] === 6, 'Second review reviewee is Client');

// 3d. Freelancer attempts duplicate review on same job -> 409 Conflict
denied(fn() => $service->submitReview(2, 116, 'freelancer', ['rating' => 5]), 409, 'Duplicate freelancer review rejected with 409');
check(count($repo->reviews) === 2, 'Review count remains 2 after rejected repeat');

// -------------------------------------------------------------
// 4. Concurrency Race Condition & Rollback
// -------------------------------------------------------------
// Simulate concurrent race where uq_reviews_job_reviewer throws 23000
$repo->duplicateRace = true;
$pdo->events = [];
// Temporary test user 10 on new job 3
$repo->job['job_id'] = 3;
denied(fn() => $service->submitReview(3, 6, 'client', ['rating' => 5]), 409, 'Concurrent duplicate key caught safely as 409');
check(in_array('tx:rollback', $pdo->events, true), 'Rollback executed on concurrency race');
$repo->duplicateRace = false;

// Simulate general database insert failure
$repo->failInsert = true;
$pdo->events = [];
denied(fn() => $service->submitReview(3, 6, 'client', ['rating' => 5]), 500, 'General database error masked as 500');
check(in_array('tx:rollback', $pdo->events, true), 'Rollback executed on insert failure');
$repo->failInsert = false;
$repo->job['job_id'] = 2;

// -------------------------------------------------------------
// 5. Reputation Aggregation Mathematics
// -------------------------------------------------------------
// Currently 1 Client-to-Freelancer review (rating 5)
$rep1 = $repo->getFreelancerReputation(116);
check($rep1['count'] === 1, 'Freelancer reputation count is 1');
check($rep1['average'] === 5.0, 'Freelancer average rating is 5.0');

// Isolation: Client reputation should NOT count Client-to-Freelancer reviews
$clientRep = $repo->getFreelancerReputation(6);
check($clientRep['count'] === 0, 'Client has 0 freelancer reviews (isolation preserved)');
check($clientRep['average'] === null, 'Client average rating is null');

// Simulate adding another review for Freelancer 116 (rating 4)
$repo->reviews[] = [
    'id' => 3,
    'job_id' => 99,
    'reviewer_id' => 6,
    'reviewee_id' => 116,
    'rating' => 4,
    'comment' => 'Second project, also good.',
    'created_at' => '2026-11-06 12:00:00',
    'client_name' => 'CafeOwner',
    'job_title' => 'Cafe Loyalty App',
];
$rep2 = $repo->getFreelancerReputation(116);
check($rep2['count'] === 2, 'Freelancer reputation count is now 2');
check($rep2['average'] === 4.5, 'Freelancer average rating is exactly 4.5 ( (5+4)/2 )');

// -------------------------------------------------------------
// 6. Controller HTTP & CSRF Verification
// -------------------------------------------------------------
$now = time();
$_SESSION = ['_initialized' => true, 'auth' => ['user_id' => 6, 'issued_at' => $now, 'last_activity' => $now]];
$authSession = new AuthSession(static fn() => $now, static fn() => true);
$currentUser = [
    'id' => 6,
    'role' => 'client',
    'display_name' => 'CafeOwner',
    'status' => 'active',
    'email_verified_at' => '2026-01-01 00:00:00',
];
$mockUserRepo = new class($currentUser) extends UserRepository {
    public function __construct(public array &$userRef) {}
    public function findAuthIdentity(int $id): array|false { return $this->userRef; }
};
$authGuard = new AuthGuard(static fn() => new AuthenticationService($mockUserRepo, new \GigHub\Services\LoginValidator()), $authSession);
$controller = new ReviewController($authGuard, static fn() => $service);

// 6a. Anonymous access -> 303 redirect to login
$_SESSION['auth']['user_id'] = null;
$anonResp = $controller->createForm(['job_id' => 2]);
check($anonResp['status'] === 303 && $anonResp['location'] === '?route=/login', 'Anonymous createForm redirects to login');
$anonPost = $controller->store(['job_id' => 2, 'csrf_token' => 'x']);
check($anonPost['status'] === 303 && $anonPost['location'] === '?route=/login', 'Anonymous store redirects to login');

// 6b. Authenticate client
$_SESSION = ['_initialized' => true, 'auth' => ['user_id' => 6, 'issued_at' => $now, 'last_activity' => $now]];

// Invalid CSRF on POST -> 403
$invalidCsrf = $controller->store(['job_id' => 2, 'csrf_token' => 'bad_csrf_token']);
check($invalidCsrf['status'] === 403, 'Invalid CSRF rejected with 403');

// Missing job ID on GET and POST -> 400
$validToken = Csrf::token('job_review');
$missingJobGet = $controller->createForm([]);
check($missingJobGet['status'] === 400, 'Missing job_id on GET returns 400');
$missingJobPost = $controller->store(['csrf_token' => $validToken]);
check($missingJobPost['status'] === 400, 'Missing job_id on POST returns 400');

// 6c. GET /jobs/review when user already reviewed -> 303 PRG redirect with info notice
$alreadyReviewedGet = $controller->createForm(['job_id' => 2]);
check($alreadyReviewedGet['status'] === 303, 'Already reviewed user is redirected');
check(str_contains($alreadyReviewedGet['location'], '?route=/client/jobs/milestones&job_id=2'), 'Redirects to client milestone workspace');

// 6d. GET /jobs/review when unreviewed (Job 4)
$repo->job['job_id'] = 4;
$unreviewedGet = $controller->createForm(['job_id' => 4]);
check($unreviewedGet['status'] === 200, 'Unreviewed user receives 200 review form');
check(str_contains($unreviewedGet['body'], 'Leave a Review for TechConsultant'), 'Form heading renders counterpart name');
check(str_contains($unreviewedGet['body'], 'name="rating"'), 'Star rating input rendered');
check(str_contains($unreviewedGet['body'], 'name="comment"'), 'Comment textarea rendered');
check(str_contains($unreviewedGet['body'], '← Back to Job Milestones'), 'Back navigation rendered');
check(str_contains($unreviewedGet['body'], 'class="gh-review-layout"'), 'Review form rendered within centered gh-review-layout container');
check(str_contains($unreviewedGet['body'], 'My Jobs'), 'Authenticated header renders My Jobs for client');
check(!str_contains($unreviewedGet['body'], 'Sign in'), 'Authenticated header does not display Sign in link');
check(str_contains($unreviewedGet['body'], 'Sign out'), 'Authenticated header renders account Sign out button');

// 6e. POST /jobs/review with validation error (missing rating) -> 422 with form re-rendered
$valErrPost = $controller->store([
    'job_id' => 4,
    'csrf_token' => $validToken,
    'comment' => 'Missing rating test',
]);
check($valErrPost['status'] === 422, 'Missing rating returns 422');
check(str_contains($valErrPost['body'], 'Please select a rating between 1 and 5 stars.'), 'Validation message rendered');
check(str_contains($valErrPost['body'], 'Missing rating test'), 'Old comment preserved in textarea');

// 6f. POST /jobs/review valid submission -> 303 PRG with success notice
$validPost = $controller->store([
    'job_id' => 4,
    'csrf_token' => $validToken,
    'rating' => 5,
    'comment' => 'Terrific job on the new project!',
]);
check($validPost['status'] === 303, 'Valid submission returns 303 PRG redirect');
check(str_contains($validPost['location'], '?route=/client/jobs/milestones&job_id=4'), 'Redirects to milestone workspace');
check(isset($_SESSION['gh_notifications']) && count($_SESSION['gh_notifications']) > 0, 'Flash notification set');
check(str_contains($_SESSION['gh_notifications'][0]['message'], 'TechConsultant has been published'), 'Success message contains counterpart name');
$_SESSION['gh_notifications'] = [];

// Reset to Job 2
$repo->job['job_id'] = 2;

// -------------------------------------------------------------
// 7. Milestone Workspace View Rendering with Reviews
// -------------------------------------------------------------
$escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$job = [
    'id' => 2,
    'client_id' => 6,
    'client_name' => 'CafeOwner',
    'title' => 'Cafe Ordering System development',
    'status' => 'completed',
    'budget' => '5000.00',
    'awarded_bid_id' => 1,
    'completed_at' => '2026-11-05 14:00:00',
];
$escrow = ['id' => 1, 'amount' => '4300.00', 'status' => 'released'];
$freelancer = ['id' => 116, 'display_name' => 'TechConsultant', 'amount' => '4300.00'];
$milestones = [
    ['id' => 1, 'title' => 'M1', 'status' => 'approved', 'position' => 1, 'description' => '', 'due_date' => '2026-12-01'],
];
$submissions = [
    [
        'id' => 5,
        'submission_type' => 'final',
        'status' => 'approved',
        'notes' => 'Done',
        'deliverable_url' => 'https://example.com',
        'submitted_at' => '2026-11-04 10:00:00',
        'reviewed_at' => '2026-11-04 11:00:00',
        'feedback' => null,
    ],
];
$isLocked = true;
$hasEscrow = true;
$isClient = true;
$notice = null;
$errors = [];
$old = [];
$reviewErrors = [];
$reviewOld = [];
$finalReviewErrors = [];
$finalReviewOld = [];
$navigationUser = ['id' => 6, 'role' => 'client', 'display_name' => 'CafeOwner'];
$logoutToken = Csrf::token('logout');
$token = Csrf::token('job_milestone');
$reviewToken = Csrf::token('milestone_review');
$finalReviewToken = Csrf::token('final_review');
$escrowReleaseToken = Csrf::token('escrow_release');

// 7a. Client completed workspace when UNREVIEWED -> renders "★ Leave a Review" button
$reviews = [];
ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$clientUnreviewedHtml = (string)ob_get_clean();

check(str_contains($clientUnreviewedHtml, '★ Leave a Review for TechConsultant'), 'Client unreviewed renders Leave a Review button');
check(str_contains($clientUnreviewedHtml, '?route=/jobs/review&amp;job_id=2'), 'Leave a Review link points to /jobs/review');

// 7b. Client completed workspace when REVIEWED -> renders submitted review card & partner feedback
$reviews = $repo->getReviewsForJob(2);
ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$clientReviewedHtml = (string)ob_get_clean();

check(!str_contains($clientReviewedHtml, '★ Leave a Review for TechConsultant'), 'Leave a Review button hidden once reviewed');
check(str_contains($clientReviewedHtml, '✓ Your Review for TechConsultant'), 'Submitted review badge rendered');
check(str_contains($clientReviewedHtml, 'Outstanding work on the Cafe Ordering System'), 'Submitted review text rendered');
check(str_contains($clientReviewedHtml, 'Feedback from TechConsultant'), 'Feedback received from freelancer rendered');

// 7c. Freelancer completed workspace when UNREVIEWED -> renders "★ Leave a Review" button
$isClient = false;
$navigationUser = ['id' => 116, 'role' => 'freelancer', 'display_name' => 'TechConsultant'];
$reviews = [];
ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$freelancerUnreviewedHtml = (string)ob_get_clean();

check(str_contains($freelancerUnreviewedHtml, '★ Leave a Review for CafeOwner'), 'Freelancer unreviewed renders Leave a Review button');
check(str_contains($freelancerUnreviewedHtml, '?route=/jobs/review&amp;job_id=2'), 'Freelancer review link points to /jobs/review');

// 7d. Freelancer completed workspace when REVIEWED -> renders submitted review card & partner feedback
$reviews = $repo->getReviewsForJob(2);
ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$freelancerReviewedHtml = (string)ob_get_clean();

check(!str_contains($freelancerReviewedHtml, '★ Leave a Review for CafeOwner'), 'Leave a Review button hidden once reviewed');
check(str_contains($freelancerReviewedHtml, '✓ Your Review for CafeOwner'), 'Freelancer submitted review badge rendered');
check(str_contains($freelancerReviewedHtml, 'Great client to work with'), 'Freelancer submitted review text rendered');
check(str_contains($freelancerReviewedHtml, 'Feedback from CafeOwner'), 'Feedback received from client rendered');

// -------------------------------------------------------------
// 8. Public Freelancer Profile View Rendering
// -------------------------------------------------------------
$owner = false;
$saved = false;

// 8a. Freelancer Profile with NO reviews -> renders empty state
$profile = [
    'id' => 116,
    'display_name' => 'TechConsultant',
    'headline' => 'Senior Full Stack PHP Engineer',
    'bio' => 'Experienced web developer.',
    'skills' => [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'MySQL']],
    'reputation' => ['count' => 0, 'average' => null],
    'reviews' => [],
];
ob_start();
require dirname(__DIR__) . '/app/Views/freelancer-profile/show.php';
$profileEmptyHtml = (string)ob_get_clean();

check(str_contains($profileEmptyHtml, '★ No reviews yet'), 'Empty reputation summary rendered');
check(str_contains($profileEmptyHtml, 'No client reviews yet. Reviews will appear here once projects are completed.'), 'Empty reviews list notice rendered');

// 8b. Freelancer Profile WITH verified reviews -> renders star score & review cards
$profile['reputation'] = ['count' => 2, 'average' => 4.5];
$profile['reviews'] = [
    [
        'id' => 1,
        'rating' => 5,
        'comment' => 'Outstanding work on the Cafe Ordering System! Clean code and fast delivery.',
        'created_at' => '2026-11-06 10:00:00',
        'client_name' => 'CafeOwner',
        'job_title' => 'Cafe Ordering System development',
    ],
    [
        'id' => 3,
        'rating' => 4,
        'comment' => 'Second project, also good.',
        'created_at' => '2026-11-06 12:00:00',
        'client_name' => 'CafeOwner',
        'job_title' => 'Cafe Loyalty App',
    ],
];
ob_start();
require dirname(__DIR__) . '/app/Views/freelancer-profile/show.php';
$profileWithReviewsHtml = (string)ob_get_clean();

check(str_contains($profileWithReviewsHtml, '4.5'), 'Average score 4.5 rendered in profile header');
check(str_contains($profileWithReviewsHtml, '2 verified reviews'), 'Verified reviews count rendered');
check(str_contains($profileWithReviewsHtml, 'Client Reviews &amp; Ratings'), 'Section heading rendered');
check(str_contains($profileWithReviewsHtml, 'Outstanding work on the Cafe Ordering System'), 'First review comment rendered');
check(str_contains($profileWithReviewsHtml, 'Second project, also good'), 'Second review comment rendered');
check(str_contains($profileWithReviewsHtml, 'Project: <strong>Cafe Ordering System development</strong>'), 'Related job title rendered');
check(str_contains($profileWithReviewsHtml, 'Client: <strong>CafeOwner</strong>'), 'Client author display name rendered');

echo "PASS: {$count} reviews offline checks; no database connections.\n";
