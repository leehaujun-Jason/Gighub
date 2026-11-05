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
    'Repositories/EscrowRepository',
    'Services/EscrowException',
    'Services/EscrowService',
    'Controllers/EscrowController',
    'Repositories/WalletRepository',
    'Services/WalletException',
    'Services/WalletService',
    'Controllers/WalletController',
    'Repositories/MilestoneRepository',
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
use GigHub\Repositories\EscrowRepository;
use GigHub\Services\EscrowException;
use GigHub\Services\EscrowService;
use GigHub\Controllers\EscrowController;
use GigHub\Repositories\WalletRepository;
use GigHub\Services\WalletException;
use GigHub\Services\WalletService;
use GigHub\Controllers\WalletController;

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
    } catch (EscrowException $e) {
        check($e->getCode() === $status, $label . ' (expected ' . $status . ', got ' . $e->getCode() . ': ' . $e->getMessage() . ')');
    }
}

// -------------------------------------------------------------
// Mock PDO & Repository for Settlement Testing
// -------------------------------------------------------------
class MockSettlementPDO extends PDO {
    public int $txLevel = 0;
    public array $events = [];
    public ?MockSettlementRepository $repo = null;
    public function __construct() {}
    public function beginTransaction(): bool {
        $this->events[] = 'tx:begin';
        ++$this->txLevel;
        $this->repo?->snapshot();
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
        $this->repo?->revert();
        return true;
    }
    public function inTransaction(): bool { return $this->txLevel > 0; }
    public function exec(string $statement): int|false {
        $trimmed = trim($statement);
        $this->events[] = 'exec:' . $trimmed;
        return 1;
    }
}

class MockSettlementRepository extends EscrowRepository {
    public array $events = [];
    public array $snapshots = [];

    public ?array $job = [
        'id' => 2,
        'client_id' => 6,
        'title' => 'Cafe Ordering System development',
        'status' => 'under_review',
        'budget' => '5000.00',
        'deadline' => '2027-01-31',
        'awarded_bid_id' => 1,
        'awarded_at' => '2026-11-02 14:57:42',
        'completed_at' => null,
        'closed_at' => null,
    ];

    public bool $isEligibleClient = true;
    public bool $isEligibleFreelancer = true;

    public ?array $bid = [
        'id' => 1,
        'job_id' => 2,
        'freelancer_id' => 116,
        'freelancer_name' => 'TechConsultant',
        'amount' => '4300.00',
        'delivery_days' => 14,
        'status' => 'accepted',
        'created_at' => '2026-11-02 14:57:42'
    ];

    public ?array $escrow = [
        'id' => 1,
        'job_id' => 2,
        'bid_id' => 1,
        'amount' => '4300.00',
        'status' => 'locked',
        'funded_at' => '2026-11-02 15:00:00',
        'settled_at' => null,
    ];

    public array $milestones = [
        ['id' => 1, 'job_id' => 2, 'title' => 'Milestone 1', 'status' => 'approved', 'position' => 1],
        ['id' => 2, 'job_id' => 2, 'title' => 'Milestone 2', 'status' => 'approved', 'position' => 2],
    ];

    public ?array $finalSubmission = [
        'id' => 5,
        'job_id' => 2,
        'submission_type' => 'final',
        'status' => 'approved',
        'notes' => 'Complete cafe ordering system deliverable handover.',
        'deliverable_url' => 'https://github.com/example/cafe-system',
    ];

    public array $wallets = [
        6 => ['user_id' => 6, 'balance' => '5700.00'],
        116 => ['user_id' => 116, 'balance' => '0.00'],
    ];

    public array $ledger = [];
    public array $notifications = [];

    public bool $failReleaseEscrow = false;
    public bool $failCreditWallet = false;
    public bool $failCompleteJob = false;

    public function __construct(PDO $pdo) {
        parent::__construct($pdo);
    }

    public function snapshot(): void {
        $this->snapshots[] = [
            'job' => $this->job,
            'escrow' => $this->escrow,
            'wallets' => $this->wallets,
            'ledger' => $this->ledger,
            'notifications' => $this->notifications,
        ];
    }

    public function revert(): void {
        if (!empty($this->snapshots)) {
            $snap = array_pop($this->snapshots);
            $this->job = $snap['job'];
            $this->escrow = $snap['escrow'];
            $this->wallets = $snap['wallets'];
            $this->ledger = $snap['ledger'];
            $this->notifications = $snap['notifications'];
        }
    }

