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
            'jobs' => $this->jobs,
            'milestones' => $this->milestones,
            'submissions' => $this->submissions,
        ];
    }

    public function revert(): void {
        if (!empty($this->snapshotState)) {
            $last = array_pop($this->snapshotState);
            $this->jobs = $last['jobs'];
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

    public function escrow(int $jobId): array|false {
        return $this->escrows[$jobId] ?? false;
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

    public function createFinalSubmission(int $jobId, int $freelancerId, string $notes, ?string $deliverableUrl): int {
        $id = $this->nextSubmissionId++;
        $now = gmdate('Y-m-d H:i:s');
        $this->submissions[] = [
            'id' => $id,
            'job_id' => $jobId,
            'milestone_id' => null,
            'submitted_by' => $freelancerId,
            'submission_type' => 'final',
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

    public bool $failJobUpdate = false;
    public function updateJobStatus(int $jobId, string $status, ?string $expectedCurrentStatus = null): int {
        if ($this->failJobUpdate) {
            return 0;
        }
        if (isset($this->jobs[$jobId])) {
            if ($expectedCurrentStatus !== null && ($this->jobs[$jobId]['status'] ?? '') !== $expectedCurrentStatus) {
                return 0;
            }
            $this->jobs[$jobId]['status'] = $status;
            $this->jobs[$jobId]['updated_at'] = gmdate('Y-m-d H:i:s');
            return 1;
        }
        return 0;
    }

    public function withLinks(array $attempts, int $jobId): array {
        return $attempts;
    }

    public function hasUnapprovedPredecessor(int $jobId, int $position, bool $lock = false): bool {
        foreach ($this->milestonesForJob($jobId) as $milestone) {
            if ((int)$milestone['position'] < $position && $milestone['status'] !== 'approved') {
                return true;
            }
        }
        return false;
    }

    public function allMilestonesApproved(int $jobId, bool $lock = false): bool {
        $ms = array_filter($this->milestones, static fn($m) => (int)$m['job_id'] === $jobId);
        if (empty($ms)) {
            return false;
        }
        foreach ($ms as $m) {
            if (($m['status'] ?? '') !== 'approved') {
                return false;
            }
        }
        return true;
    }

    public function milestonesForJob(int $jobId): array {
        $filtered = array_filter($this->milestones, static fn($m) => (int)$m['job_id'] === $jobId);
        usort($filtered, static fn($a, $b) => $a['position'] <=> $b['position']);
        return array_values($filtered);
    }

    public function finalSubmissionsForJob(int $jobId): array {
        $filtered = array_filter($this->submissions, static fn($s) => (int)$s['job_id'] === $jobId && ($s['submission_type'] ?? '') === 'final');
        usort($filtered, static fn($a, $b) => $b['id'] <=> $a['id']);
        return array_values($filtered);
    }

    public function latestFinalSubmissionForJob(int $jobId, bool $lock = false): array|false {
        $subs = $this->finalSubmissionsForJob($jobId);
        return $subs[0] ?? false;
    }

    public function hasApprovedFinalSubmission(int $jobId, bool $lock = false): bool {
        foreach ($this->submissions as $s) {
            if ((int)$s['job_id'] === $jobId && ($s['submission_type'] ?? '') === 'final' && ($s['status'] ?? '') === 'approved') {
                return true;
            }
        }
        return false;
    }

    public function hasPendingFinalSubmission(int $jobId, bool $lock = false): bool {
        foreach ($this->submissions as $s) {
            if ((int)$s['job_id'] === $jobId && ($s['submission_type'] ?? '') === 'final' && ($s['status'] ?? '') === 'submitted') {
                return true;
            }
        }
        return false;
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

// =========================================================================
// SECTION 6: Module 15C Final Deliverable Submission Service Tests
// =========================================================================
$repo->jobs[10] = [
    'id' => 10,
    'client_id' => 6,
    'awarded_bid_id' => 20,
    'title' => 'Cafe Ordering System development',
    'budget' => '5000.00',
    'deadline' => '2027-01-31',
    'status' => 'in_progress',
];
$repo->awardedBids[10] = 116;
$repo->escrows[10] = ['id' => 5, 'job_id' => 10, 'amount' => '5000.00', 'status' => 'locked'];
$repo->eligibleFreelancers = [116];
$repo->eligibleClients = [6];
$repo->milestones[101] = [
    'id' => 101,
    'job_id' => 10,
    'title' => 'Milestone 1',
    'position' => 1,
    'status' => 'approved',
    'approved_at' => '2026-11-04 12:00:00',
];
$repo->milestones[102] = [
    'id' => 102,
    'job_id' => 10,
    'title' => 'Milestone 2',
    'position' => 2,
    'status' => 'approved',
    'approved_at' => '2026-11-04 13:00:00',
];

// 69. getFinalSubmissionContext: Rejects non-eligible freelancer (403)
denied(static fn() => $service->getFinalSubmissionContext(999, 10), 403, 'non-eligible freelancer rejected from final submission context');

// 70. getFinalSubmissionContext: Rejects non-existent job (404)
denied(static fn() => $service->getFinalSubmissionContext(116, 999), 404, 'non-existent job rejected');

// 71. getFinalSubmissionContext: Rejects non-awarded freelancer (404)
$repo->jobs[11] = ['id' => 11, 'client_id' => 6, 'status' => 'in_progress', 'awarded_bid_id' => 21];
$repo->awardedBids[11] = 999;
denied(static fn() => $service->getFinalSubmissionContext(116, 11), 404, 'non-awarded freelancer rejected');

// 72. getFinalSubmissionContext: Rejects job not in_progress (409)
$repo->jobs[10]['status'] = 'completed';
denied(static fn() => $service->getFinalSubmissionContext(116, 10), 409, 'completed job rejected');
$repo->jobs[10]['status'] = 'in_progress';

// 73. getFinalSubmissionContext: Rejects unlocked escrow (409)
$repo->escrows[10]['status'] = 'released';
denied(static fn() => $service->getFinalSubmissionContext(116, 10), 409, 'unlocked escrow rejected');
$repo->escrows[10]['status'] = 'locked';

// 74. getFinalSubmissionContext: Rejects if zero milestones exist (409)
$savedMilestones = $repo->milestones;
$repo->milestones = [];
denied(static fn() => $service->getFinalSubmissionContext(116, 10), 409, 'zero milestones rejected');
$repo->milestones = $savedMilestones;

// 75. getFinalSubmissionContext: Rejects if any milestone is not approved (409)
$repo->milestones[102]['status'] = 'submitted';
denied(static fn() => $service->getFinalSubmissionContext(116, 10), 409, 'unapproved milestone rejected');
$repo->milestones[102]['status'] = 'approved';

// 76. getFinalSubmissionContext: Success returns context
$ctx = $service->getFinalSubmissionContext(116, 10);
check($ctx['job']['id'] === 10, 'final submission context returns job');
check(count($ctx['milestones']) === 2, 'returns all 2 milestones');

// 77. submitFinalWork: Validation error on empty notes (422)
denied(static fn() => $service->submitFinalWork(116, 10, ['notes' => '   ']), 422, 'empty notes rejected');

// 78. submitFinalWork: Validation error on invalid URL (422)
denied(static fn() => $service->submitFinalWork(116, 10, ['notes' => 'Valid notes', 'deliverable_url' => 'ftp://bad.com']), 422, 'invalid URL scheme rejected');

// 79. submitFinalWork: Successful final submission
$res = $service->submitFinalWork(116, 10, [
    'notes' => 'Completed all deliverables for final review.',
    'deliverable_url' => 'https://github.com/example/cafe-ordering',
]);
check(is_int($res['submission_id']), 'final submission created with integer ID');
check($repo->jobs[10]['status'] === 'under_review', 'job status transitioned to under_review');
$finalSub = $repo->submissionById($res['submission_id']);
check($finalSub !== false, 'final submission record found in repository');
check($finalSub['submission_type'] === 'final', 'submission_type is final');
check($finalSub['milestone_id'] === null, 'milestone_id is null for final submission');
check($finalSub['status'] === 'submitted', 'status is submitted');
check($finalSub['deliverable_url'] === 'https://github.com/example/cafe-ordering', 'deliverable URL stored');

// 80. submitFinalWork: Duplicate final submission while pending rejected (409)
denied(static fn() => $service->submitFinalWork(116, 10, ['notes' => 'Duplicate attempt']), 409, 'duplicate pending final submission rejected');

// 81. submitFinalWork: Transaction rollback on failure (e.g. job update failure)
$repo->jobs[12] = ['id' => 12, 'client_id' => 6, 'awarded_bid_id' => 22, 'status' => 'in_progress', 'budget' => '3000.00'];
$repo->awardedBids[12] = 116;
$repo->escrows[12] = ['id' => 6, 'job_id' => 12, 'status' => 'locked'];
$repo->milestones[103] = ['id' => 103, 'job_id' => 12, 'position' => 1, 'status' => 'approved', 'approved_at' => '2026-11-04 12:00:00'];
$repo->failJobUpdate = true;
$initialSubCount = count($repo->submissions);
denied(static fn() => $service->submitFinalWork(116, 12, ['notes' => 'Will fail job update']), 409, 'job update failure raises 409');
check(count($repo->submissions) === $initialSubCount, 'savepoint rollback leaves zero orphaned submissions on failure');
$repo->failJobUpdate = false;

// =========================================================================
// SECTION 7: Module 15C Final Deliverable Client Review Service Tests
// =========================================================================
$finalSubId = $res['submission_id'];

// 82. approveFinalWork: Rejects non-owning client (404)
denied(static fn() => $service->approveFinalWork(999, 10, $finalSubId, ['feedback' => 'Nice']), 404, 'non-owning client rejected');

// 83. approveFinalWork: Rejects if job is not under_review (409)
$repo->jobs[10]['status'] = 'in_progress';
denied(static fn() => $service->approveFinalWork(6, 10, $finalSubId, ['feedback' => 'Nice']), 409, 'job not under_review rejected');
$repo->jobs[10]['status'] = 'under_review';

// 84. approveFinalWork: Rejects stale review request (submission not submitted) (409)
$repo->submissions[count($repo->submissions) - 1]['status'] = 'approved';
denied(static fn() => $service->approveFinalWork(6, 10, $finalSubId, ['feedback' => 'Nice']), 409, 'already approved submission rejected');
$repo->submissions[count($repo->submissions) - 1]['status'] = 'submitted';

// 85. approveFinalWork: Rejects non-existent submission ID (404)
denied(static fn() => $service->approveFinalWork(6, 10, 9999, ['feedback' => 'Nice']), 404, 'non-existent submission ID rejected');

// 86. requestFinalRevision: Rejects empty feedback (422)
denied(static fn() => $service->requestFinalRevision(6, 10, $finalSubId, ['feedback' => '  ']), 422, 'empty revision feedback rejected');

// 87. requestFinalRevision: Successful revision request
$revRes = $service->requestFinalRevision(6, 10, $finalSubId, ['feedback' => 'Please add deployment documentation to README.']);
check($revRes['submission_id'] === $finalSubId, 'revision requested on target submission');
$revSub = $repo->submissionById($finalSubId);
check($revSub['status'] === 'revisions_requested', 'submission status is revisions_requested');
check($revSub['reviewed_by'] === 6, 'reviewed_by recorded');
check($revSub['feedback'] === 'Please add deployment documentation to README.', 'feedback stored');
check($repo->jobs[10]['status'] === 'in_progress', 'job status reverted from under_review to in_progress');
check($repo->milestones[101]['status'] === 'approved', 'milestone 1 status remains approved');
check($repo->milestones[102]['status'] === 'approved', 'milestone 2 status remains approved');

// 88. submitFinalWork: Freelancer resubmission (Attempt #2) after revisions requested
$res2 = $service->submitFinalWork(116, 10, [
    'notes' => 'Attempt 2: Added deployment documentation as requested.',
    'deliverable_url' => 'https://github.com/example/cafe-ordering/tree/v2',
]);
check($res2['submission_id'] > $finalSubId, 'attempt 2 has distinct incremented ID');
check($repo->jobs[10]['status'] === 'under_review', 'job status moved back to under_review');
$attempt1 = $repo->submissionById($finalSubId);
check($attempt1['status'] === 'revisions_requested', 'attempt 1 preserved as revisions_requested');

// 89. approveFinalWork: Successful approval on Attempt #2
$apprRes = $service->approveFinalWork(6, 10, $res2['submission_id'], [
    'feedback' => 'Documentation verified. Excellent work!',
]);
check($apprRes['submission_id'] === $res2['submission_id'], 'attempt 2 approved');
$attempt2 = $repo->submissionById($res2['submission_id']);
check($attempt2['status'] === 'approved', 'attempt 2 status is approved');
check($attempt2['feedback'] === 'Documentation verified. Excellent work!', 'approval note stored');
check($repo->jobs[10]['status'] === 'under_review', 'job status remains under_review awaiting Module 16 settlement');
check($repo->escrows[10]['status'] === 'locked', 'escrow remains locked');

// 90. Final Approval Immutability: Cannot approve again (409)
denied(static fn() => $service->approveFinalWork(6, 10, $res2['submission_id'], ['feedback' => 'Repeat']), 409, 're-approval rejected');

// 91. Final Approval Immutability: Cannot request revisions after approval (409)
denied(static fn() => $service->requestFinalRevision(6, 10, $res2['submission_id'], ['feedback' => 'Too late']), 409, 'revision request on approved final work rejected');

// 92. Final Approval Immutability: Cannot submit further attempts after approval (409)
$repo->jobs[10]['status'] = 'in_progress'; // even if job was forced
denied(static fn() => $service->submitFinalWork(116, 10, ['notes' => 'Post-approval attempt']), 409, 'resubmission after approved final work rejected');
$repo->jobs[10]['status'] = 'under_review';

// =========================================================================
// SECTION 8: Module 15C Final Deliverable Controller Tests
// =========================================================================
// 93. Controller: finalSubmitForm unauthenticated -> redirects to login
$session->forget();
$formUnauth = $controller->finalSubmitForm(['job_id' => 10]);
check($formUnauth['status'] === 303, 'unauthenticated finalSubmitForm returns 303');
check(str_contains($formUnauth['location'], 'route=/login'), 'redirects to login');

// 94. Controller: finalSubmitForm as client -> 403
$session->establish(6);
$users->row['id'] = 6;
$users->row['role'] = 'client';
$formClient = $controller->finalSubmitForm(['job_id' => 10]);
check($formClient['status'] === 403, 'client accessing finalSubmitForm receives 403');

// 95. Controller: finalSubmitForm missing job_id -> 400
$session->establish(116);
$users->row['id'] = 116;
$users->row['role'] = 'freelancer';
$formBadId = $controller->finalSubmitForm([]);
check($formBadId['status'] === 400, 'missing job_id returns 400');

// Setup clean job 20 for controller submission & review testing
$repo->jobs[20] = [
    'id' => 20,
    'client_id' => 6,
    'awarded_bid_id' => 30,
    'title' => 'Controller Test Job',
    'budget' => '4000.00',
    'deadline' => '2027-02-28',
    'status' => 'in_progress',
];
$repo->awardedBids[20] = 116;
$repo->escrows[20] = ['id' => 7, 'job_id' => 20, 'amount' => '4000.00', 'status' => 'locked'];
$repo->milestones[201] = [
    'id' => 201,
    'job_id' => 20,
    'title' => 'Core Backend Services',
    'position' => 1,
    'status' => 'approved',
    'approved_at' => '2026-11-04 12:00:00',
    'due_date' => '2027-02-15',
    'created_at' => '2026-11-04 10:00:00',
    'description' => 'Core backend services scope',
];

// 96. Controller: finalSubmitForm eligible freelancer -> 200 HTML
$formOk = $controller->finalSubmitForm(['job_id' => 20]);
check($formOk['status'] === 200, 'eligible freelancer receives 200 on finalSubmitForm');
check(str_contains($formOk['body'], 'Submit Final Deliverable'), 'HTML contains Submit Final Deliverable heading');

// 97. Controller: submitFinal invalid CSRF -> 403
$subBadCsrf = $controller->submitFinal([
    'job_id' => 20,
    'notes' => 'Deliverable text',
    'csrf_token' => 'invalid-token',
]);
check($subBadCsrf['status'] === 403, 'submitFinal with invalid CSRF returns 403');

// 98. Controller: submitFinal validation error -> 303 PRG to final-submit form with errors
$csrfFinal = Csrf::token('final_submission');
$subValFail = $controller->submitFinal([
    'job_id' => 20,
    'notes' => '   ',
    'csrf_token' => $csrfFinal,
]);
check($subValFail['status'] === 303, 'validation error returns 303 PRG');
check($subValFail['location'] === '?route=/freelancer/jobs/final-submit&job_id=20', 'redirects back to final-submit form');
check(!empty($_SESSION['submission_errors']), 'session errors stored');

// 99. Controller: submitFinal successful -> 303 PRG with exact success notification
$csrfFinal2 = Csrf::token('final_submission');
$subOk = $controller->submitFinal([
    'job_id' => 20,
    'notes' => 'Final completed deliverable from controller test.',
    'deliverable_url' => 'https://github.com/example/test-final',
    'csrf_token' => $csrfFinal2,
]);
check($subOk['status'] === 303, 'successful submission returns 303 PRG');
check($subOk['location'] === '?route=/freelancer/jobs/milestones&job_id=20', 'redirects to milestones workspace');
$notifs = Notification::consume();
$hasSubMsg = false;
foreach ($notifs as $n) {
    if ($n['message'] === 'Final work deliverable submitted successfully. Awaiting client review.') {
        $hasSubMsg = true;
        break;
    }
}
check($hasSubMsg, 'exact required final submission success message flashed');
check($repo->jobs[20]['status'] === 'under_review', 'job status is under_review');

$createdSub = $repo->latestFinalSubmissionForJob(20);
check($createdSub !== false, 'final submission found');
$cSubId = (int)$createdSub['id'];

// Switch session to Client 6 for review tests
$session->establish(6);
$users->row['id'] = 6;
$users->row['role'] = 'client';

// 100. Controller: approveFinal invalid CSRF -> 403
$apprBadCsrf = $controller->approveFinal([
    'job_id' => 20,
    'submission_id' => $cSubId,
    'csrf_token' => 'invalid-token',
]);
check($apprBadCsrf['status'] === 403, 'approveFinal with invalid CSRF returns 403');

// 101. Controller: requestFinalRevision missing feedback -> 303 with session errors
$csrfReview1 = Csrf::token('final_review');
$revValFail = $controller->requestFinalRevision([
    'job_id' => 20,
    'submission_id' => $cSubId,
    'feedback' => '   ',
    'csrf_token' => $csrfReview1,
]);
check($revValFail['status'] === 303, 'empty feedback returns 303');
check(!empty($_SESSION['final_review_errors']), 'final review session errors stored');

// 102. Controller: requestFinalRevision successful -> 303 PRG with exact notification
$csrfReview2 = Csrf::token('final_review');
$revOk = $controller->requestFinalRevision([
    'job_id' => 20,
    'submission_id' => $cSubId,
    'feedback' => 'Controller revision request feedback.',
    'csrf_token' => $csrfReview2,
]);
check($revOk['status'] === 303, 'successful revision request returns 303');
check($revOk['location'] === '?route=/client/jobs/milestones&job_id=20', 'redirects to client milestones view');
$notifs2 = Notification::consume();
$hasRevMsg = false;
foreach ($notifs2 as $n) {
    if ($n['message'] === 'Revision requested on final deliverable. The freelancer can view your feedback.') {
        $hasRevMsg = true;
        break;
    }
}
check($hasRevMsg, 'exact required final revision success message flashed');
check($repo->jobs[20]['status'] === 'in_progress', 'job reverted to in_progress');

// Resubmit as Freelancer
$session->establish(116);
$users->row['id'] = 116;
$users->row['role'] = 'freelancer';
$csrfFinal3 = Csrf::token('final_submission');
$subOk2 = $controller->submitFinal([
    'job_id' => 20,
    'notes' => 'Attempt 2 from controller.',
    'csrf_token' => $csrfFinal3,
]);
check($subOk2['status'] === 303, 'attempt 2 submitted');
$createdSub2 = $repo->latestFinalSubmissionForJob(20);
$cSubId2 = (int)$createdSub2['id'];

// Switch to Client 6 to test approveFinal
$session->establish(6);
$users->row['id'] = 6;
$users->row['role'] = 'client';
$csrfReview3 = Csrf::token('final_review');
$apprOk = $controller->approveFinal([
    'job_id' => 20,
    'submission_id' => $cSubId2,
    'feedback' => 'Looks great from controller test!',
    'csrf_token' => $csrfReview3,
]);
check($apprOk['status'] === 303, 'successful approval returns 303');
check($apprOk['location'] === '?route=/client/jobs/milestones&job_id=20', 'redirects to client milestones view');
$notifs3 = Notification::consume();
$hasApprMsg = false;
foreach ($notifs3 as $n) {
    if ($n['message'] === 'Final deliverable approved successfully. All project requirements completed and awaiting settlement.') {
        $hasApprMsg = true;
        break;
    }
}
check($hasApprMsg, 'exact required final approval success message flashed');
check($repo->jobs[20]['status'] === 'under_review', 'job remains under_review');
check($repo->escrows[20]['status'] === 'locked', 'escrow remains locked');

// =========================================================================
// SECTION 9: Module 15C Strict PHP View Rendering Tests
// =========================================================================
// 103. Render freelancer/submissions/final.php without warnings
$job = $repo->jobs[20];
$milestones = $repo->milestonesForJob(20);
$previousSubmissions = $repo->finalSubmissionsForJob(20);
$latestSubmission = $repo->latestFinalSubmissionForJob(20);
$token = Csrf::token('final_submission');
$logoutToken = Csrf::token('logout');
$navigationUser = ['id' => 116, 'role' => 'freelancer', 'email' => 'freelancer1@example.com', 'display_name' => 'Assigned Freelancer'];
$escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$errors = [];
$old = [];

ob_start();
require dirname(__DIR__) . '/app/Views/freelancer/submissions/final.php';
$finalViewHtml = ob_get_clean();
check(str_contains($finalViewHtml, 'Submit Final Deliverable'), 'freelancer final view rendered successfully');
check(str_contains($finalViewHtml, 'Completed Milestone Checkpoints'), 'freelancer final view shows milestones checklist');
check(str_contains($finalViewHtml, 'Final Submission History'), 'freelancer final view shows attempt history');

// 104. Render client/milestones/index.php in Client Mode with approved final deliverable
$submissions = $repo->submissionsForJob(20);
$isLocked = true;
$hasEscrow = true;
$isClient = true;
$reviewToken = Csrf::token('milestone_review');
$finalReviewToken = Csrf::token('final_review');
$notice = null;
$reviewErrors = [];
$reviewOld = [];
$finalReviewErrors = [];
$finalReviewOld = [];
$navigationUser = ['id' => 6, 'role' => 'client', 'email' => 'client1@example.com', 'display_name' => 'Job Client'];

ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$clientIndexHtml = ob_get_clean();
check(str_contains($clientIndexHtml, 'Final Project Deliverable'), 'client index view renders Final Project Deliverable section');
check(str_contains($clientIndexHtml, 'Final Project Deliverable Approved'), 'client index view shows approved final deliverable status');
check(str_contains($clientIndexHtml, 'ready for final escrow settlement'), 'client index view shows ready for settlement');

$clientFinalPos = strpos($clientIndexHtml, 'Final Project Deliverable');
$clientDefinedPos = strpos($clientIndexHtml, 'Defined Milestones');
check($clientFinalPos !== false && $clientDefinedPos !== false && $clientFinalPos < $clientDefinedPos, 'client view renders Final Project Deliverable panel BEFORE Defined Milestones');

// 105. Render client/milestones/index.php in Freelancer Mode with approved final deliverable
$isClient = false;
$navigationUser = ['id' => 116, 'role' => 'freelancer', 'email' => 'freelancer1@example.com', 'display_name' => 'Assigned Freelancer'];

ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$freelancerIndexHtml = ob_get_clean();
check(str_contains($freelancerIndexHtml, 'Final Work Deliverable Approved by Client!'), 'freelancer index view shows final approval banner');
check(str_contains($freelancerIndexHtml, 'awaiting financial escrow settlement'), 'freelancer index view shows awaiting settlement banner');

$freelancerFinalPos = strpos($freelancerIndexHtml, 'Final Project Deliverable');
$freelancerDefinedPos = strpos($freelancerIndexHtml, 'Defined Milestones');
check($freelancerFinalPos !== false && $freelancerDefinedPos !== false && $freelancerFinalPos < $freelancerDefinedPos, 'freelancer view renders Final Project Deliverable panel BEFORE Defined Milestones');

// 106. Regression test: MilestoneRepository::job queries budget column
$repoContent = file_get_contents(dirname(__DIR__) . '/app/Repositories/MilestoneRepository.php');
check(str_contains($repoContent, 'SELECT id, client_id, title, budget, status, deadline, awarded_bid_id FROM jobs'), 'MilestoneRepository::job queries budget column');

// 107. Regression test: client milestones view handles missing budget defensively without ErrorException
$jobWithoutBudget = $repo->jobs[20];
unset($jobWithoutBudget['budget']);
$job = $jobWithoutBudget;
$isClient = true;
ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$defensiveHtml = ob_get_clean();
check(str_contains($defensiveHtml, 'RM 0.00'), 'view handles missing budget key defensively with RM 0.00');

// 108. Regression test: Sequential chronological attempt numbering in submission-history partial
$milestoneSubs = [
    [
        'id' => 5,
        'submission_type' => 'final',
        'status' => 'approved',
        'submitted_at' => '2026-11-05 02:47:19',
        'notes' => 'Attempt 2 notes',
        'feedback' => 'Approved note',
        'reviewed_at' => '2026-11-05 02:50:25',
    ],
    [
        'id' => 4,
        'submission_type' => 'final',
        'status' => 'revisions_requested',
        'submitted_at' => '2026-11-05 02:45:00',
        'notes' => 'Attempt 1 notes',
        'feedback' => 'Needs changes',
        'reviewed_at' => '2026-11-05 02:46:00',
    ],
];
ob_start();
require dirname(__DIR__) . '/app/Views/partials/submission-history.php';
$subHistoryHtml = ob_get_clean();
check(str_contains($subHistoryHtml, 'Attempt #2'), 'submission history renders sequential Attempt #2');
check(str_contains($subHistoryHtml, 'Attempt #1'), 'submission history renders sequential Attempt #1');
$att2Pos = strpos($subHistoryHtml, 'Attempt #2');
$att1Pos = strpos($subHistoryHtml, 'Attempt #1');
check($att2Pos < $att1Pos, 'submission history lists Attempt #2 before Attempt #1 (newest first)');
check(!str_contains($subHistoryHtml, 'Attempt #4') && !str_contains($subHistoryHtml, 'Attempt #5'), 'submission history does not display raw auto-increment PKs as attempt numbers');

// 109. Regression test: Client job bids navigation link and updated workflow wording
$bids = [];
$page = 1;
$hasNext = false;
$awardNotice = false;
$job = ['id' => 2, 'title' => 'Test Job', 'status' => 'under_review', 'awarded_bid_id' => 10, 'awarded_at' => '2026-11-05 01:00:00'];
ob_start();
require dirname(__DIR__) . '/app/Views/bids/client.php';
$bidsHtml = ob_get_clean();
check(str_contains($bidsHtml, 'View Job Milestones'), 'bids view renders View Job Milestones link for under_review job');
check(!str_contains($bidsHtml, 'Escrow and payments are not available yet'), 'bids view does not contain outdated escrow unavailable text');

// 110. Regression test: Authoritative escrow amount displayed when job budget differs
$jobWithDiffBudget = [
    'id' => 99,
    'client_id' => 6,
    'awarded_bid_id' => 1,
    'title' => 'Budget Difference Job',
    'budget' => '5000.00', // Original advertised budget
    'deadline' => '2027-02-28',
    'status' => 'under_review',
];
$escrowDiff = [
    'id' => 1,
    'job_id' => 99,
    'bid_id' => 1,
    'amount' => '4300.00', // Actual accepted bid / locked escrow amount
    'status' => 'locked',
];
$milestones = [
    ['id' => 901, 'job_id' => 99, 'title' => 'M1', 'due_date' => '2027-02-28', 'position' => 1, 'status' => 'approved', 'approved_at' => '2026-11-04 12:00:00', 'created_at' => '2026-11-04 10:00:00']
];
$submissions = [
    [
        'id' => 1,
        'job_id' => 99,
        'milestone_id' => null,
        'submitted_by' => 116,
        'submission_type' => 'final',
        'notes' => 'Final submission',
        'deliverable_url' => 'https://example.com/repo',
        'status' => 'approved',
        'submitted_at' => '2026-11-05 02:47:19',
        'reviewed_by' => 6,
        'reviewed_at' => '2026-11-05 02:50:25',
        'feedback' => 'Approved',
        'created_at' => '2026-11-05 02:47:19',
    ]
];

// Client workspace check
$job = $jobWithDiffBudget;
$escrow = $escrowDiff;
$isLocked = true;
$isClient = true;
$token = Csrf::token('job_milestone');
$reviewToken = Csrf::token('milestone_review');
$finalReviewToken = Csrf::token('final_review');
$notice = null;
$errors = [];
$old = [];
$reviewErrors = [];
$reviewOld = [];
$finalReviewErrors = [];
$finalReviewOld = [];
$navigationUser = ['id' => 6, 'role' => 'client', 'email' => 'client@example.com', 'display_name' => 'Client'];

ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$clientDiffHtml = ob_get_clean();
check(str_contains($clientDiffHtml, 'Escrow Status: Locked (RM 4,300.00)'), 'client view displays authoritative locked escrow amount RM 4,300.00');
check(!str_contains($clientDiffHtml, 'Escrow Status: Locked (RM 5,000.00)'), 'client view does NOT use job budget for locked escrow amount');

// Freelancer workspace check
$isClient = false;
$navigationUser = ['id' => 116, 'role' => 'freelancer', 'email' => 'freelancer@example.com', 'display_name' => 'Freelancer'];
ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$freelancerDiffHtml = ob_get_clean();
check(str_contains($freelancerDiffHtml, 'Agreed Escrow Amount: RM 4,300.00'), 'freelancer view displays authoritative agreed escrow amount RM 4,300.00');
check(!str_contains($freelancerDiffHtml, 'Agreed Escrow Amount: RM 5,000.00'), 'freelancer view does NOT use job budget for agreed escrow amount');

// Freelancer final submission form check
$previousSubmissions = [];
$latestSubmission = null;
$token = Csrf::token('final_submission');
ob_start();
require dirname(__DIR__) . '/app/Views/freelancer/submissions/final.php';
$finalFormDiffHtml = ob_get_clean();
check(str_contains($finalFormDiffHtml, 'Agreed Escrow Amount') && str_contains($finalFormDiffHtml, 'RM 4,300.00'), 'freelancer final submit form displays authoritative escrow amount');
check(!str_contains($finalFormDiffHtml, 'Agreed Budget') && !str_contains($finalFormDiffHtml, 'RM 5,000.00'), 'freelancer final submit form does NOT use advertised budget');

echo "PASS: $count submission offline checks; no database connections.\n";
