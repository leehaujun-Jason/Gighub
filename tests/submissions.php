<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

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
    'Repositories/SubmissionRepository',
    'Services/SubmissionException',
    'Services/SubmissionValidator',
    'Services/ReviewValidator',
    'Services/SubmissionService',
    'Controllers/SubmissionController',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Repositories\UserRepository;
use GigHub\Services\AuthenticationService;
use GigHub\Repositories\SubmissionRepository;
use GigHub\Services\SubmissionException;
use GigHub\Services\SubmissionValidator;
use GigHub\Services\ReviewValidator;
use GigHub\Services\SubmissionService;
use GigHub\Controllers\SubmissionController;

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
    } catch (SubmissionException $e) {
        check($e->status === $status, $label . ' (expected ' . $status . ', got ' . $e->status . ': ' . $e->getMessage() . ')');
    }
}

// -------------------------------------------------------------
// Mock PDO & Repository
// -------------------------------------------------------------
class MockSubmissionPDO extends PDO {
    public int $txLevel = 0;
    public array $events = [];
    public ?MockSubmissionRepository $repo = null;
    public bool $failCommit = false;

    public function __construct() {}

    public function beginTransaction(): bool {
        $this->events[] = 'tx:begin';
        ++$this->txLevel;
        $this->repo?->snapshot();
        return true;
    }

    public function commit(): bool {
        if ($this->failCommit) {
            $this->rollBack();
            throw new RuntimeException('Simulated commit failure');
        }
        $this->events[] = 'tx:commit';
        $this->txLevel = max(0, $this->txLevel - 1);
        return true;
    }

    public function rollBack(): bool {
        $this->events[] = 'tx:rollback';
        $this->txLevel = max(0, $this->txLevel - 1);
        $this->repo?->revert();
        return true;
    }

    public function inTransaction(): bool {
        return $this->txLevel > 0;
    }

    public function exec(string $statement): int|false {
        $this->events[] = 'exec:' . $statement;
        if (str_starts_with($statement, 'SAVEPOINT')) {
            $this->repo?->snapshot();
        } elseif (str_starts_with($statement, 'ROLLBACK TO SAVEPOINT')) {
            $this->repo?->revert();
        }
        return 0;
    }
}

class MockSubmissionRepository extends SubmissionRepository {
    public array $jobs = [];
    public array $milestones = [];
    public array $escrows = [];
    public array $awardedBids = [];
    public array $submissions = [];
    public array $eligibleFreelancers = [];
    public array $eligibleClients = [];
    public int $nextSubmissionId = 1;

    private array $snapshotState = [];

    public function __construct(PDO $pdo) {
        parent::__construct($pdo);
    }

    public function snapshot(): void {
        $this->snapshotState[] = [
            'milestones' => $this->milestones,
            'submissions' => $this->submissions,
        ];
    }

    public function revert(): void {
        if (!empty($this->snapshotState)) {
            $last = array_pop($this->snapshotState);
            $this->milestones = $last['milestones'];
            $this->submissions = $last['submissions'];
        }
    }

    public function job(int $jobId, bool $lock = false): array|false {
        return $this->jobs[$jobId] ?? false;
    }

    public function milestone(int $jobId, int $milestoneId, bool $lock = false): array|false {
        $m = $this->milestones[$milestoneId] ?? null;
        if ($m !== null && (int)$m['job_id'] === $jobId) {
            return $m;
        }
        return false;
    }

    public function isAwardedFreelancer(int $jobId, int $freelancerId): bool {
        return isset($this->awardedBids[$jobId]) && (int)$this->awardedBids[$jobId] === $freelancerId;
    }

    public function eligibleFreelancer(int $freelancerId, bool $lock = false): bool {
        return in_array($freelancerId, $this->eligibleFreelancers, true);
    }

    public function eligibleClient(int $clientId, bool $lock = false): bool {
        return in_array($clientId, $this->eligibleClients, true);
    }

    public function hasLockedEscrow(int $jobId): bool {
        return ($this->escrows[$jobId]['status'] ?? '') === 'locked';
    }

    public function createMilestoneSubmission(int $jobId, int $milestoneId, int $freelancerId, string $notes, ?string $deliverableUrl): int {
        $id = $this->nextSubmissionId++;
        $now = gmdate('Y-m-d H:i:s');
        $this->submissions[] = [
            'id' => $id,
            'job_id' => $jobId,
            'milestone_id' => $milestoneId,
            'submitted_by' => $freelancerId,
            'submission_type' => 'milestone',
            'notes' => $notes,
            'deliverable_url' => $deliverableUrl,
            'status' => 'submitted',
            'submitted_at' => $now,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'feedback' => null,
            'created_at' => $now,
        ];
        return $id;
    }

    public function updateMilestoneStatus(int $jobId, int $milestoneId, string $status): void {
        if (isset($this->milestones[$milestoneId]) && (int)$this->milestones[$milestoneId]['job_id'] === $jobId) {
            $this->milestones[$milestoneId]['status'] = $status;
            $this->milestones[$milestoneId]['updated_at'] = gmdate('Y-m-d H:i:s');
        }
    }

    public function submissionsForJob(int $jobId): array {
        $filtered = array_filter($this->submissions, static fn($s) => (int)$s['job_id'] === $jobId);
        usort($filtered, static fn($a, $b) => $b['id'] <=> $a['id']);
        return array_values($filtered);
    }

    public function submissionsForMilestone(int $jobId, int $milestoneId): array {
        $filtered = array_filter($this->submissions, static fn($s) => (int)$s['job_id'] === $jobId && (int)$s['milestone_id'] === $milestoneId);
        usort($filtered, static fn($a, $b) => $b['id'] <=> $a['id']);
        return array_values($filtered);
    }