    public function job(int $jobId, bool $lock = false): array|false {
        $this->events[] = 'job:' . $jobId . ($lock ? ':lock' : '');
        return ($this->job !== null && $this->job['id'] === $jobId) ? $this->job : false;
    }

    public function eligibleClient(int $id, bool $lock = false): bool {
        $this->events[] = 'eligibleClient:' . $id . ($lock ? ':lock' : '');
        return $this->isEligibleClient;
    }

    public function eligibleFreelancer(int $id, bool $lock = false): bool {
        $this->events[] = 'eligibleFreelancer:' . $id . ($lock ? ':lock' : '');
        return $this->isEligibleFreelancer;
    }

    public function awardedBid(int $jobId, int $bidId, bool $lock = false): array|false {
        $this->events[] = 'awardedBid:' . $bidId . ($lock ? ':lock' : '');
        return ($this->bid !== null && $this->bid['id'] === $bidId && $this->bid['job_id'] === $jobId) ? $this->bid : false;
    }

    public function getWallet(int $userId, bool $lock = false): array|false {
        $this->events[] = 'getWallet:' . $userId . ($lock ? ':lock' : '');
        return $this->wallets[$userId] ?? false;
    }

    public function getEscrow(int $jobId, bool $lock = false): array|false {
        $this->events[] = 'getEscrow:' . $jobId . ($lock ? ':lock' : '');
        return ($this->escrow !== null && $this->escrow['job_id'] === $jobId) ? $this->escrow : false;
    }

    public function milestones(int $jobId): array {
        $this->events[] = 'milestones:' . $jobId;
        return $this->milestones;
    }

    public function lockMilestones(int $jobId): array {
        $this->events[] = 'lockMilestones:' . $jobId;
        return $this->milestones;
    }

    public function lockLatestFinalSubmission(int $jobId): array|false {
        $this->events[] = 'lockLatestFinalSubmission:' . $jobId;
        return $this->finalSubmission ?? false;
    }

    public function hasSettlementLedger(int $escrowId): bool {
        $this->events[] = 'hasSettlementLedger:' . $escrowId;
        foreach ($this->ledger as $entry) {
            if ($entry['escrow_id'] === $escrowId && in_array($entry['entry_type'], ['escrow_release', 'escrow_refund'], true)) {
                return true;
            }
        }
        return false;
    }

    public function hasOperationKey(string $key): bool {
        $this->events[] = 'hasOperationKey:' . $key;
        foreach ($this->ledger as $entry) {
            if ($entry['operation_key'] === $key) {
                return true;
            }
        }
        return false;
    }

    public function creditWallet(int $userId, string $amount): bool {
        $this->events[] = 'creditWallet:' . $userId . ':' . $amount;
        if ($this->failCreditWallet) return false;
        if (!isset($this->wallets[$userId])) return false;
        $this->wallets[$userId]['balance'] = bcadd($this->wallets[$userId]['balance'], $amount, 2);
        return true;
    }

    public function releaseEscrow(int $escrowId): bool {
        $this->events[] = 'releaseEscrow:' . $escrowId;
        if ($this->failReleaseEscrow) return false;
        if ($this->escrow === null || $this->escrow['id'] !== $escrowId || $this->escrow['status'] !== 'locked') {
            return false;
        }
        $this->escrow['status'] = 'released';
        $this->escrow['settled_at'] = '2026-11-05 14:00:00';
        return true;
    }

    public function insertLedgerRelease(int $freelancerId, int $escrowId, string $amount, int $actorId, string $operationKey): int {
        $this->events[] = 'insertLedgerRelease:' . $freelancerId . ':' . $amount;
        $id = count($this->ledger) + 1;
        $this->ledger[] = [
            'id' => $id,
            'wallet_user_id' => $freelancerId,
            'escrow_id' => $escrowId,
            'entry_type' => 'escrow_release',
            'amount_delta' => $amount,
            'source' => 'client_approval',
            'actor_id' => $actorId,
            'operation_key' => $operationKey,
            'created_at' => '2026-11-05 14:00:00',
        ];
        return $id;
    }

    public function completeJob(int $jobId): bool {
        $this->events[] = 'completeJob:' . $jobId;
        if ($this->failCompleteJob) return false;
        if ($this->job === null || $this->job['id'] !== $jobId || $this->job['status'] !== 'under_review') {
            return false;
        }
        $this->job['status'] = 'completed';
        $this->job['completed_at'] = '2026-11-05 14:00:00';
        $this->job['closed_at'] = '2026-11-05 14:00:00';
        return true;
    }

