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
    'Services/PaymentMoney',
    'Repositories/ProgressiveEscrowRepository',
    'Services/ProgressiveEscrowService',
    'Services/SubmissionValidator',
    'Core/Csrf',
    'Core/Notification',
    'Core/AuthSession',
    'Core/AuthGuard',
    'Core/Router',
    'Repositories/UserRepository',
    'Services/LoginValidator',
    'Services/AuthenticationService',
    'Services/BidValidator',
    'Repositories/MilestoneRepository',
    'Services/MilestoneValidator',
    'Services/MilestoneException',
    'Services/MilestoneService',
    'Controllers/MilestoneController',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

use GigHub\Core\Csrf;
use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Repositories\UserRepository;
use GigHub\Services\AuthenticationService;
use GigHub\Repositories\MilestoneRepository;
use GigHub\Services\MilestoneValidator;
use GigHub\Services\MilestoneException;
use GigHub\Services\MilestoneService;
use GigHub\Controllers\MilestoneController;

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
    } catch (MilestoneException $e) {
        check($e->status === $status, $label);
    }
}

class MilestoneTestPDO extends PDO {
    public bool $active = false;
    public bool $failCommit = false;
    public array $events = [];
    public function __construct() {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->events[] = 'begin'; $this->active = true; return true; }
    public function commit(): bool {
        if ($this->failCommit) throw new RuntimeException('private commit detail');
        $this->events[] = 'commit';
        $this->active = false;
        return true;
    }
    public function rollBack(): bool { $this->events[] = 'rollback'; $this->active = false; return true; }
    public function exec(string $statement): int|false { $this->events[] = $statement; return 0; }
}

$pdo = new MilestoneTestPDO();