    public bool $failSubUpdate = false;
    public bool $failMilestoneUpdate = false;

    public function latestSubmissionForMilestone(int $jobId, int $milestoneId, bool $lock = false): array|false {
        $subs = $this->submissionsForMilestone($jobId, $milestoneId);
        return $subs[0] ?? false;
    }

    public function submissionById(int $submissionId, bool $lock = false): array|false {
        foreach ($this->submissions as $s) {
            if ((int)$s['id'] === $submissionId) {
                return $s;
            }
        }
        return false;
    }

    public function approveSubmission(int $submissionId, int $reviewedBy, ?string $feedback): int {
        if ($this->failSubUpdate) {
            return 0;
        }
        foreach ($this->submissions as &$s) {
            if ((int)$s['id'] === $submissionId && $s['status'] === 'submitted') {
                $s['status'] = 'approved';
                $s['reviewed_by'] = $reviewedBy;
                $s['reviewed_at'] = gmdate('Y-m-d H:i:s');
                $s['feedback'] = $feedback;
                return 1;
            }
        }
        return 0;
    }

    public function requestSubmissionRevision(int $submissionId, int $reviewedBy, string $feedback): int {
        if ($this->failSubUpdate) {
            return 0;
        }
        foreach ($this->submissions as &$s) {
            if ((int)$s['id'] === $submissionId && $s['status'] === 'submitted') {
                $s['status'] = 'revisions_requested';
                $s['reviewed_by'] = $reviewedBy;
                $s['reviewed_at'] = gmdate('Y-m-d H:i:s');
                $s['feedback'] = $feedback;
                return 1;
            }
        }
        return 0;
    }

    public function approveMilestoneStatus(int $jobId, int $milestoneId): int {
        if ($this->failMilestoneUpdate) {
            return 0;
        }
        if (isset($this->milestones[$milestoneId]) && (int)$this->milestones[$milestoneId]['job_id'] === $jobId && $this->milestones[$milestoneId]['status'] === 'submitted') {
            $this->milestones[$milestoneId]['status'] = 'approved';
            $this->milestones[$milestoneId]['approved_at'] = gmdate('Y-m-d H:i:s');
            $this->milestones[$milestoneId]['updated_at'] = gmdate('Y-m-d H:i:s');
            return 1;
        }
        return 0;
    }

    public function requestMilestoneRevisionStatus(int $jobId, int $milestoneId): int {
        if ($this->failMilestoneUpdate) {
            return 0;
        }
        if (isset($this->milestones[$milestoneId]) && (int)$this->milestones[$milestoneId]['job_id'] === $jobId && $this->milestones[$milestoneId]['status'] === 'submitted') {
            $this->milestones[$milestoneId]['status'] = 'revisions_requested';
            $this->milestones[$milestoneId]['approved_at'] = null;
            $this->milestones[$milestoneId]['updated_at'] = gmdate('Y-m-d H:i:s');
            return 1;
        }
        return 0;
    }
}

// Initialize Mocks
$pdo = new MockSubmissionPDO();
$repo = new MockSubmissionRepository($pdo);
$pdo->repo = $repo;
$service = new SubmissionService($pdo, $repo);

// =========================================================================
// SECTION 1: SubmissionValidator Unit Tests
// =========================================================================