    public function createPaymentNotification(int $recipientId, int $jobId, string $message): void {
        $this->events[] = 'createPaymentNotification:' . $recipientId;
        $this->notifications[] = [
            'recipient_id' => $recipientId,
            'job_id' => $jobId,
            'type' => 'payment_released',
            'message' => $message,
            'created_at' => '2026-11-05 14:00:00',
        ];
    }
}

// -------------------------------------------------------------
// Test Runner Setup
// -------------------------------------------------------------
$pdo = new MockSettlementPDO();
$repo = new MockSettlementRepository($pdo);
$pdo->repo = $repo;
$service = new EscrowService($pdo, $repo);

echo "Starting Module 16 Settlement Offline Test Suite...\n";

// -------------------------------------------------------------
// 1. Precondition Checks: Ownership, Status, Eligibility
// -------------------------------------------------------------

// Non-existent job
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 999), 404, 'Non-existent job rejected');

// Non-owner client
denied(fn() => $service->releaseEscrowAndCompleteJob(99, 2), 404, 'Non-owner client rejected');

// Ineligible client
$repo->isEligibleClient = false;
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 403, 'Ineligible client rejected');
$repo->isEligibleClient = true;

// Job statuses other than under_review
foreach (['draft', 'open', 'awarded', 'in_progress', 'completed', 'disputed'] as $invalidStatus) {
    $repo->job['status'] = $invalidStatus;
    denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, "Job status '{$invalidStatus}' rejected");
}
$repo->job['status'] = 'under_review';

// Job missing awarded_bid_id
$repo->job['awarded_bid_id'] = null;
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Job without awarded_bid_id rejected');
$repo->job['awarded_bid_id'] = 1;

// Awarded bid not accepted
$repo->bid['status'] = 'pending';
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Non-accepted bid rejected');
$repo->bid['status'] = 'accepted';

// Ineligible freelancer
$repo->isEligibleFreelancer = false;
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 403, 'Ineligible freelancer rejected');
$repo->isEligibleFreelancer = true;

// -------------------------------------------------------------
// 2. Freelancer Destination Wallet Precondition (No silent creation)
// -------------------------------------------------------------
unset($repo->wallets[116]);
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Uninitialized freelancer wallet strictly rejected');
$repo->wallets[116] = ['user_id' => 116, 'balance' => '0.00'];

// -------------------------------------------------------------
// 3. Escrow Record Preconditions
// -------------------------------------------------------------
$savedEscrow = $repo->escrow;

// Missing escrow
$repo->escrow = null;
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 404, 'Missing escrow record rejected');
$repo->escrow = $savedEscrow;

// Non-locked escrow
$repo->escrow['status'] = 'released';
$repo->escrow['settled_at'] = '2026-11-04 10:00:00';
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Already released escrow rejected');
$repo->escrow = $savedEscrow;

// Escrow amount mismatch with accepted bid
$repo->escrow['amount'] = '5000.00'; // budget != bid
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 422, 'Escrow amount mismatch with bid rejected');
$repo->escrow = $savedEscrow;

// -------------------------------------------------------------
// 4. Milestone Preconditions
// -------------------------------------------------------------
// No milestones
$repo->milestones = [];
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 422, 'Empty milestones rejected');

// Unapproved milestone
$repo->milestones = [
    ['id' => 1, 'job_id' => 2, 'title' => 'M1', 'status' => 'approved', 'position' => 1],
    ['id' => 2, 'job_id' => 2, 'title' => 'M2', 'status' => 'revisions_requested', 'position' => 2],
];
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 422, 'Unapproved milestone rejected');

$repo->milestones = [
    ['id' => 1, 'job_id' => 2, 'title' => 'M1', 'status' => 'approved', 'position' => 1],
    ['id' => 2, 'job_id' => 2, 'title' => 'M2', 'status' => 'approved', 'position' => 2],
];

// -------------------------------------------------------------
// 5. Final Submission Preconditions
// -------------------------------------------------------------
// Missing final submission
$repo->finalSubmission = null;
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Missing final submission rejected');

// Final submission pending review
$repo->finalSubmission = [
    'id' => 5,
    'job_id' => 2,
    'submission_type' => 'final',
    'status' => 'submitted',
    'notes' => 'Handover',
];
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Unreviewed final submission rejected');

// Final submission revision requested
$repo->finalSubmission['status'] = 'revisions_requested';
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Revision requested final submission rejected');

$repo->finalSubmission['status'] = 'approved';

// -------------------------------------------------------------
// 6. Outer Transaction Guard
// -------------------------------------------------------------
$pdo->beginTransaction();
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 500, 'Nested transaction call rejected');
$pdo->rollBack();