$repo = new class extends MilestoneRepository {
    public array|false $jobRow = [
        'id' => 2,
        'client_id' => 6,
        'title' => 'Awarded Website Job',
        'status' => 'awarded',
        'deadline' => '2030-12-31',
        'awarded_bid_id' => 1
    ];
    public bool $clientEligible = true;
    public bool $hasEscrow = false;
    public bool $freelancerEligible = true;
    public bool $isAwarded = true;
    public array $milestones = [];
    public int $nextId = 1;
    public bool $failInsert = false;
    public array $events = [];

    public function __construct() {}

    public function job(int $jobId, bool $lock = false): array|false {
        $this->events[] = 'job:' . ($lock ? 'lock' : 'read');
        return ($this->jobRow !== false && $this->jobRow['id'] === $jobId) ? $this->jobRow : false;
    }

    public array|false $escrowRow = false;

    public function hasEscrow(int $jobId, bool $lock = false): bool {
        $this->events[] = 'escrow:' . ($lock ? 'lock' : 'read');
        return $this->hasEscrow;
    }

    public function escrow(int $jobId, bool $lock = false): array|false {
        $this->events[] = 'escrow:' . ($lock ? 'lock' : 'read');
        return $this->escrowRow;
    }

    public function eligibleClient(int $id, bool $lock = false): bool {
        $this->events[] = 'client:' . ($lock ? 'lock' : 'read');
        return $this->clientEligible;
    }

    public function eligibleFreelancer(int $id, bool $lock = false): bool {
        $this->events[] = 'freelancer:' . ($lock ? 'lock' : 'read');
        return $this->freelancerEligible;
    }

    public function isAwardedFreelancer(int $jobId, int $freelancerId): bool {
        $this->events[] = 'is_awarded_freelancer';
        return $this->isAwarded;
    }

    public function forJob(int $jobId): array {
        $this->events[] = 'for_job';
        $rows = array_values(array_filter($this->milestones, fn($m) => $m['job_id'] === $jobId));
        usort($rows, fn($a, $b) => $a['position'] <=> $b['position']);
        return $rows;
    }

    public array $submissions = [];
    public function submissionsForJob(int $jobId): array {
        return $this->submissions;
    }

    public function find(int $id, int $jobId, bool $lock = false): array|false {
        $this->events[] = 'milestone:' . ($lock ? 'lock' : 'read');
        foreach ($this->milestones as $m) {
            if ($m['id'] === $id && $m['job_id'] === $jobId) {
                return $m;
            }
        }
        return false;
    }

    public function nextPosition(int $jobId): int {
        $this->events[] = 'next_position';
        $max = 0;
        foreach ($this->milestones as $m) {
            if ($m['job_id'] === $jobId && $m['position'] > $max) {
                $max = $m['position'];
            }
        }
        return $max + 1;
    }

    public function countForJob(int $jobId): int {
        $this->events[] = 'count_for_job';
        return count(array_filter($this->milestones, fn($m) => $m['job_id'] === $jobId));
    }

    public function insert(int $jobId, string $title, ?string $description, string $dueDate, int $position): int {
        if ($this->failInsert) throw new RuntimeException('simulated insert failure');
        $this->events[] = 'insert';
        // Enforce UNIQUE(job_id, position) constraint simulation
        foreach ($this->milestones as $m) {
            if ($m['job_id'] === $jobId && $m['position'] === $position) {
                throw new PDOException('Duplicate entry for key uq_milestones_position', 23000);
            }
        }
        $id = $this->nextId++;
        $this->milestones[] = [
            'id' => $id,
            'job_id' => $jobId,
            'title' => $title,
            'description' => $description,
            'due_date' => $dueDate,
            'position' => $position,
            'status' => 'pending',
            'approved_at' => null,
            'created_at' => '2026-11-04 12:00:00',
            'updated_at' => '2026-11-04 12:00:00',
        ];
        return $id;
    }

    public function update(int $id, int $jobId, string $title, ?string $description, string $dueDate): void {
        $this->events[] = 'update';
        foreach ($this->milestones as &$m) {
            if ($m['id'] === $id && $m['job_id'] === $jobId && $m['status'] === 'pending') {
                $m['title'] = $title;
                $m['description'] = $description;
                $m['due_date'] = $dueDate;
                $m['updated_at'] = '2026-11-04 12:30:00';
                return;
            }
        }
    }

    public function delete(int $id, int $jobId): void {
        $this->events[] = 'delete';
        $this->milestones = array_values(array_filter($this->milestones, fn($m) => !($m['id'] === $id && $m['job_id'] === $jobId)));
    }

    public function shiftPositionsDown(int $jobId, int $deletedPosition): void {
        $this->events[] = 'shift_positions';
        // Simulates UPDATE milestones SET position = position - 1 WHERE job_id = ? AND position > ? ORDER BY position ASC
        // We sort ascending first to verify that no collision occurs during sequential movement.
        usort($this->milestones, fn($a, $b) => $a['position'] <=> $b['position']);
        foreach ($this->milestones as &$m) {
            if ($m['job_id'] === $jobId && $m['position'] > $deletedPosition) {
                $newPos = $m['position'] - 1;
                // Check if newPos collides with any other row
                foreach ($this->milestones as $other) {
                    if ($other['job_id'] === $jobId && $other['id'] !== $m['id'] && $other['position'] === $newPos) {
                        throw new PDOException('Duplicate key collision in shiftPositionsDown', 23000);
                    }
                }
                $m['position'] = $newPos;
            }
        }
    }
};

$service = new MilestoneService($pdo, $repo);

// 1. Validator Tests
$now = new DateTimeImmutable('2026-11-04 12:00:00', new DateTimeZone('Asia/Kuala_Lumpur'));
$valEmpty = MilestoneValidator::validate(['title' => '', 'due_date' => '2026-11-10'], '2026-11-30', $now);
check(isset($valEmpty['errors']['title']), 'empty title rejected');

$valLongTitle = MilestoneValidator::validate(['title' => str_repeat('a', 151), 'due_date' => '2026-11-10'], '2026-11-30', $now);
check(isset($valLongTitle['errors']['title']), 'over-150 char title rejected');

$valPast = MilestoneValidator::validate(['title' => 'Valid', 'due_date' => '2026-11-03'], '2026-11-30', $now);
check(isset($valPast['errors']['due_date']), 'past date rejected against Malaysia today');

$valBeyondDeadline = MilestoneValidator::validate(['title' => 'Valid', 'due_date' => '2026-12-01'], '2026-11-30', $now);
check(isset($valBeyondDeadline['errors']['due_date']), 'date beyond job deadline rejected');

$valInvalidSyntax = MilestoneValidator::validate(['title' => 'Valid', 'due_date' => '2026-02-30'], '2026-11-30', $now);
check(isset($valInvalidSyntax['errors']['due_date']), 'invalid calendar date rejected');

