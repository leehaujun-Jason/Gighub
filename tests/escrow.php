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
// Mock PDO & Repository
// -------------------------------------------------------------
class MockEscrowPDO extends PDO {
    public int $txLevel = 0;
    public array $events = [];
    public ?MockEscrowRepository $repo = null;
    public function __construct() {}
    public function beginTransaction(): bool {
        $this->events[] = 'tx:begin';
        ++$this->txLevel;
        $this->repo?->snapshot();
        return true;
    }
    public function commit(): bool { $this->events[] = 'tx:commit'; --$this->txLevel; return true; }
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
        if (str_starts_with($trimmed, 'SAVEPOINT')) {
            $this->repo?->snapshot();
        } elseif (str_starts_with($trimmed, 'ROLLBACK TO SAVEPOINT')) {
            $this->repo?->revert();
        }
        return 1;
    }
}

class MockEscrowRepository extends EscrowRepository {
    public array $events = [];
    public array $snapshots = [];
    public ?array $job = [
        'id' => 2,
        'client_id' => 6,
        'title' => 'Cafe Ordering System development',
        'status' => 'awarded',
        'budget' => '5000.00',
        'deadline' => '2027-01-31',
        'awarded_bid_id' => 1,
        'awarded_at' => '2026-11-02 14:57:42'
    ];
    public bool $isEligibleClient = true;
    public ?array $bid = [
        'id' => 1,
        'job_id' => 2,
        'freelancer_id' => 116,
        'freelancer_name' => 'Alice Dev',
        'amount' => '4300.00',
        'delivery_days' => 18,
        'status' => 'pending'
    ];
    public array $milestonesList = [
        [
            'id' => 2,
            'job_id' => 2,
            'title' => 'Frontend Table Layout & Ordering UI',
            'position' => 1,
            'due_date' => '2026-12-31',
            'status' => 'pending'
        ]
    ];
    public bool $hasEscrowRecord = false;
    public array $wallet = [
        'user_id' => 6,
        'balance' => '10000.00'
    ];
    public bool $simulateDebitFail = false;
    public bool $simulateLedgerFail = false;
    public array $escrowsCreated = [];
    public array $entriesCreated = [];
    public array $bidsRejected = [];

    public function __construct(PDO $pdo) { parent::__construct($pdo); }

    public function snapshot(): void {
        $this->snapshots[] = [
            'wallet' => $this->wallet,
            'job' => $this->job,
            'bid' => $this->bid,
            'hasEscrowRecord' => $this->hasEscrowRecord,
            'escrowsCreated' => $this->escrowsCreated,
            'entriesCreated' => $this->entriesCreated,
        ];
    }

    public function revert(): void {
        if ($this->snapshots !== []) {
            $state = array_pop($this->snapshots);
            $this->wallet = $state['wallet'];
            $this->job = $state['job'];
            $this->bid = $state['bid'];
            $this->hasEscrowRecord = $state['hasEscrowRecord'];
            $this->escrowsCreated = $state['escrowsCreated'];
            $this->entriesCreated = $state['entriesCreated'];
        }
    }

    public function job(int $jobId, bool $lock = false): array|false {
        $this->events[] = 'job:' . ($lock ? 'lock' : 'read');
        if ($this->job !== null && (int)$this->job['id'] === $jobId) {
            return $this->job;
        }
        return false;
    }

    public function eligibleClient(int $id, bool $lock = false): bool {
        $this->events[] = 'client:' . ($lock ? 'lock' : 'read');
        return $this->isEligibleClient;
    }

    public function awardedBid(int $jobId, int $bidId, bool $lock = false): array|false {
        $this->events[] = 'bid:' . ($lock ? 'lock' : 'read');
        if ($this->bid !== null && (int)$this->bid['id'] === $bidId && (int)$this->bid['job_id'] === $jobId) {
            return $this->bid;
        }
        return false;
    }

    public function milestones(int $jobId): array {
        $this->events[] = 'milestones:list';
        return $this->milestonesList;
    }

    public function milestoneCount(int $jobId): int {
        $this->events[] = 'milestone:count';
        return count($this->milestonesList);
    }

    public function hasEscrow(int $jobId, bool $lock = false): bool {
        $this->events[] = 'escrow:' . ($lock ? 'lock' : 'read');
        return $this->hasEscrowRecord;
    }

    public function getEscrow(int $jobId, bool $lock = false): array|false {
        return $this->hasEscrowRecord ? ($this->escrowsCreated[0] ?? ['id' => 1, 'job_id' => $jobId, 'amount' => '4300.00', 'status' => 'locked']) : false;
    }