// -------------------------------------------------------------
// 7. Successful Atomic Escrow Release Execution
// -------------------------------------------------------------
$repo->events = [];
$pdo->events = [];

$result = $service->releaseEscrowAndCompleteJob(6, 2);

check($result['job_id'] === 2, 'Settlement result contains correct job_id');
check($result['escrow_id'] === 1, 'Settlement result contains correct escrow_id');
check($result['freelancer_id'] === 116, 'Settlement result contains correct freelancer_id');
check($result['amount'] === '4300.00', 'Settlement result contains correct amount RM4,300.00');
check($result['ledger_id'] === 1, 'Settlement result contains ledger entry ID');

// Verify deterministic lock order in recorded events
$expectedLockOrder = [
    'job:2:lock',
    'eligibleClient:6:lock',
    'awardedBid:1:lock',
    'eligibleFreelancer:116:lock',
    'getWallet:116:lock',
    'getEscrow:2:lock',
    'lockMilestones:2',
    'lockLatestFinalSubmission:2',
];
foreach ($expectedLockOrder as $idx => $expectedEvent) {
    check(isset($repo->events[$idx]) && $repo->events[$idx] === $expectedEvent, "Lock event #{$idx} was {$expectedEvent}");
}

// Verify transaction commits
check(in_array('tx:begin', $pdo->events, true), 'Transaction begin was executed');
check(in_array('tx:commit', $pdo->events, true), 'Transaction commit was executed');
check(!$pdo->inTransaction(), 'Transaction is closed after commit');

// Verify database mutations
check($repo->escrow['status'] === 'released', 'Escrow status transitioned to released');
check($repo->escrow['settled_at'] !== null, 'Escrow settled_at timestamp populated');
check($repo->wallets[116]['balance'] === '4300.00', 'Freelancer wallet credited by exactly RM4,300.00');
check($repo->wallets[6]['balance'] === '5700.00', 'Client wallet balance untouched (no double-debit)');
check($repo->job['status'] === 'completed', 'Job transitioned to completed');
check($repo->job['completed_at'] !== null, 'Job completed_at timestamp populated');
check($repo->job['closed_at'] !== null, 'Job closed_at timestamp populated');

// Verify ledger entry
check(count($repo->ledger) === 1, 'Exactly one ledger entry created');
$ledgerEntry = $repo->ledger[0];
check($ledgerEntry['entry_type'] === 'escrow_release', 'Ledger entry_type is escrow_release');
check($ledgerEntry['wallet_user_id'] === 116, 'Ledger wallet_user_id is winning freelancer');
check($ledgerEntry['escrow_id'] === 1, 'Ledger escrow_id is correct');
check($ledgerEntry['amount_delta'] === '4300.00', 'Ledger amount_delta is +4300.00');
check($ledgerEntry['source'] === 'client_approval', 'Ledger source is client_approval');
check($ledgerEntry['actor_id'] === 6, 'Ledger actor_id is client');
check($ledgerEntry['operation_key'] === 'escrow_release:job:2', 'Ledger operation_key is escrow_release:job:2');

// Verify notification
check(count($repo->notifications) === 1, 'Payment notification created');
$notif = $repo->notifications[0];
check($notif['recipient_id'] === 116, 'Notification recipient is freelancer');
check($notif['type'] === 'payment_released', 'Notification type is payment_released');
check(str_contains($notif['message'], '4,300.00'), 'Notification mentions RM4,300.00');
check(str_contains($notif['message'], 'Cafe Ordering System'), 'Notification mentions job title');

// -------------------------------------------------------------
// 8. Idempotency & Repeat Settlement Prevention
// -------------------------------------------------------------
// Repeat settlement fails because job is already completed and escrow is already released
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Repeat settlement call rejected (job completed)');

// Simulate attempt where job was under_review but settlement ledger already exists
$repo->job['status'] = 'under_review';
$repo->escrow['status'] = 'locked';
$repo->escrow['settled_at'] = null;
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Settlement rejected when settlement ledger exists');

// Reset repository state for rollback test
$repo->ledger = [];
$repo->notifications = [];
$repo->wallets[116]['balance'] = '0.00';
$repo->job['status'] = 'under_review';
$repo->job['completed_at'] = null;
$repo->job['closed_at'] = null;
$repo->escrow['status'] = 'locked';
$repo->escrow['settled_at'] = null;