$valOk = MilestoneValidator::validate(['title' => 'Milestone 1', 'due_date' => '2026-11-20', 'description' => '   '], '2026-11-30', $now);
check($valOk['errors'] === [] && $valOk['data']['description'] === null, 'valid milestone trimmed and empty desc is null');

// 2. Initial List Empty
$list = $service->listForJob(6, 2);
check($list['milestones'] === [], 'initial milestone list is empty');

// 3. Create First Milestone
$repo->events = [];
$pdo->events = [];
$id1 = $service->createMilestone(6, 2, [
    'title' => 'Design Wireframes',
    'due_date' => '2030-11-15',
    'description' => 'Initial Figma layouts'
]);
check($id1 === 1, 'first milestone gets id 1');
check(count($repo->milestones) === 1, 'milestones array count is 1');
check($repo->milestones[0]['position'] === 1, 'first milestone gets position 1');
check($repo->milestones[0]['status'] === 'pending', 'initial status is pending');
check($pdo->events === ['begin', 'commit'], 'transaction started and committed');
check($repo->events === ['job:lock', 'client:lock', 'escrow:lock', 'count_for_job', 'next_position', 'insert'], 'proper lock order: job:lock -> client:lock -> escrow:lock');

// 4. Create Second Milestone
$pdo->events = [];
$repo->events = [];
$id2 = $service->createMilestone(6, 2, [
    'title' => 'Frontend Coding',
    'due_date' => '2030-11-25',
    'description' => 'HTML and CSS'
]);
check($id2 === 2, 'second milestone gets id 2');
check($repo->milestones[1]['position'] === 2, 'second milestone assigned position 2 sequentially');

// 5. Create Third Milestone
$id3 = $service->createMilestone(6, 2, [
    'title' => 'Backend Integration',
    'due_date' => '2030-12-10',
    'description' => 'PHP services'
]);
check($id3 === 3, 'third milestone gets id 3');
check($repo->milestones[2]['position'] === 3, 'third milestone assigned position 3 sequentially');

// Verify list order
$fullList = $service->listForJob(6, 2);
check(count($fullList['milestones']) === 3, 'all 3 milestones listed');
check(array_column($fullList['milestones'], 'position') === [1, 2, 3], 'positions strictly sequential');

// 6. Edit Milestone
$service->updateMilestone(6, 2, $id2, [
    'title' => 'Frontend Development Updated',
    'due_date' => '2030-11-26',
    'description' => 'Updated CSS description'
]);
$m2 = $service->getMilestone(6, 2, $id2);
check($m2['milestone']['title'] === 'Frontend Development Updated', 'title updated');
check($m2['milestone']['due_date'] === '2030-11-26', 'due date updated');
check($m2['milestone']['position'] === 2, 'position preserved on edit');

// 7. Delete Middle Milestone (Position 2) & Safe Reindexing
$service->deleteMilestone(6, 2, $id2);
$afterDelete = $service->listForJob(6, 2);
check(count($afterDelete['milestones']) === 2, 'two milestones remain after delete');
check($afterDelete['milestones'][0]['id'] === $id1 && $afterDelete['milestones'][0]['position'] === 1, 'first milestone remains position 1');
check($afterDelete['milestones'][1]['id'] === $id3 && $afterDelete['milestones'][1]['position'] === 2, 'third milestone shifted safely to position 2');

// 8. Delete First Milestone (Position 1) & Safe Reindexing
$service->deleteMilestone(6, 2, $id1);
$afterDeleteFirst = $service->listForJob(6, 2);
check(count($afterDeleteFirst['milestones']) === 1, 'one milestone remains');
check($afterDeleteFirst['milestones'][0]['id'] === $id3 && $afterDeleteFirst['milestones'][0]['position'] === 1, 'remaining milestone shifted to position 1');

// 9. Add New Milestone After Deletions
$id4 = $service->createMilestone(6, 2, [
    'title' => 'Final QA',
    'due_date' => '2030-12-20'
]);
$afterAdd = $service->listForJob(6, 2);
check(count($afterAdd['milestones']) === 2, 'two milestones now');
check(array_column($afterAdd['milestones'], 'position') === [1, 2], 'positions contiguous [1, 2]');