    public function getWallet(int $userId, bool $lock = false): array|false {
        $this->events[] = 'wallet:' . ($lock ? 'lock' : 'read');
        return $this->wallet;
    }

    public function ensureWallet(int $userId): void {
        $this->events[] = 'wallet:ensure';
    }

    public function debitWallet(int $userId, string $amount): bool {
        $this->events[] = 'wallet:debit';
        if ($this->simulateDebitFail) return false;
        $current = (float)$this->wallet['balance'];
        $deduct = (float)$amount;
        if ($current < $deduct) return false;
        $this->wallet['balance'] = number_format($current - $deduct, 2, '.', '');
        return true;
    }

    public function createEscrow(int $jobId, int $bidId, string $amount): int {
        $this->events[] = 'escrow:create';
        $id = count($this->escrowsCreated) + 1;
        $this->escrowsCreated[] = [
            'id' => $id,
            'job_id' => $jobId,
            'bid_id' => $bidId,
            'amount' => $amount,
            'status' => 'locked'
        ];
        $this->hasEscrowRecord = true;
        return $id;
    }

    public function insertLedgerDebit(int $userId, int $escrowId, string $amountDelta, int $actorId, string $operationKey): int {
        $this->events[] = 'ledger:debit';
        if ($this->simulateLedgerFail) {
            throw new \PDOException('Duplicate entry for key uq_wallet_operation');
        }
        $id = count($this->entriesCreated) + 1;
        $this->entriesCreated[] = [
            'id' => $id,
            'wallet_user_id' => $userId,
            'escrow_id' => $escrowId,
            'amount_delta' => $amountDelta,
            'actor_id' => $actorId,
            'operation_key' => $operationKey
        ];
        return $id;
    }

    public function transitionJobToInProgress(int $jobId, int $bidId): bool {
        $this->events[] = 'job:in_progress';
        if ($this->job !== null && (int)$this->job['id'] === $jobId) {
            $this->job['status'] = 'in_progress';
            return true;
        }
        return false;
    }

    public function acceptWinningBid(int $jobId, int $bidId): bool {
        $this->events[] = 'bid:accept';
        if ($this->bid !== null && (int)$this->bid['id'] === $bidId) {
            $this->bid['status'] = 'accepted';
            return true;
        }
        return false;
    }

    public function rejectLosingBids(int $jobId, int $winningBidId): int {
        $this->events[] = 'bid:reject_losers';
        $this->bidsRejected[] = $jobId;
        return 1;
    }
}

// -------------------------------------------------------------
// Test Setup
// -------------------------------------------------------------
$pdo = new MockEscrowPDO();
$repo = new MockEscrowRepository($pdo);
$pdo->repo = $repo;
$service = new EscrowService($pdo, $repo);

// 1. Funding Details Preview Validation
$details = $service->getFundingDetails(6, 2);
check($details['job']['id'] === 2, 'funding details contains job 2');
check($details['bid']['id'] === 1, 'funding details contains bid 1');
check($details['balance'] === '10000.00', 'initial balance is 10000.00');
check($details['amount'] === '4300.00', 'exact winning bid amount is 4300.00');
check($details['remaining'] === '5700.00', 'remaining balance is 5700.00');
check($details['is_sufficient'] === true, 'balance is sufficient');

// 2. Ownership & Eligibility Errors
denied(fn() => $service->getFundingDetails(999, 2), 404, 'non-owner cannot preview funding');
denied(fn() => $service->fundJob(999, 2), 404, 'non-owner cannot fund job');

$repo->isEligibleClient = false;
denied(fn() => $service->getFundingDetails(6, 2), 403, 'ineligible client cannot preview funding');
denied(fn() => $service->fundJob(6, 2), 403, 'ineligible client cannot fund job');
$repo->isEligibleClient = true;

// 3. Job Status Validation
$repo->job['status'] = 'open';
denied(fn() => $service->getFundingDetails(6, 2), 409, 'open job cannot be funded');
denied(fn() => $service->fundJob(6, 2), 409, 'open job cannot be funded');

$repo->job['status'] = 'in_progress';
denied(fn() => $service->getFundingDetails(6, 2), 409, 'in_progress job cannot be funded');
$repo->job['status'] = 'awarded';