// -------------------------------------------------------------
// 9. Rollback Verification on Failure
// -------------------------------------------------------------
// Failure during releaseEscrow
$repo->failReleaseEscrow = true;
$pdo->events = [];
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Failure during releaseEscrow triggers exception');
check(in_array('tx:rollback', $pdo->events, true), 'Rollback executed on releaseEscrow failure');
check($repo->escrow['status'] === 'locked', 'Escrow status reverted to locked');
check($repo->wallets[116]['balance'] === '0.00', 'Wallet balance reverted');
check($repo->job['status'] === 'under_review', 'Job status reverted');
$repo->failReleaseEscrow = false;

// Failure during creditWallet
$repo->failCreditWallet = true;
$pdo->events = [];
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 500, 'Failure during creditWallet triggers exception');
check(in_array('tx:rollback', $pdo->events, true), 'Rollback executed on creditWallet failure');
check($repo->escrow['status'] === 'locked', 'Escrow status reverted to locked');
check($repo->wallets[116]['balance'] === '0.00', 'Wallet balance reverted');
$repo->failCreditWallet = false;

// Failure during completeJob
$repo->failCompleteJob = true;
$pdo->events = [];
denied(fn() => $service->releaseEscrowAndCompleteJob(6, 2), 409, 'Failure during completeJob triggers exception');
check(in_array('tx:rollback', $pdo->events, true), 'Rollback executed on completeJob failure');
check($repo->escrow['status'] === 'locked', 'Escrow status reverted to locked');
check($repo->wallets[116]['balance'] === '0.00', 'Wallet balance reverted');
check($repo->job['status'] === 'under_review', 'Job status reverted to under_review');
$repo->failCompleteJob = false;

// -------------------------------------------------------------
// 10. Controller Level HTTP & CSRF Verification
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
    public function findAuthIdentity(int $id): array|false {
        return $this->userRef;
    }
};
$authGuard = new AuthGuard(static fn() => new AuthenticationService($mockUserRepo, new \GigHub\Services\LoginValidator()), $authSession);

$controller = new EscrowController($authGuard, static fn() => $service);

// CSRF check
$validToken = Csrf::token('escrow_release');

// Invalid CSRF token
$resp = $controller->release(['job_id' => 2, 'csrf_token' => 'invalid_csrf_token_string_which_fails_validation_check_64charslong1234']);
check($resp['status'] === 403, 'Invalid CSRF rejected with 403');

// Missing CSRF token
$resp = $controller->release(['job_id' => 2]);
check($resp['status'] === 403, 'Missing CSRF rejected with 403');

// Invalid job ID
$resp = $controller->release(['job_id' => 'abc', 'csrf_token' => $validToken]);
check($resp['status'] === 400, 'Invalid job_id rejected with 400');

// Successful release via controller
$resp = $controller->release(['job_id' => 2, 'csrf_token' => $validToken]);
check($resp['status'] === 303, 'Successful release redirects with 303');
check($resp['location'] === '?route=/client/jobs/milestones&job_id=2', 'Redirects to milestone workspace');
check(isset($_SESSION['gh_notifications']) && count($_SESSION['gh_notifications']) > 0, 'Success flash notification set');
check($_SESSION['gh_notifications'][0]['type'] === 'success', 'Notification type is success');
check(str_contains($_SESSION['gh_notifications'][0]['message'], 'RM4,300.00'), 'Notification message contains RM4,300.00');

// Non-client role access to controller release
$_SESSION['auth']['user_id'] = 116;
$freelancerUser = [
    'id' => 116,
    'role' => 'freelancer',
    'display_name' => 'TechConsultant',
    'status' => 'active',
    'email_verified_at' => '2026-01-01 00:00:00',
];
$mockFreelancerRepo = new class($freelancerUser) extends UserRepository {
    public function __construct(public array $user) {}
    public function findAuthIdentity(int $id): array|false {
        return $this->user;
    }
};
$freelancerAuthGuard = new AuthGuard(static fn() => new AuthenticationService($mockFreelancerRepo, new \GigHub\Services\LoginValidator()), $authSession);
$freelancerController = new EscrowController($freelancerAuthGuard, static fn() => $service);
$resp = $freelancerController->release(['job_id' => 2, 'csrf_token' => Csrf::token('escrow_release')]);
check($resp['status'] === 403, 'Freelancer role forbidden from escrow release');