// 10. Max Milestones Limit (Cap 20)
for ($i = count($repo->milestones) + 1; $i <= MilestoneService::MAX_MILESTONES; $i++) {
    $service->createMilestone(6, 2, ['title' => "M $i", 'due_date' => '2030-12-25']);
}
check(count($repo->milestones) === 20, '20 milestones created');
denied(fn() => $service->createMilestone(6, 2, ['title' => 'M 21', 'due_date' => '2030-12-25']), 422, 'exceeding 20 milestones rejected with 422');

// Reset milestones
$repo->milestones = [
    ['id' => 1, 'job_id' => 2, 'title' => 'Task 1', 'description' => null, 'due_date' => '2030-11-20', 'position' => 1, 'status' => 'pending', 'approved_at' => null, 'created_at' => '2026-11-04 12:00:00', 'updated_at' => '2026-11-04 12:00:00']
];

// 11. Security & Job State Guards
// Non-owner rejected with 404
denied(fn() => $service->listForJob(999, 2), 404, 'non-owner cannot list milestones');
denied(fn() => $service->createMilestone(999, 2, ['title' => 'X', 'due_date' => '2030-11-20']), 404, 'non-owner cannot create');
denied(fn() => $service->updateMilestone(999, 2, 1, ['title' => 'X', 'due_date' => '2030-11-20']), 404, 'non-owner cannot update');
denied(fn() => $service->deleteMilestone(999, 2, 1), 404, 'non-owner cannot delete');

// Ineligible Client rejected with 403
$repo->clientEligible = false;
denied(fn() => $service->listForJob(6, 2), 403, 'ineligible client cannot list');
denied(fn() => $service->createMilestone(6, 2, ['title' => 'X', 'due_date' => '2030-11-20']), 403, 'ineligible client cannot create');
$repo->clientEligible = true;

// Non-awarded or un-funded initial status (draft, open) rejected with 409
foreach (['draft', 'open'] as $status) {
    $repo->jobRow['status'] = $status;
    denied(fn() => $service->listForJob(6, 2), 409, "job in status $status cannot list milestones");
    denied(fn() => $service->createMilestone(6, 2, ['title' => 'X', 'due_date' => '2030-11-20']), 409, "job in status $status cannot create milestone");
}
$repo->jobRow['status'] = 'awarded';

// Funded job allows read-only viewing for Client, but blocks mutations with 409
$repo->hasEscrow = true;
$repo->jobRow['status'] = 'in_progress';
$fundedView = $service->listForJob(6, 2);
check($fundedView['is_locked'] === true, 'funded job has is_locked true');
check($fundedView['has_escrow'] === true, 'funded job has has_escrow true');
denied(fn() => $service->createMilestone(6, 2, ['title' => 'X', 'due_date' => '2030-11-20']), 409, 'funded job cannot create milestone');
denied(fn() => $service->updateMilestone(6, 2, 1, ['title' => 'X', 'due_date' => '2030-11-20']), 409, 'funded job cannot update milestone');
denied(fn() => $service->deleteMilestone(6, 2, 1), 409, 'funded job cannot delete milestone');

// Winning Freelancer can view funded job milestones
$flView = $service->listForFreelancer(116, 2);
check($flView['is_locked'] === true, 'freelancer view is locked');
check($flView['is_client'] === false, 'freelancer view is not client');

// Terminal or dispute statuses ('cancelled', 'disputed', 'closed_disputed') allow viewing but block mutations
foreach (['cancelled', 'disputed', 'closed_disputed'] as $status) {
    $repo->jobRow['status'] = $status;
    $termView = $service->listForJob(6, 2);
    check($termView['job']['status'] === $status, "job in status $status can be listed by client");
    check($termView['is_locked'] === true, "job in status $status is locked for client");

    $flTermView = $service->listForFreelancer(116, 2);
    check($flTermView['job']['status'] === $status, "job in status $status can be listed by freelancer");
    check($flTermView['is_locked'] === true, "job in status $status is locked for freelancer");

    denied(fn() => $service->createMilestone(6, 2, ['title' => 'X', 'due_date' => '2030-11-20']), 409, "job in status $status cannot create milestone");
}
$repo->jobRow['status'] = 'in_progress';

// Ineligible or non-awarded freelancer rejected
$repo->freelancerEligible = false;
denied(fn() => $service->listForFreelancer(116, 2), 403, 'ineligible freelancer cannot view milestones');
$repo->freelancerEligible = true;

$repo->isAwarded = false;
denied(fn() => $service->listForFreelancer(999, 2), 404, 'non-awarded freelancer cannot view milestones');
$repo->isAwarded = true;