// 4. Milestone Check
$repo->milestonesList = [];
denied(fn() => $service->getFundingDetails(6, 2), 422, 'job with 0 milestones cannot be previewed');
denied(fn() => $service->fundJob(6, 2), 422, 'job with 0 milestones cannot be funded');
$repo->milestonesList = [
    ['id' => 2, 'job_id' => 2, 'title' => 'Frontend Table Layout & Ordering UI', 'position' => 1, 'due_date' => '2026-12-31', 'status' => 'pending']
];

// 5. Existing Escrow Check
$repo->hasEscrowRecord = true;
denied(fn() => $service->getFundingDetails(6, 2), 409, 'already funded job cannot preview');
denied(fn() => $service->fundJob(6, 2), 409, 'already funded job cannot fund again');
$repo->hasEscrowRecord = false;

// 6. Insufficient Wallet Balance Check
$repo->wallet['balance'] = '4299.99';
$details = $service->getFundingDetails(6, 2);
check($details['is_sufficient'] === false, 'preview identifies insufficient balance');
check($details['remaining'] === '0.00', 'insufficient balance defaults remaining to 0.00');
denied(fn() => $service->fundJob(6, 2), 422, 'insufficient wallet balance is rejected');
$repo->wallet['balance'] = '10000.00';

// 7. Exact Equal Balance Boundary Condition
$repo->wallet['balance'] = '4300.00';
$details = $service->getFundingDetails(6, 2);
check($details['is_sufficient'] === true, 'exact equal balance is sufficient');
check($details['remaining'] === '0.00', 'remaining balance after exact funding is 0.00');
$repo->wallet['balance'] = '10000.00';

// 8. Successful Atomic Funding & Lock Ordering Verification
$repo->events = [];
$pdo->events = [];
$res = $service->fundJob(6, 2);

check($res['escrow_id'] === 1, 'escrow ID returned is 1');
check($res['amount'] === '4300.00', 'funded amount is 4300.00');
check($repo->wallet['balance'] === '5700.00', 'client wallet debited to 5700.00');
check($repo->job['status'] === 'in_progress', 'job transitioned to in_progress');
check($repo->bid['status'] === 'accepted', 'winning bid transitioned to accepted');
check(count($repo->entriesCreated) === 1, 'exactly one ledger entry created');
check($repo->entriesCreated[0]['amount_delta'] === '-4300.00', 'ledger entry amount is -4300.00');
check($repo->entriesCreated[0]['operation_key'] === 'escrow_fund:job:2', 'operation key is escrow_fund:job:2');
check(count($repo->escrowsCreated) === 1, 'exactly one escrow created');
check($repo->escrowsCreated[0]['amount'] === '4300.00', 'escrow amount is 4300.00');
check($repo->escrowsCreated[0]['status'] === 'locked', 'escrow status is locked');

// Verify Strict Lock Sequence
$expectedLocks = [
    'job:lock',
    'client:lock',
    'bid:lock',
    'escrow:lock',
    'milestone:count',
    'wallet:ensure',
    'wallet:lock',
    'wallet:debit',
    'escrow:create',
    'ledger:debit',
    'job:in_progress',
    'bid:accept',
    'bid:reject_losers'
];
check($repo->events === $expectedLocks, 'strict lock ordering verified: ' . implode(' -> ', $repo->events));
check($pdo->events === ['tx:begin', 'tx:commit'], 'single outer transaction opened and committed');

// 9. Repeated Funding / Re-entry Prevention
denied(fn() => $service->fundJob(6, 2), 409, 'repeated funding on now-funded job is rejected with 409');

// 10. Savepoint Discipline in Nested Transaction
$repo->hasEscrowRecord = false;
$repo->job['status'] = 'awarded';
$repo->bid['status'] = 'pending';
$repo->wallet['balance'] = '10000.00';
$pdo->txLevel = 1; // Simulate outer transaction already open
$pdo->events = [];
$repo->events = [];

$res = $service->fundJob(6, 2);
check($res['amount'] === '4300.00', 'nested funding succeeded');
check(in_array('exec:SAVEPOINT gighub_escrow_fund', $pdo->events, true), 'savepoint created in nested tx');
check(in_array('exec:RELEASE SAVEPOINT gighub_escrow_fund', $pdo->events, true), 'savepoint released on success');
check(!in_array('tx:commit', $pdo->events, true), 'outer transaction preserved without premature commit');
$pdo->txLevel = 0;

// 11. Transaction Rollback & Error Masking
$repo->hasEscrowRecord = false;
$repo->job['status'] = 'awarded';
$repo->bid['status'] = 'pending';
$repo->wallet['balance'] = '10000.00';
$repo->simulateLedgerFail = true; // Simulate database error
$pdo->events = [];