// -------------------------------------------------------------
// 11. Freelancer Wallet Verification (GET /freelancer/wallet)
// -------------------------------------------------------------
class MockWalletRepo extends WalletRepository {
    public array $wallets = [];
    public array $entries = [];
    public bool $isEligibleClient = true;
    public bool $isEligibleFreelancer = true;
    public function __construct() {}
    public function eligibleClient(int $id, bool $lock = false): bool { return $this->isEligibleClient; }
    public function eligibleFreelancer(int $id, bool $lock = false): bool { return $this->isEligibleFreelancer; }
    public function eligibleUser(int $id, string $role, bool $lock = false): bool {
        return $role === 'client' ? $this->isEligibleClient : $this->isEligibleFreelancer;
    }
    public function getWallet(int $userId, bool $lock = false): array|false {
        return $this->wallets[$userId] ?? false;
    }
    public function ensureWallet(int $userId): void {
        if (!isset($this->wallets[$userId])) {
            $this->wallets[$userId] = ['user_id' => $userId, 'balance' => '0.00', 'updated_at' => null];
        }
    }
    public function hasDemoCredit(int $userId, bool $lock = false): bool { return false; }
    public function getEntries(int $userId, int $page = 1): array {
        return $this->entries[$userId] ?? [];
    }
    public function isClientJobOwner(int $clientId, int $jobId): bool {
        return $jobId === 2 && $clientId === 6;
    }
    public function isFreelancerAwardedJob(int $freelancerId, int $jobId): bool {
        return $jobId === 2 && $freelancerId === 116;
    }
}

$mockWalletRepo = new MockWalletRepo();
$walletService = new WalletService(new MockSettlementPDO(), $mockWalletRepo);
$walletController = new WalletController($freelancerAuthGuard, static fn() => $walletService);

// 11a. Uninitialized freelancer visits /freelancer/wallet -> initializes with 0.00
check($mockWalletRepo->getWallet(116) === false, 'Wallet row does not exist prior to page visit');
$overview = $walletService->getWalletOverview(116, 'freelancer');
check($mockWalletRepo->getWallet(116) !== false, 'Wallet row created upon page visit outside settlement');
check($overview['wallet']['balance'] === '0.00', 'Initial balance is RM0.00');
check($overview['has_demo_credit'] === false, 'Freelancers do not have demo credit');
check($overview['role'] === 'freelancer', 'Role is freelancer');

// 11b. Freelancer controller showFreelancer
$walletResp = $walletController->showFreelancer([]);
check($walletResp['status'] === 200, 'showFreelancer returns 200');
check(str_contains($walletResp['body'], 'FREELANCER WORKSPACE'), 'Freelancer eyebrow rendered');
check(str_contains($walletResp['body'], 'Simulated Freelancer Wallet'), 'Freelancer title rendered');
check(str_contains($walletResp['body'], 'Current Balance:'), 'Current balance label rendered');
check(str_contains($walletResp['body'], 'RM0.00'), 'Initial RM0.00 balance rendered');
check(!str_contains($walletResp['body'], 'Claim RM10,000 Demo Credit'), 'Demo credit button hidden for freelancer');

// 11c. Freelancer controller show (client route) rejects freelancer with 403
$walletClientRouteResp = $walletController->show([]);
check($walletClientRouteResp['status'] === 403, 'Freelancer cannot access /client/wallet');

// 11d. Client controller showFreelancer rejects client with 403
$clientWalletController = new WalletController($authGuard, static fn() => $walletService);
$clientShowFreelancerResp = $clientWalletController->showFreelancer([]);
check($clientShowFreelancerResp['status'] === 403, 'Client cannot access /freelancer/wallet');

// 11e. Freelancer wallet after settlement credit
$mockWalletRepo->wallets[116]['balance'] = '4300.00';
$mockWalletRepo->entries[116] = [
    [
        'id' => 1,
        'wallet_user_id' => 116,
        'escrow_id' => 1,
        'entry_type' => 'escrow_release',
        'amount_delta' => '4300.00',
        'source' => 'client_approval',
        'actor_id' => 6,
        'operation_key' => 'escrow_release:job:2',
        'created_at' => '2026-11-05 14:00:00',
        'job_id' => 2,
        'job_title' => 'Cafe Ordering System development',
        'job_client_id' => 6,
        'job_freelancer_id' => 116,
    ]
];

$creditedResp = $walletController->showFreelancer([]);
check($creditedResp['status'] === 200, 'Credited wallet returns 200');
check(str_contains($creditedResp['body'], 'RM4,300.00'), 'Updated balance RM4,300.00 displayed');
check(str_contains($creditedResp['body'], '+RM4,300.00'), 'Positive ledger delta +RM4,300.00 rendered in green');
check(str_contains($creditedResp['body'], 'Escrow Release'), 'Escrow Release entry type rendered');
check(str_contains($creditedResp['body'], 'Client Approval'), 'Client Approval source rendered');
check(str_contains($creditedResp['body'], 'Related Job'), 'Related Job column header rendered');
check(str_contains($creditedResp['body'], 'Cafe Ordering System development'), 'Job title rendered in table');
check(str_contains($creditedResp['body'], '?route=/freelancer/jobs/milestones&amp;job_id=2'), 'Authorized clickable job link rendered');
check(str_contains($creditedResp['body'], '← Back to Freelancer Workspace'), 'Default back to workspace link rendered');