$repo->hasEscrow = false;
$repo->jobRow['status'] = 'awarded';

// 12. Non-pending milestone cannot be edited or deleted
$repo->milestones[0]['status'] = 'approved';
denied(fn() => $service->updateMilestone(6, 2, 1, ['title' => 'X', 'due_date' => '2030-11-20']), 409, 'approved milestone cannot be updated');
denied(fn() => $service->deleteMilestone(6, 2, 1), 409, 'approved milestone cannot be deleted');
$repo->milestones[0]['status'] = 'pending';

// 13. Transaction Rollback & Error Masking
$repo->failInsert = true;
$pdo->events = [];
$threw = false;
try {
    $service->createMilestone(6, 2, ['title' => 'Rollback Task', 'due_date' => '2030-11-20']);
} catch (RuntimeException $e) {
    $threw = true;
    check($e->getMessage() === 'Milestone operation is temporarily unavailable.', 'internal error masked');
}
check($threw, 'exception thrown on insert failure');
check($pdo->events === ['begin', 'rollback'], 'transaction rolled back on insert failure');
$repo->failInsert = false;

// 14. Savepoint Handling Under Outer Transaction
$pdo->active = true;
$pdo->events = [];
$service->createMilestone(6, 2, ['title' => 'Savepoint Task', 'due_date' => '2030-11-20']);
check(in_array('SAVEPOINT gighub_milestone', $pdo->events, true), 'savepoint created');
check(in_array('RELEASE SAVEPOINT gighub_milestone', $pdo->events, true), 'savepoint released');
$pdo->active = false;