try {
    $service->fundJob(6, 2);
    check(false, 'ledger failure must throw');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'Escrow funding is temporarily unavailable.', 'database error masked with 503 generic message');
}
check(in_array('tx:rollback', $pdo->events, true), 'transaction rolled back on failure');
check($repo->wallet['balance'] === '10000.00', 'wallet balance unchanged after rollback');
$repo->simulateLedgerFail = false;

// -------------------------------------------------------------
// Controller Tests (RBAC, CSRF, PRG)
// -------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mockUsers = new class extends UserRepository {
    public array $row = ['id' => 6, 'role' => 'client', 'display_name' => 'Test Client', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false { return $this->row; }
};

$_SESSION = [];
$authSession = new AuthSession(null, static fn() => true);
$auth = new AuthenticationService($mockUsers);
$guard = new AuthGuard(static fn() => $auth, $authSession);
$controller = new EscrowController($guard, static fn() => $service);

// 12. Unauthenticated Access
$authSession->forget();
$res = $controller->preview(['job_id' => 2]);
check($res['status'] === 303 && str_contains($res['location'], '/login'), 'unauthenticated preview redirects to login');

$res = $controller->confirm(['job_id' => 2, 'csrf_token' => 'x']);
check($res['status'] === 303 && str_contains($res['location'], '/login'), 'unauthenticated confirm redirects to login');

// 13. Role-Based Access Control (Freelancer Denied)
$authSession->establish(116);
$mockUsers->row = ['id' => 116, 'role' => 'freelancer', 'display_name' => 'Freelancer Alice', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];

$res = $controller->preview(['job_id' => 2]);
check($res['status'] === 403, 'freelancer cannot access funding preview (403)');

$res = $controller->confirm(['job_id' => 2, 'csrf_token' => 'x']);
check($res['status'] === 403, 'freelancer cannot confirm funding (403)');

// 14. Authenticated Client - Preview Rendering
$authSession->establish(6);
$mockUsers->row = ['id' => 6, 'role' => 'client', 'display_name' => 'Test Client', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];

$repo->hasEscrowRecord = false;
$repo->job['status'] = 'awarded';
$repo->bid['status'] = 'pending';
$repo->wallet['balance'] = '10000.00';

$res = $controller->preview(['job_id' => 2]);
check($res['status'] === 200, 'authenticated client receives 200 preview');
check(str_contains($res['body'], 'Fund Escrow for Cafe Ordering System development'), 'preview contains job title');
check(str_contains($res['body'], 'RM4,300.00'), 'preview contains agreed bid amount RM4,300.00');
check(str_contains($res['body'], 'RM10,000.00'), 'preview contains wallet balance RM10,000.00');
check(str_contains($res['body'], 'RM5,700.00'), 'preview contains remaining balance RM5,700.00');
check(str_contains($res['body'], 'Simulated Demonstration Environment'), 'preview contains simulation notice');

// 15. Invalid CSRF on Confirm
$res = $controller->confirm(['job_id' => 2, 'csrf_token' => 'invalid_token']);
check($res['status'] === 403, 'invalid CSRF rejected with 403');

// 16. Invalid Job ID on Confirm
Csrf::rotate('escrow_fund');
$validToken = Csrf::token('escrow_fund');
$res = $controller->confirm(['job_id' => 'abc', 'csrf_token' => $validToken]);
check($res['status'] === 400, 'invalid job ID rejected with 400');

// 17. Successful Post/Redirect/Get (PRG) Confirmation
Csrf::rotate('escrow_fund');
$validToken = Csrf::token('escrow_fund');
$res = $controller->confirm(['job_id' => 2, 'csrf_token' => $validToken]);
check($res['status'] === 303, 'successful funding returns 303 PRG redirect');
check($res['location'] === '?route=/client/jobs/show&id=2', 'redirects to client jobs show');
check(str_contains($_SESSION['job_posting_notice'], 'RM4,300.00 has been locked in escrow'), 'session notice reflects funded RM4,300.00');

// 18. Prevention on Double Submit
Csrf::rotate('escrow_fund');
$validToken = Csrf::token('escrow_fund');
$res = $controller->confirm(['job_id' => 2, 'csrf_token' => $validToken]);
check($res['status'] === 303 && str_contains($res['location'], '/client/jobs/fund&job_id=2'), 'subsequent submission redirects with error');
check(str_contains($_SESSION['escrow_fund_error'], 'Only awarded jobs awaiting funding can be funded.'), 'error message confirms non-awarded job cannot be funded again');

echo "PASS: $count escrow offline checks; no database connections.\n";