// 11f. Back navigation with job_id and fallback general wallet activity
$creditedWithJobResp = $walletController->showFreelancer(['job_id' => 2]);
check(str_contains($creditedWithJobResp['body'], '← Back to Job Milestones'), 'Freelancer back to job milestones link rendered');
check(str_contains($creditedWithJobResp['body'], '?route=/freelancer/jobs/milestones&amp;job_id=2'), 'Freelancer return link correct');

$mockWalletRepo->entries[6] = [
    [
        'id' => 2,
        'wallet_user_id' => 6,
        'escrow_id' => null,
        'entry_type' => 'demo_credit',
        'amount_delta' => '10000.00',
        'source' => 'demo_seed',
        'actor_id' => 6,
        'operation_key' => 'demo:user:6',
        'created_at' => '2026-11-05 13:00:00',
        'job_id' => null,
        'job_title' => null,
        'job_client_id' => null,
        'job_freelancer_id' => null,
    ]
];
$clientWalletResp = $clientWalletController->show([]);
check(str_contains($clientWalletResp['body'], '← Back to Client Workspace'), 'Client default back to workspace rendered');
check(str_contains($clientWalletResp['body'], 'General Wallet Activity'), 'Fallback General Wallet Activity rendered when no job is associated');
$clientWalletJobResp = $clientWalletController->show(['job_id' => 2]);
check(str_contains($clientWalletJobResp['body'], '← Back to Job Milestones'), 'Client back to job milestones link rendered');
check(str_contains($clientWalletJobResp['body'], '?route=/client/jobs/milestones&amp;job_id=2'), 'Client return link correct');

// -------------------------------------------------------------
// 12. Milestones View Rendering Verification
// -------------------------------------------------------------
// 12a. Client view under_review: shows Release Escrow Payment panel
$escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$job = [
    'id' => 2,
    'client_id' => 6,
    'title' => 'Cafe Ordering System development',
    'status' => 'under_review',
    'budget' => '5000.00',
    'awarded_bid_id' => 1,
];
$escrow = ['id' => 1, 'amount' => '4300.00', 'status' => 'locked'];
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

ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$clientUnderReviewHtml = (string)ob_get_clean();

check(str_contains($clientUnderReviewHtml, 'Release Escrow Payment &amp; Complete Project'), 'Client under_review shows Release Escrow heading');
check(str_contains($clientUnderReviewHtml, 'TechConsultant'), 'Client under_review displays freelancer name TechConsultant');
check(str_contains($clientUnderReviewHtml, 'RM 4,300.00'), 'Client under_review displays authoritative escrow RM 4,300.00');
check(str_contains($clientUnderReviewHtml, '?route=/client/jobs/escrow/release'), 'Client under_review has release form action');
check(str_contains($clientUnderReviewHtml, 'name="csrf_token" value="' . $escrowReleaseToken . '"'), 'Client under_review has escrow_release CSRF token');
check(str_contains($clientUnderReviewHtml, 'gh-release-panel'), 'Client under_review has gh-release-panel class');
check(str_contains($clientUnderReviewHtml, 'gh-release-meta-grid'), 'Client under_review has gh-release-meta-grid class');
check(str_contains($clientUnderReviewHtml, 'gh-release-form-wrap'), 'Client under_review has gh-release-form-wrap class');
check(str_contains($clientUnderReviewHtml, '← Back to Job Details'), 'Client under_review shows back nav to job details');

// 12b. Freelancer view under_review: shows Awaiting Escrow Settlement notice
$isClient = false;
$navigationUser = ['id' => 116, 'role' => 'freelancer', 'display_name' => 'TechConsultant'];
ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$freelancerUnderReviewHtml = (string)ob_get_clean();

check(str_contains($freelancerUnderReviewHtml, 'Final Work Deliverable Approved by Client!'), 'Freelancer under_review shows deliverable approved notice');
check(str_contains($freelancerUnderReviewHtml, 'awaiting financial escrow settlement'), 'Freelancer under_review notes awaiting escrow settlement');
check(!str_contains($freelancerUnderReviewHtml, '?route=/client/jobs/escrow/release'), 'Freelancer under_review does NOT have release form');
check(str_contains($freelancerUnderReviewHtml, '← Back to My Bids'), 'Freelancer under_review shows back nav to bids');