// 1. Missing notes
try {
    SubmissionValidator::validate([]);
    check(false, 'missing notes should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'missing notes returns 422');
    check(isset($e->errors['notes']), 'notes error key present');
}

// 2. Whitespace-only notes
try {
    SubmissionValidator::validate(['notes' => "   \t \n  "]);
    check(false, 'whitespace notes should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'whitespace notes returns 422');
    check(isset($e->errors['notes']), 'notes error key present');
}

// 3. Notes exceeding 5000 characters
try {
    SubmissionValidator::validate(['notes' => str_repeat('a', 5001)]);
    check(false, 'notes > 5000 chars should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'notes > 5000 chars returns 422');
    check(str_contains($e->errors['notes'], '5000'), 'error mentions 5000 limit');
}

// 4. Valid notes (min 1 char)
$val = SubmissionValidator::validate(['notes' => 'A']);
check($val['notes'] === 'A', '1 char note accepted');
check($val['deliverable_url'] === null, 'null deliverable url when omitted');

// 5. Deliverable URL invalid scheme
try {
    SubmissionValidator::validate(['notes' => 'Valid notes', 'deliverable_url' => 'javascript:alert(1)']);
    check(false, 'javascript URL should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'javascript URL returns 422');
    check(isset($e->errors['deliverable_url']), 'deliverable_url error key present');
}

// 6. Deliverable URL not a valid URL
try {
    SubmissionValidator::validate(['notes' => 'Valid notes', 'deliverable_url' => 'not-a-valid-url']);
    check(false, 'invalid URL string should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'invalid URL string returns 422');
}

// 7. Deliverable URL exceeding 2048 characters
try {
    SubmissionValidator::validate(['notes' => 'Valid notes', 'deliverable_url' => 'https://example.com/' . str_repeat('x', 2040)]);
    check(false, 'URL > 2048 chars should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'URL > 2048 chars returns 422');
}

// 8. Empty string deliverable URL normalized to null
$val = SubmissionValidator::validate(['notes' => 'Valid notes', 'deliverable_url' => '   ']);
check($val['deliverable_url'] === null, 'empty deliverable url normalized to null');

// 9. Valid HTTPS URL accepted
$val = SubmissionValidator::validate(['notes' => 'Done', 'deliverable_url' => 'https://github.com/example/fyp']);
check($val['deliverable_url'] === 'https://github.com/example/fyp', 'valid https URL accepted');

// 10. Valid HTTP URL accepted
$val = SubmissionValidator::validate(['notes' => 'Done', 'deliverable_url' => 'http://localhost/demo']);
check($val['deliverable_url'] === 'http://localhost/demo', 'valid http URL accepted');

// =========================================================================
// SECTION 2: SubmissionService Business Rules & Security Tests
// =========================================================================

// Setup Baseline Fixtures
$repo->jobs[2] = [
    'id' => 2,
    'client_id' => 6,
    'title' => 'E-Commerce Marketplace',
    'budget' => '4500.00',
    'deadline' => '2027-01-31',
    'status' => 'in_progress',
    'awarded_bid_id' => 1,
];
$repo->awardedBids[2] = 116; // Freelancer 116 is the awarded freelancer
$repo->eligibleFreelancers = [116, 200];
$repo->eligibleClients = [6, 99];
$repo->escrows[2] = ['id' => 1, 'job_id' => 2, 'amount' => '4300.00', 'status' => 'locked'];
$repo->milestones[2] = [
    'id' => 2,
    'job_id' => 2,
    'title' => 'Initial Prototype',
    'description' => 'Wireframes and database setup',
    'due_date' => '2026-12-31',
    'position' => 1,
    'status' => 'pending',
    'approved_at' => null,
    'created_at' => '2026-11-04 00:00:00',
    'updated_at' => '2026-11-04 00:00:00',
];

// 11. Ineligible Freelancer denied with 403
denied(static fn() => $service->getSubmissionContext(999, 2, 2), 403, 'ineligible freelancer denied getSubmissionContext');
denied(static fn() => $service->submitMilestoneWork(999, 2, 2, ['notes' => 'test']), 403, 'ineligible freelancer denied submitMilestoneWork');

// 12. Non-existent job denied with 404
denied(static fn() => $service->getSubmissionContext(116, 9999, 2), 404, 'non-existent job denied getSubmissionContext');
denied(static fn() => $service->submitMilestoneWork(116, 9999, 2, ['notes' => 'test']), 404, 'non-existent job denied submitMilestoneWork');

// 13. Unassigned Freelancer denied with 404
denied(static fn() => $service->getSubmissionContext(200, 2, 2), 404, 'unassigned freelancer denied getSubmissionContext');
denied(static fn() => $service->submitMilestoneWork(200, 2, 2, ['notes' => 'test']), 404, 'unassigned freelancer denied submitMilestoneWork');

// 14. Job not in_progress denied with 409
$repo->jobs[2]['status'] = 'awarded';
denied(static fn() => $service->getSubmissionContext(116, 2, 2), 409, 'unfunded awarded job denied getSubmissionContext');
denied(static fn() => $service->submitMilestoneWork(116, 2, 2, ['notes' => 'test']), 409, 'unfunded awarded job denied submitMilestoneWork');
$repo->jobs[2]['status'] = 'in_progress';

// 15. Unfunded escrow denied with 409
$repo->escrows[2]['status'] = 'refunded';
denied(static fn() => $service->getSubmissionContext(116, 2, 2), 409, 'refunded escrow denied getSubmissionContext');
denied(static fn() => $service->submitMilestoneWork(116, 2, 2, ['notes' => 'test']), 409, 'refunded escrow denied submitMilestoneWork');
$repo->escrows[2]['status'] = 'locked';

// 16. Non-existent milestone denied with 404
denied(static fn() => $service->getSubmissionContext(116, 2, 999), 404, 'non-existent milestone denied getSubmissionContext');
denied(static fn() => $service->submitMilestoneWork(116, 2, 999, ['notes' => 'test']), 404, 'non-existent milestone denied submitMilestoneWork');

// 17. Milestone belonging to another job denied with 404
$repo->milestones[50] = [
    'id' => 50,
    'job_id' => 999,
    'title' => 'Other job milestone',
    'due_date' => '2026-12-31',
    'position' => 1,
    'status' => 'pending',
    'approved_at' => null,
];
denied(static fn() => $service->getSubmissionContext(116, 2, 50), 404, 'mismatched job milestone denied');
denied(static fn() => $service->submitMilestoneWork(116, 2, 50, ['notes' => 'test']), 404, 'mismatched job milestone denied submit');

// 18. Milestone already approved denied with 409
$repo->milestones[2]['status'] = 'approved';
denied(static fn() => $service->getSubmissionContext(116, 2, 2), 409, 'approved milestone denied submission context');
denied(static fn() => $service->submitMilestoneWork(116, 2, 2, ['notes' => 'test']), 409, 'approved milestone denied submitMilestoneWork');

// 19. Milestone already submitted denied with 409 (prevents duplicate concurrent submissions)
$repo->milestones[2]['status'] = 'submitted';
denied(static fn() => $service->getSubmissionContext(116, 2, 2), 409, 'already submitted milestone denied submission context');
denied(static fn() => $service->submitMilestoneWork(116, 2, 2, ['notes' => 'test']), 409, 'already submitted milestone denied submitMilestoneWork');
$repo->milestones[2]['status'] = 'pending';

// 20. Valid getSubmissionContext returns job, milestone, and history
$ctx = $service->getSubmissionContext(116, 2, 2);
check($ctx['job']['id'] === 2, 'context contains job');
check($ctx['milestone']['id'] === 2, 'context contains milestone');
check(is_array($ctx['previous_submissions']), 'context contains previous_submissions array');

// 21. Successful submitMilestoneWork
$pdo->events = [];
$res = $service->submitMilestoneWork(116, 2, 2, [
    'notes' => 'Completed initial prototype architecture with test coverage.',
    'deliverable_url' => 'https://github.com/user/fyp-proto',
]);
check($res['submission_id'] === 1, 'submission record ID returned');
check($res['job_id'] === 2, 'job ID returned');
check($res['milestone_id'] === 2, 'milestone ID returned');
check(in_array('tx:begin', $pdo->events, true), 'transaction started');
check(in_array('tx:commit', $pdo->events, true), 'transaction committed');
check($repo->milestones[2]['status'] === 'submitted', 'milestone status transitioned to submitted');
check(count($repo->submissions) === 1, 'one submission record in repository');
$subRecord = $repo->submissions[0];
check($subRecord['job_id'] === 2, 'submission job_id matches');
check($subRecord['milestone_id'] === 2, 'submission milestone_id matches');
check($subRecord['submitted_by'] === 116, 'submission author matches freelancer ID');
check($subRecord['submission_type'] === 'milestone', 'submission_type is milestone');
check($subRecord['status'] === 'submitted', 'submission status is submitted');
check($subRecord['reviewed_by'] === null, 'reviewed_by is null');
check($subRecord['reviewed_at'] === null, 'reviewed_at is null');
check($subRecord['feedback'] === null, 'feedback is null');
check($subRecord['deliverable_url'] === 'https://github.com/user/fyp-proto', 'deliverable_url persisted');

// 22. Duplicate submission blocked once status is 'submitted'
denied(static fn() => $service->submitMilestoneWork(116, 2, 2, ['notes' => 'Duplicate attempt']), 409, 'subsequent submission blocked while submitted');

// 23. Transaction Rollback Simulation
$repo->milestones[2]['status'] = 'revisions_requested'; // Simulated revision state allowing re-submit
$pdo->failCommit = true;
$pdo->events = [];
try {
    $service->submitMilestoneWork(116, 2, 2, ['notes' => 'Attempt before fail']);
    check(false, 'should have thrown commit failure');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'Simulated commit failure'), 'commit failure caught');
    check(in_array('tx:rollback', $pdo->events, true), 'rollback event executed');
    check($repo->milestones[2]['status'] === 'revisions_requested', 'milestone status reverted on rollback');
    check(count($repo->submissions) === 1, 'failed submission not added to repo after rollback');
}
$pdo->failCommit = false;

// 24. Submissions list for Client
$clientList = $service->listSubmissionsForJob(6, 2, 'client');
check($clientList['job']['id'] === 2, 'client can list submissions for owned job');
check(count($clientList['submissions']) === 1, 'client sees submissions');

// 25. Non-owner Client denied listing submissions
denied(static fn() => $service->listSubmissionsForJob(99, 2, 'client'), 404, 'non-owner client denied listing submissions');

// 26. Submissions list for winning Freelancer
$flList = $service->listSubmissionsForJob(116, 2, 'freelancer');
check(count($flList['submissions']) === 1, 'winning freelancer sees submissions');

// 27. Unrelated Freelancer denied listing submissions
denied(static fn() => $service->listSubmissionsForJob(200, 2, 'freelancer'), 404, 'unrelated freelancer denied listing submissions');

// =========================================================================
// SECTION 3: SubmissionController HTTP & View Tests
// =========================================================================

$users = new class extends UserRepository {
    public ?array $row = [
        'id' => 116,
        'role' => 'freelancer',
        'status' => 'active',
        'email_verified_at' => '2026-10-31 00:00:00',
        'display_name' => 'Assigned Freelancer',
        'email' => 'fl116@example.com'
    ];
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false { return $this->row; }
};

$_SESSION = [];
$session = new AuthSession(null, static fn() => true);
$auth = new AuthenticationService($users);
$guard = new AuthGuard(static fn() => $auth, $session);
$controller = new SubmissionController($guard, static fn() => $service);

// 28. Anonymous User -> 303 Redirect to Login
$anonForm = $controller->createForm(['job_id' => 2, 'milestone_id' => 2]);
check($anonForm['status'] === 303 && $anonForm['location'] === '?route=/login', 'anonymous createForm redirects to login');
$anonPost = $controller->submit(['job_id' => 2, 'milestone_id' => 2]);
check($anonPost['status'] === 303 && $anonPost['location'] === '?route=/login', 'anonymous submit redirects to login');

// Authenticate Freelancer 116
$session->establish(116);

// 29. Missing / Invalid Query Parameters -> 400
$badParam = $controller->createForm(['job_id' => 'abc']);
check($badParam['status'] === 400, 'malformed params return 400');

// Reset milestone to pending for controller test
$repo->milestones[2]['status'] = 'pending';

// 30. GET createForm -> 200 OK HTML
$formResp = $controller->createForm(['job_id' => 2, 'milestone_id' => 2]);
check($formResp['status'] === 200, 'createForm returns 200');
check(str_contains($formResp['body'], 'Submit Deliverable'), 'heading rendered');
check(str_contains($formResp['body'], 'Initial Prototype'), 'milestone title rendered');
check(str_contains($formResp['body'], 'E-Commerce Marketplace'), 'job title rendered');
check(str_contains($formResp['body'], 'name="csrf_token"'), 'csrf token rendered');

// 31. Invalid CSRF on POST submit -> 403
$invalidCsrf = $controller->submit([
    'job_id' => 2,
    'milestone_id' => 2,
    'notes' => 'Some notes',
    'csrf_token' => 'invalid-token',
]);
check($invalidCsrf['status'] === 403, 'invalid CSRF rejected with 403');

// 32. Validation Failure on POST submit (empty notes) -> 303 PRG back to form
$validCsrf = Csrf::token('work_submission');
$valFail = $controller->submit([
    'job_id' => 2,
    'milestone_id' => 2,
    'notes' => '',
    'csrf_token' => $validCsrf,
]);
check($valFail['status'] === 303, 'validation failure returns 303 PRG');
check(str_contains($valFail['location'], '/freelancer/milestones/submit&job_id=2&milestone_id=2'), 'redirects back to form');
check(isset($_SESSION['submission_errors']['notes']), 'validation error preserved in session');

// 33. Successful POST submit -> 303 PRG to milestones with exact notice
Csrf::rotate('work_submission');
$newCsrf = Csrf::token('work_submission');
$validPost = $controller->submit([
    'job_id' => 2,
    'milestone_id' => 2,
    'notes' => 'Controller submitted work notes.',
    'deliverable_url' => 'https://github.com/example/controller-submission',
    'csrf_token' => $newCsrf,
]);
check($validPost['status'] === 303, 'successful submission returns 303');
check($validPost['location'] === '?route=/freelancer/jobs/milestones&job_id=2', 'redirects to freelancer milestones view');

// Check notifications flash
$notifications = Notification::consume();
check(count($notifications) >= 1, 'notification flashed in session');
$foundSuccess = false;
foreach ($notifications as $n) {
    if ($n['message'] === 'Work deliverable submitted successfully. Awaiting client review.') {
        $foundSuccess = true;
        break;
    }
}
check($foundSuccess, 'exact required success message flashed');

// 34. Role isolation: Client forbidden from submitting work
$users->row['role'] = 'client';
$clientForm = $controller->createForm(['job_id' => 2, 'milestone_id' => 2]);
check($clientForm['status'] === 403, 'client forbidden from submission createForm');
$clientSubmit = $controller->submit(['job_id' => 2, 'milestone_id' => 2, 'csrf_token' => 'x']);
check($clientSubmit['status'] === 403, 'client forbidden from submission POST');

// =========================================================================
// SECTION 6: ReviewValidator Unit Tests (Module 15B)
// =========================================================================

// 35. validateApproval: empty input returns null feedback
$appr1 = ReviewValidator::validateApproval([]);
check($appr1['feedback'] === null, 'approval with empty input returns null feedback');

// 36. validateApproval: whitespace input returns null feedback
$appr2 = ReviewValidator::validateApproval(['feedback' => "   \t\n  "]);
check($appr2['feedback'] === null, 'approval with whitespace feedback returns null');

// 37. validateApproval: valid feedback trimmed
$appr3 = ReviewValidator::validateApproval(['feedback' => '  Looks solid. Good work!  ']);
check($appr3['feedback'] === 'Looks solid. Good work!', 'approval feedback trimmed');

// 38. validateApproval: feedback > 5000 chars throws 422
try {
    ReviewValidator::validateApproval(['feedback' => str_repeat('a', 5001)]);
    check(false, 'approval feedback > 5000 should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'approval feedback > 5000 returns 422');
    check(str_contains($e->errors['feedback'], '5,000') || str_contains($e->errors['feedback'], '5000'), 'mentions 5000 limit');
}

// 39. validateRevision: missing feedback throws 422
try {
    ReviewValidator::validateRevision([]);
    check(false, 'missing revision feedback should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'missing revision feedback returns 422');
    check(isset($e->errors['feedback']), 'feedback error key present');
}

// 40. validateRevision: whitespace-only feedback throws 422
try {
    ReviewValidator::validateRevision(['feedback' => "   \n\t  "]);
    check(false, 'whitespace revision feedback should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'whitespace revision feedback returns 422');
    check(isset($e->errors['feedback']), 'feedback error key present');
}

// 41. validateRevision: feedback > 5000 chars throws 422
try {
    ReviewValidator::validateRevision(['feedback' => str_repeat('b', 5001)]);
    check(false, 'revision feedback > 5000 should fail');
} catch (SubmissionException $e) {
    check($e->status === 422, 'revision feedback > 5000 returns 422');
    check(str_contains($e->errors['feedback'], '5,000') || str_contains($e->errors['feedback'], '5000'), 'mentions 5000 limit');
}

// 42. validateRevision: valid feedback trimmed
$revValid = ReviewValidator::validateRevision(['feedback' => '  Please add unit tests and fix responsiveness.  ']);
check($revValid['feedback'] === 'Please add unit tests and fix responsiveness.', 'revision feedback trimmed and accepted');

// =========================================================================
// SECTION 7: approveMilestone Service Tests (Module 15B)
// =========================================================================

// Setup baseline state for Review tests:
// Job 2 (client 6, in_progress, escrow locked)
// Milestone 2 (status = 'submitted')
// Submission 1 (id = 1, milestone 2, submitted_by 116, status = 'submitted')
$repo->jobs[2] = ['id' => 2, 'client_id' => 6, 'status' => 'in_progress', 'title' => 'E-Commerce Marketplace'];
$repo->milestones[2] = [
    'id' => 2,
    'job_id' => 2,
    'title' => 'Initial Prototype',
    'status' => 'submitted',
    'approved_at' => null,
    'due_date' => '2026-12-31',
];
$repo->escrows[2] = ['status' => 'locked'];
$repo->eligibleClients = [6];
$repo->submissions = [
    [
        'id' => 1,
        'job_id' => 2,
        'milestone_id' => 2,
        'submitted_by' => 116,
        'submission_type' => 'milestone',
        'notes' => 'Attempt 1 notes',
        'deliverable_url' => 'https://github.com/example/repo',
        'status' => 'submitted',
        'submitted_at' => gmdate('Y-m-d H:i:s'),
        'reviewed_by' => null,
        'reviewed_at' => null,
        'feedback' => null,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ],
];
$repo->nextSubmissionId = 2;

// 43. approveMilestone: Non-owner Client -> 404
denied(
    static fn() => $service->approveMilestone(999, 2, 2, 1, ['feedback' => 'Good']),
    404,
    'approveMilestone by non-owner client fails with 404'
);

// 44. approveMilestone: Ineligible Client -> 403
$repo->eligibleClients = [];
denied(
    static fn() => $service->approveMilestone(6, 2, 2, 1, ['feedback' => 'Good']),
    403,
    'approveMilestone by suspended client fails with 403'
);
$repo->eligibleClients = [6];

// 45. approveMilestone: Job not in_progress -> 409
$repo->jobs[2]['status'] = 'completed';
denied(
    static fn() => $service->approveMilestone(6, 2, 2, 1, ['feedback' => 'Good']),
    409,
    'approveMilestone for completed job fails with 409'
);
$repo->jobs[2]['status'] = 'in_progress';

// 46. approveMilestone: Escrow not locked -> 409
$repo->escrows[2]['status'] = 'released';
denied(
    static fn() => $service->approveMilestone(6, 2, 2, 1, ['feedback' => 'Good']),
    409,
    'approveMilestone with released escrow fails with 409'
);
$repo->escrows[2]['status'] = 'locked';

// 47. approveMilestone: Milestone not found -> 404
denied(
    static fn() => $service->approveMilestone(6, 2, 999, 1, ['feedback' => 'Good']),
    404,
    'approveMilestone with missing milestone fails with 404'
);

// 48. approveMilestone: Milestone not in submitted status -> 409
$repo->milestones[2]['status'] = 'pending';
denied(
    static fn() => $service->approveMilestone(6, 2, 2, 1, ['feedback' => 'Good']),
    409,
    'approveMilestone when milestone is pending fails with 409'
);
$repo->milestones[2]['status'] = 'submitted';

// 49. approveMilestone: Submission not found -> 404
denied(
    static fn() => $service->approveMilestone(6, 2, 2, 999, ['feedback' => 'Good']),
    404,
    'approveMilestone with missing submission fails with 404'
);

// 50. approveMilestone: Stale submission status (already approved) -> 409
$repo->submissions[0]['status'] = 'approved';
denied(
    static fn() => $service->approveMilestone(6, 2, 2, 1, ['feedback' => 'Good']),
    409,
    'approveMilestone with non-submitted submission fails with 409'
);
$repo->submissions[0]['status'] = 'submitted';

// 51. approveMilestone: Stale targeting when newer submission exists -> 409
$repo->submissions[] = [
    'id' => 2,
    'job_id' => 2,
    'milestone_id' => 2,
    'submitted_by' => 116,
    'submission_type' => 'milestone',
    'notes' => 'Attempt 2 notes',
    'deliverable_url' => null,
    'status' => 'submitted',
    'submitted_at' => gmdate('Y-m-d H:i:s'),
    'reviewed_by' => null,
    'reviewed_at' => null,
    'feedback' => null,
    'created_at' => gmdate('Y-m-d H:i:s'),
];
denied(
    static fn() => $service->approveMilestone(6, 2, 2, 1, ['feedback' => 'Good']),
    409,
    'approveMilestone targeting older submission when newer exists fails with 409'
);
// Remove attempt 2 to restore single submission baseline
array_pop($repo->submissions);

// 52. approveMilestone: Guarded update failure on submission -> rollback and 409
$repo->failSubUpdate = true;
$pdo->events = [];
denied(
    static fn() => $service->approveMilestone(6, 2, 2, 1, ['feedback' => 'Good']),
    409,
    'guarded submission update failure triggers 409'
);
check(in_array('tx:rollback', $pdo->events, true), 'transaction rolled back on submission guarded update failure');
$repo->failSubUpdate = false;

// 53. approveMilestone: Guarded update failure on milestone -> rollback and 409
$repo->failMilestoneUpdate = true;
$pdo->events = [];
denied(
    static fn() => $service->approveMilestone(6, 2, 2, 1, ['feedback' => 'Good']),
    409,
    'guarded milestone update failure triggers 409'
);
check(in_array('tx:rollback', $pdo->events, true), 'transaction rolled back on milestone guarded update failure');
$repo->failMilestoneUpdate = false;

// 54. Successful approveMilestone -> updates submission & milestone, commits
$pdo->events = [];
$apprResult = $service->approveMilestone(6, 2, 2, 1, ['feedback' => 'Exceeds expectations!']);
check($apprResult['milestone_id'] === 2 && $apprResult['submission_id'] === 1, 'service returns identifiers');
check(in_array('tx:begin', $pdo->events, true), 'transaction started');
check(in_array('tx:commit', $pdo->events, true), 'transaction committed');

// Verify submission record updated
$subAfter = $repo->submissionById(1);
check($subAfter['status'] === 'approved', 'submission status is approved');
check((int)$subAfter['reviewed_by'] === 6, 'reviewed_by set to client id 6');
check($subAfter['reviewed_at'] !== null, 'reviewed_at is populated');
check($subAfter['feedback'] === 'Exceeds expectations!', 'feedback saved');

// Verify milestone record updated
$msAfter = $repo->milestone(2, 2);
check($msAfter['status'] === 'approved', 'milestone status is approved');
check($msAfter['approved_at'] !== null, 'approved_at is populated');

// =========================================================================
// SECTION 8: requestMilestoneRevision Service & Resubmission Workflow
// =========================================================================

// Reset to submitted state for revision test
$repo->milestones[2]['status'] = 'submitted';
$repo->milestones[2]['approved_at'] = null;
$repo->submissions[0]['status'] = 'submitted';
$repo->submissions[0]['reviewed_by'] = null;
$repo->submissions[0]['reviewed_at'] = null;
$repo->submissions[0]['feedback'] = null;

// 55. requestMilestoneRevision: missing feedback throws 422
denied(
    static fn() => $service->requestMilestoneRevision(6, 2, 2, 1, ['feedback' => '']),
    422,
    'requestMilestoneRevision with empty feedback fails with 422'
);

// 56. requestMilestoneRevision: non-owner client -> 404
denied(
    static fn() => $service->requestMilestoneRevision(999, 2, 2, 1, ['feedback' => 'Needs changes']),
    404,
    'requestMilestoneRevision by non-owner fails with 404'
);

// 57. requestMilestoneRevision: milestone not submitted -> 409
$repo->milestones[2]['status'] = 'pending';
denied(
    static fn() => $service->requestMilestoneRevision(6, 2, 2, 1, ['feedback' => 'Needs changes']),
    409,
    'requestMilestoneRevision on non-submitted milestone fails with 409'
);
$repo->milestones[2]['status'] = 'submitted';

// 58. requestMilestoneRevision: Guarded update failure on submission -> rollback and 409
$repo->failSubUpdate = true;
$pdo->events = [];
denied(
    static fn() => $service->requestMilestoneRevision(6, 2, 2, 1, ['feedback' => 'Needs changes']),
    409,
    'guarded update failure triggers 409'
);
check(in_array('tx:rollback', $pdo->events, true), 'transaction rolled back');
$repo->failSubUpdate = false;

// 59. Successful requestMilestoneRevision -> updates submission & milestone, commits
$pdo->events = [];
$revResult = $service->requestMilestoneRevision(6, 2, 2, 1, ['feedback' => 'Please add responsive layout for mobile.']);
check($revResult['milestone_id'] === 2 && $revResult['submission_id'] === 1, 'revision service returns identifiers');
check(in_array('tx:begin', $pdo->events, true), 'transaction started');
check(in_array('tx:commit', $pdo->events, true), 'transaction committed');

// Verify submission record: status = revisions_requested, feedback present
$subRev = $repo->submissionById(1);
check($subRev['status'] === 'revisions_requested', 'submission status is revisions_requested');
check((int)$subRev['reviewed_by'] === 6, 'reviewed_by set to 6');
check($subRev['reviewed_at'] !== null, 'reviewed_at is populated');
check($subRev['feedback'] === 'Please add responsive layout for mobile.', 'revision feedback preserved');

// Verify milestone record: status = revisions_requested, approved_at is null
$msRev = $repo->milestone(2, 2);
check($msRev['status'] === 'revisions_requested', 'milestone status is revisions_requested');
check($msRev['approved_at'] === null, 'approved_at remains null');

// 60. Resubmission Workflow: Freelancer submits Attempt #2
$repo->eligibleFreelancers = [116];
$repo->awardedBids[2] = 116;
$resubResult = $service->submitMilestoneWork(116, 2, 2, [
    'notes' => 'Attempt 2: added mobile responsive styles and breakpoints.',
    'deliverable_url' => 'https://github.com/example/repo/tree/v2',
]);
check($resubResult['submission_id'] === 2, 'attempt 2 created with ID 2');

// Verify submission history contains both attempts
$allSubs = $repo->submissionsForMilestone(2, 2);
check(count($allSubs) === 2, 'both attempts preserved in submission history');
check($allSubs[0]['id'] === 2 && $allSubs[0]['status'] === 'submitted', 'latest attempt #2 is submitted');
check($allSubs[1]['id'] === 1 && $allSubs[1]['status'] === 'revisions_requested', 'attempt #1 preserved as revisions_requested');
check($allSubs[1]['feedback'] === 'Please add responsive layout for mobile.', 'attempt #1 feedback preserved');

// Verify milestone status moved back to 'submitted'
$msAfterResub = $repo->milestone(2, 2);
check($msAfterResub['status'] === 'submitted', 'milestone status back to submitted after attempt 2');

// 61. Client approves Attempt #2
$appr2 = $service->approveMilestone(6, 2, 2, 2, ['feedback' => 'Mobile layout verified. Approved!']);
check($appr2['submission_id'] === 2, 'attempt 2 approved');

$allSubsFinal = $repo->submissionsForMilestone(2, 2);
check($allSubsFinal[0]['status'] === 'approved', 'attempt #2 is approved');
check($allSubsFinal[1]['status'] === 'revisions_requested', 'attempt #1 still revisions_requested');
$msFinal = $repo->milestone(2, 2);
check($msFinal['status'] === 'approved' && $msFinal['approved_at'] !== null, 'milestone fully approved');

// =========================================================================
// SECTION 9: Controller Review Endpoints (Module 15B)
// =========================================================================

// Reset to test controller approve & requestRevision:
$repo->milestones[2]['status'] = 'submitted';
$repo->milestones[2]['approved_at'] = null;
$repo->submissions = [
    [
        'id' => 10,
        'job_id' => 2,
        'milestone_id' => 2,
        'submitted_by' => 116,
        'submission_type' => 'milestone',
        'notes' => 'Controller test submission notes',
        'deliverable_url' => null,
        'status' => 'submitted',
        'submitted_at' => gmdate('Y-m-d H:i:s'),
        'reviewed_by' => null,
        'reviewed_at' => null,
        'feedback' => null,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ],
];

// 62. Controller: Anonymous User -> 303 Redirect to Login
$session->forget();
$anonAppr = $controller->approve(['job_id' => 2, 'milestone_id' => 2, 'submission_id' => 10]);
check($anonAppr['status'] === 303 && $anonAppr['location'] === '?route=/login', 'anonymous approve redirects to login');
$anonRev = $controller->requestRevision(['job_id' => 2, 'milestone_id' => 2, 'submission_id' => 10]);
check($anonRev['status'] === 303 && $anonRev['location'] === '?route=/login', 'anonymous requestRevision redirects to login');

// 63. Controller: Freelancer Role -> 403 Forbidden
$session->establish(116);
$users->row['id'] = 116;
$users->row['role'] = 'freelancer';
$freelancerAppr = $controller->approve(['job_id' => 2, 'milestone_id' => 2, 'submission_id' => 10, 'csrf_token' => 'x']);
check($freelancerAppr['status'] === 403, 'freelancer forbidden from approve');
$freelancerRev = $controller->requestRevision(['job_id' => 2, 'milestone_id' => 2, 'submission_id' => 10, 'csrf_token' => 'x']);
check($freelancerRev['status'] === 403, 'freelancer forbidden from requestRevision');

// Authenticate Client 6
$session->establish(6);
$users->row['id'] = 6;
$users->row['role'] = 'client';

// 64. Controller: Invalid CSRF -> 403
$badCsrfAppr = $controller->approve([
    'job_id' => 2,
    'milestone_id' => 2,
    'submission_id' => 10,
    'csrf_token' => 'invalid-token',
]);
check($badCsrfAppr['status'] === 403, 'invalid CSRF rejected with 403 on approve');

$badCsrfRev = $controller->requestRevision([
    'job_id' => 2,
    'milestone_id' => 2,
    'submission_id' => 10,
    'csrf_token' => 'invalid-token',
]);
check($badCsrfRev['status'] === 403, 'invalid CSRF rejected with 403 on requestRevision');

// 65. Controller: Malformed IDs -> 400
$validRevCsrf = Csrf::token('milestone_review');
$badIdAppr = $controller->approve([
    'job_id' => 'abc',
    'milestone_id' => 2,
    'submission_id' => 10,
    'csrf_token' => $validRevCsrf,
]);
check($badIdAppr['status'] === 400, 'malformed job_id returns 400 on approve');

$badIdRev = $controller->requestRevision([
    'job_id' => 2,
    'milestone_id' => -1,
    'submission_id' => 10,
    'csrf_token' => $validRevCsrf,
]);
check($badIdRev['status'] === 400, 'malformed milestone_id returns 400 on requestRevision');

// 66. Controller: Validation failure on requestRevision (empty feedback) -> 303 PRG with session error
$valFailRev = $controller->requestRevision([
    'job_id' => 2,
    'milestone_id' => 2,
    'submission_id' => 10,
    'feedback' => '   ',
    'csrf_token' => $validRevCsrf,
]);
check($valFailRev['status'] === 303, 'validation failure returns 303 PRG');
check($valFailRev['location'] === '?route=/client/jobs/milestones&job_id=2', 'redirects back to milestones view');
check(isset($_SESSION['review_errors']['feedback']), 'review error preserved in session');
check(isset($_SESSION['review_old']['feedback']), 'review old input preserved in session');

// 67. Controller: Successful requestRevision -> 303 PRG with exact notification
Csrf::rotate('milestone_review');
$newCsrfRev = Csrf::token('milestone_review');
$validRevPost = $controller->requestRevision([
    'job_id' => 2,
    'milestone_id' => 2,
    'submission_id' => 10,
    'feedback' => 'Controller revision instructions: add search filter.',
    'csrf_token' => $newCsrfRev,
]);
check($validRevPost['status'] === 303, 'successful revision request returns 303 PRG');
check($validRevPost['location'] === '?route=/client/jobs/milestones&job_id=2', 'redirects to milestones view');

$revNotes = Notification::consume();
$foundRevMsg = false;
foreach ($revNotes as $n) {
    if ($n['message'] === 'Revision requested successfully. The freelancer can view your feedback.') {
        $foundRevMsg = true;
        break;
    }
}
check($foundRevMsg, 'exact required revision success message flashed');

// Reset to submitted for approve controller test
$repo->milestones[2]['status'] = 'submitted';
$repo->submissions[0]['status'] = 'submitted';

// 68. Controller: Successful approve -> 303 PRG with exact notification
Csrf::rotate('milestone_review');
$newCsrfAppr = Csrf::token('milestone_review');
$validApprPost = $controller->approve([
    'job_id' => 2,
    'milestone_id' => 2,
    'submission_id' => 10,
    'feedback' => 'Approved via controller.',
    'csrf_token' => $newCsrfAppr,
]);
check($validApprPost['status'] === 303, 'successful approval returns 303 PRG');
check($validApprPost['location'] === '?route=/client/jobs/milestones&job_id=2', 'redirects to milestones view');

$apprNotes = Notification::consume();
$foundApprMsg = false;
foreach ($apprNotes as $n) {
    if ($n['message'] === 'Milestone approved successfully. Checkpoint marked as completed.') {
        $foundApprMsg = true;
        break;
    }
}
check($foundApprMsg, 'exact required approval success message flashed');

echo "PASS: $count submission offline checks; no database connections.\n";