// 15. Controller Tests
$users = new class extends UserRepository {
    public array $row = ['id' => 6, 'role' => 'client', 'display_name' => 'Test Client', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false { return $this->row; }
};

$_SESSION = [];
$session = new AuthSession(null, static fn() => true);
$auth = new AuthenticationService($users);
$guard = new AuthGuard(static fn() => $auth, $session);
$controller = new MilestoneController($guard, fn() => $service);

// Anonymous user -> 303 redirect
$anon = $controller->index(['job_id' => 2]);
check($anon['status'] === 303 && $anon['location'] === '?route=/login', 'anonymous index redirects to login');

// Authenticate Client 6
$session->establish(6);

// GET Index 200
$indexResp = $controller->index(['job_id' => 2]);
check($indexResp['status'] === 200, 'authenticated client receives 200 index');
check(str_contains($indexResp['body'], 'Milestones for Awarded Website Job'), 'job title rendered');
check(str_contains($indexResp['body'], 'Add Milestone'), 'add form present');

// Invalid CSRF on POST -> 403
$invalidCsrf = $controller->create(['job_id' => 2, 'csrf_token' => 'invalid']);
check($invalidCsrf['status'] === 403, 'invalid CSRF rejected with 403');

// POST Create -> 303 PRG
$validToken = Csrf::token('job_milestone');
$createResp = $controller->create([
    'job_id' => 2,
    'title' => 'Controller Created Task',
    'due_date' => '2030-11-22',
    'csrf_token' => $validToken
]);
check($createResp['status'] === 303 && $createResp['location'] === '?route=/client/jobs/milestones&job_id=2', 'create returns 303 PRG redirect');
check(isset($_SESSION['milestone_notice']), 'flash notice set');

// POST Update -> 303 PRG
$lastId = end($repo->milestones)['id'];
$updateToken = Csrf::token('job_milestone');
$updateResp = $controller->update([
    'job_id' => 2,
    'id' => $lastId,
    'title' => 'Controller Updated Title',
    'due_date' => '2030-11-23',
    'csrf_token' => $updateToken
]);
check($updateResp['status'] === 303 && $updateResp['location'] === '?route=/client/jobs/milestones&job_id=2', 'update returns 303 PRG redirect');

// POST Delete -> 303 PRG
$deleteToken = Csrf::token('job_milestone');
$deleteResp = $controller->delete([
    'job_id' => 2,
    'id' => $lastId,
    'csrf_token' => $deleteToken
]);
check($deleteResp['status'] === 303 && $deleteResp['location'] === '?route=/client/jobs/milestones&job_id=2', 'delete returns 303 PRG redirect');

// Role Restrictions
foreach (['freelancer', 'admin'] as $role) {
    $users->row['role'] = $role;
    check($controller->index(['job_id' => 2])['status'] === 403, "role $role forbidden from milestones index");
    check($controller->create(['job_id' => 2, 'csrf_token' => $validToken])['status'] === 403, "role $role forbidden from milestones create");
}
$users->row['role'] = 'client';

// Freelancer milestone viewing
$users->row = ['id' => 116, 'role' => 'freelancer', 'display_name' => 'Alice Freelancer', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];
$session->establish(116);
$flResp = $controller->freelancerIndex(['job_id' => 2]);
check($flResp['status'] === 200, 'freelancer can view milestones via freelancerIndex');
check(str_contains($flResp['body'], 'FREELANCER WORKSPACE'), 'freelancer view shows freelancer workspace eyebrow');
check(!str_contains($flResp['body'], 'Add Milestone'), 'freelancer view hides add milestone form');

// Client calling freelancerIndex receives 403
$users->row['role'] = 'client';
$session->establish(6);
check($controller->freelancerIndex(['job_id' => 2])['status'] === 403, 'client forbidden from freelancerIndex');

// 16. View Rendering Regression Tests with strict error handler (no warnings/exceptions)
$repo->milestones[0]['status'] = 'submitted';
$repo->submissions = [
    [
        'id' => 1,
        'job_id' => 2,
        'milestone_id' => $repo->milestones[0]['id'],
        'submitted_by' => 116,
        'status' => 'submitted',
        'notes' => 'Attempt 1 submission deliverable notes that exceed 300 characters to test the expandable component thoroughly across all views. ' . str_repeat('Additional details and scope explanation. ', 5),
        'deliverable_url' => 'https://github.com/example/cafe-ordering',
        'feedback' => null,
        'reviewed_by' => null,
        'reviewed_at' => null,
        'created_at' => '2026-11-04 12:00:00',
    ],
];

// Test Client Workspace Rendering (GET /client/jobs/milestones)
$users->row = ['id' => 6, 'role' => 'client', 'display_name' => 'Test Client', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];
$session->establish(6);
$clientRender = $controller->index(['job_id' => 2]);
check($clientRender['status'] === 200, 'client milestone view renders 200 OK with strict error handler');
check(str_contains($clientRender['body'], 'gh-review-panel'), 'client review panel rendered');
check(str_contains($clientRender['body'], 'gh-sub-card'), 'submission history card rendered');
check(str_contains($clientRender['body'], 'Attempt #1'), 'attempt number rendered in history');
check(preg_match('/<link rel="stylesheet" href="assets\/milestones\.css\?v=[a-f0-9]{12}">/', $clientRender['body']) === 1, 'client milestone view includes milestones.css with sha256 hash cache-buster');

// Test Freelancer Workspace Rendering (GET /freelancer/jobs/milestones)
$users->row = ['id' => 116, 'role' => 'freelancer', 'display_name' => 'Alice Freelancer', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];
$session->establish(116);
$flRender = $controller->freelancerIndex(['job_id' => 2]);
check($flRender['status'] === 200, 'freelancer milestone view renders 200 OK with strict error handler');
check(str_contains($flRender['body'], 'gh-sub-card'), 'freelancer view renders submission history card');
check(str_contains($flRender['body'], 'Attempt #1'), 'freelancer view shows attempt #1');
check(preg_match('/<link rel="stylesheet" href="assets\/milestones\.css\?v=[a-f0-9]{12}">/', $flRender['body']) === 1, 'freelancer milestone view includes milestones.css with sha256 hash cache-buster');

// Test Cancelled Contract Workspace Rendering for both Client and Freelancer
$repo->jobRow['status'] = 'cancelled';
$users->row = ['id' => 6, 'role' => 'client', 'display_name' => 'Test Client', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];
$session->establish(6);
$clientCancelRender = $controller->index(['job_id' => 2]);
check($clientCancelRender['status'] === 200, 'client milestone view renders 200 OK for cancelled job');

$users->row = ['id' => 116, 'role' => 'freelancer', 'display_name' => 'Alice Freelancer', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];
$session->establish(116);
$flCancelRender = $controller->freelancerIndex(['job_id' => 2]);
check($flCancelRender['status'] === 200, 'freelancer milestone view renders 200 OK for cancelled job');
$repo->jobRow['status'] = 'in_progress';

echo "PASS: {$count} milestone offline checks; no database connections.\n";