// 12c. Client view completed: shows Project Completed & Escrow Settled celebration
$isClient = true;
$job['status'] = 'completed';
$job['completed_at'] = '2026-11-05 14:00:00';
$navigationUser = ['id' => 6, 'role' => 'client', 'display_name' => 'CafeOwner'];
ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$clientCompletedHtml = (string)ob_get_clean();

check(str_contains($clientCompletedHtml, '✓ Project Completed &amp; Escrow Settled'), 'Client completed shows completion title');
check(str_contains($clientCompletedHtml, 'RM 4,300.00'), 'Client completed displays released amount RM 4,300.00');
check(str_contains($clientCompletedHtml, 'TechConsultant'), 'Client completed displays freelancer name');
check(str_contains($clientCompletedHtml, '?route=/client/wallet&amp;job_id=2'), 'Client completed provides link to wallet ledger with job_id');
check(!str_contains($clientCompletedHtml, '?route=/client/jobs/escrow/release'), 'Client completed does NOT render release form');
check(str_contains($clientCompletedHtml, 'Status:'), 'Client completed displays Status: label');
check(str_contains($clientCompletedHtml, 'Completed;'), 'Client completed displays Completed; with semicolon');
check(str_contains($clientCompletedHtml, 'Escrow Released:'), 'Client completed displays Escrow Released: label');
check(str_contains($clientCompletedHtml, 'RM 4,300.00;'), 'Client completed displays RM 4,300.00; with semicolon');
check(str_contains($clientCompletedHtml, 'Awarded Freelancer:'), 'Client completed displays Awarded Freelancer: label');
check(str_contains($clientCompletedHtml, 'TechConsultant;'), 'Client completed displays TechConsultant; with semicolon');
check(str_contains($clientCompletedHtml, 'gh-settlement-card'), 'Client completed has gh-settlement-card class');
check(str_contains($clientCompletedHtml, 'gh-settlement-meta-grid'), 'Client completed has gh-settlement-meta-grid class');
check(str_contains($clientCompletedHtml, 'gh-settlement-actions'), 'Client completed has gh-settlement-actions class');
check(str_contains($clientCompletedHtml, '← Back to Job Details'), 'Client completed shows back navigation to job details');
check(str_contains($clientCompletedHtml, 'Client Workspace'), 'Client completed shows workspace breadcrumb');

// 12d. Freelancer view completed: shows celebration card with link to /freelancer/wallet
$isClient = false;
$navigationUser = ['id' => 116, 'role' => 'freelancer', 'display_name' => 'TechConsultant'];
ob_start();
require dirname(__DIR__) . '/app/Views/client/milestones/index.php';
$freelancerCompletedHtml = (string)ob_get_clean();

check(str_contains($freelancerCompletedHtml, 'Project Completed — Escrow Payment Released!'), 'Freelancer completed shows celebration title');
check(str_contains($freelancerCompletedHtml, '+RM 4,300.00'), 'Freelancer completed displays credited amount +RM 4,300.00');
check(str_contains($freelancerCompletedHtml, '?route=/freelancer/wallet&amp;job_id=2'), 'Freelancer completed provides link to /freelancer/wallet with job_id');
check(str_contains($freelancerCompletedHtml, 'Status:'), 'Freelancer completed displays Status: label');
check(str_contains($freelancerCompletedHtml, 'Completed;'), 'Freelancer completed displays Completed; with semicolon');
check(str_contains($freelancerCompletedHtml, 'Payment Credited:'), 'Freelancer completed displays Payment Credited: label');
check(str_contains($freelancerCompletedHtml, '+RM 4,300.00;'), 'Freelancer completed displays +RM 4,300.00; with semicolon');
check(str_contains($freelancerCompletedHtml, 'gh-settlement-card'), 'Freelancer completed has gh-settlement-card class');
check(str_contains($freelancerCompletedHtml, 'gh-settlement-meta-grid'), 'Freelancer completed has gh-settlement-meta-grid class');
check(str_contains($freelancerCompletedHtml, 'gh-settlement-actions'), 'Freelancer completed has gh-settlement-actions class');
check(str_contains($freelancerCompletedHtml, '← Back to My Bids'), 'Freelancer completed shows back navigation to bids');
check(str_contains($freelancerCompletedHtml, 'Freelancer Workspace'), 'Freelancer completed shows workspace breadcrumb');

echo "PASS: {$count} settlement offline checks; no database connections.\n";
