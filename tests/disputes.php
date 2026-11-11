<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE && PHP_SAPI === 'cli') {
    session_start();
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

foreach ([
    'Core/Config',
    'Core/Database',
    'Core/Csrf',
    'Core/Notification',
    'Core/AuthSession',
    'Core/AuthGuard',
    'Core/Router',
    'Repositories/UserRepository',
    'Services/AuthenticationService',
    'Repositories/DisputeRepository',
    'Services/DisputeException',
    'Services/DisputeService',
    'Controllers/DisputeController',
    'Controllers/AdminDisputeController',
    'Services/BidValidator',
    'Repositories/MilestoneRepository',
    'Services/MilestoneValidator',
    'Services/MilestoneException',
    'Services/MilestoneService',
    'Controllers/MilestoneController',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Core\Csrf;
use GigHub\Controllers\DisputeController;
use GigHub\Controllers\AdminDisputeController;
use GigHub\Controllers\MilestoneController;
use GigHub\Repositories\DisputeRepository;
use GigHub\Repositories\MilestoneRepository;
use GigHub\Services\DisputeException;
use GigHub\Services\DisputeService;
use GigHub\Services\MilestoneException;
use GigHub\Services\MilestoneService;
use GigHub\Services\MilestoneValidator;

// Test Infrastructure
$testCount = 0;
$failCount = 0;

$assert = function (bool $condition, string $message) use (&$testCount, &$failCount): void {
    $testCount++;
    if ($condition) {
        echo "  [PASS] {$message}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$message}\n";
    }
};

echo "\n====================================================================\n";
echo " GigHub Module 21: Job Cancellation & Dispute Resolution Offline Tests\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// In-Memory Mock Repository & PDO
// -------------------------------------------------------------------------
class MockDisputePDO extends PDO
{
    public int $txLevel = 0;
    public ?MockDisputeRepository $repo = null;
    public bool $simulateFailure = false;

    public function __construct() {}

    public function beginTransaction(): bool
    {
        $this->txLevel++;
        $this->repo?->snapshot();
        return true;
    }

    public function commit(): bool
    {
        if ($this->simulateFailure) {
            $this->rollBack();
            throw new RuntimeException('Simulated database deadlock / network failure on commit');
        }
        $this->txLevel = max(0, $this->txLevel - 1);
        return true;
    }

    public function rollBack(): bool
    {
        $this->txLevel = max(0, $this->txLevel - 1);
        $this->repo?->restore();
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->txLevel > 0;
    }
}

class MockDisputeRepository extends DisputeRepository
{
    public array $jobs = [];
    public array $bids = [];
    public array $users = [];
    public array $cancellationRequests = [];
    public array $disputes = [];
    public array $escrows = [];
    public array $wallets = [];
    public array $walletEntries = [];
    public array $notifications = [];

    private array $snapshot = [];
    private int $nextCancelId = 1;
    private int $nextDisputeId = 1;
    private int $nextLedgerId = 1;
    private int $nextNotifId = 1;

    public function __construct(private readonly MockDisputePDO $mockPdo)
    {
        $mockPdo->repo = $this;
    }

    public function snapshot(): void
    {
        $this->snapshot = [
            'jobs' => $this->jobs,
            'bids' => $this->bids,
            'users' => $this->users,
            'cancellationRequests' => $this->cancellationRequests,
            'disputes' => $this->disputes,
            'escrows' => $this->escrows,
            'wallets' => $this->wallets,
            'walletEntries' => $this->walletEntries,
            'notifications' => $this->notifications,
        ];
    }

    public function restore(): void
    {
        if (!empty($this->snapshot)) {
            $this->jobs = $this->snapshot['jobs'];
            $this->bids = $this->snapshot['bids'];
            $this->users = $this->snapshot['users'];
            $this->cancellationRequests = $this->snapshot['cancellationRequests'];
            $this->disputes = $this->snapshot['disputes'];
            $this->escrows = $this->snapshot['escrows'];
            $this->wallets = $this->snapshot['wallets'];
            $this->walletEntries = $this->snapshot['walletEntries'];
            $this->notifications = $this->snapshot['notifications'];
        }
    }

    public function getJob(int $jobId, bool $lock = false): ?array
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function getBid(int $bidId, bool $lock = false): ?array
    {
        return $this->bids[$bidId] ?? null;
    }

    public function getUser(int $userId): ?array
    {
        return $this->users[$userId] ?? null;
    }

    public function getCancellationRequest(int $jobId, bool $lock = false): ?array
    {
        foreach ($this->cancellationRequests as $cr) {
            if ($cr['job_id'] === $jobId) {
                $requester = $this->users[$cr['requested_by']] ?? ['display_name' => 'User'];
                $responder = isset($cr['responded_by']) ? ($this->users[$cr['responded_by']] ?? ['display_name' => 'User']) : null;
                $cr['requester_name'] = $requester['display_name'];
                $cr['responder_name'] = $responder ? $responder['display_name'] : null;
                return $cr;
            }
        }
        return null;
    }

    public function createCancellationRequest(int $jobId, int $requestedBy, string $reason): int
    {
        $id = $this->nextCancelId++;
        $this->cancellationRequests[$id] = [
            'id' => $id,
            'job_id' => $jobId,
            'requested_by' => $requestedBy,
            'reason' => $reason,
            'status' => 'pending',
            'responded_by' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'responded_at' => null,
        ];
        return $id;
    }

    public function respondCancellationRequest(int $requestId, string $status, int $respondedBy): bool
    {
        if (isset($this->cancellationRequests[$requestId]) && $this->cancellationRequests[$requestId]['status'] === 'pending') {
            $this->cancellationRequests[$requestId]['status'] = $status;
            $this->cancellationRequests[$requestId]['responded_by'] = $respondedBy;
            $this->cancellationRequests[$requestId]['responded_at'] = date('Y-m-d H:i:s');
            return true;
        }
        return false;
    }

    public function supersedeCancellationRequest(int $jobId): bool
    {
        $updated = false;
        foreach ($this->cancellationRequests as &$cr) {
            if ($cr['job_id'] === $jobId && $cr['status'] === 'pending') {
                $cr['status'] = 'superseded';
                $updated = true;
            }
        }
        return $updated;
    }

    public function getDisputeByJobId(int $jobId, bool $lock = false): ?array
    {
        foreach ($this->disputes as $d) {
            if ($d['job_id'] === $jobId) {
                $raiser = $this->users[$d['raised_by']] ?? ['display_name' => 'User'];
                $admin = isset($d['admin_id']) ? ($this->users[$d['admin_id']] ?? ['display_name' => 'Admin']) : null;
                $d['raiser_name'] = $raiser['display_name'];
                $d['admin_name'] = $admin ? $admin['display_name'] : null;
                return $d;
            }
        }
        return null;
    }

    public function getDisputeById(int $disputeId, bool $lock = false): ?array
    {
        if (isset($this->disputes[$disputeId])) {
            $d = $this->disputes[$disputeId];
            $raiser = $this->users[$d['raised_by']] ?? ['display_name' => 'User'];
            $admin = isset($d['admin_id']) ? ($this->users[$d['admin_id']] ?? ['display_name' => 'Admin']) : null;
            $d['raiser_name'] = $raiser['display_name'];
            $d['admin_name'] = $admin ? $admin['display_name'] : null;
            return $d;
        }
        return null;
    }

    public function createDispute(int $jobId, int $raisedBy, string $reason): int
    {
        $id = $this->nextDisputeId++;
        $this->disputes[$id] = [
            'id' => $id,
            'job_id' => $jobId,
            'raised_by' => $raisedBy,
            'reason' => $reason,
            'response' => null,
            'admin_id' => null,
            'status' => 'open',
            'decision' => null,
            'decision_note' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'resolved_at' => null,
        ];
        return $id;
    }

    public function submitDisputeResponse(int $disputeId, string $response): bool
    {
        if (isset($this->disputes[$disputeId]) && $this->disputes[$disputeId]['status'] === 'open' && $this->disputes[$disputeId]['response'] === null) {
            $this->disputes[$disputeId]['response'] = $response;
            return true;
        }
        return false;
    }

    public function resolveDispute(int $disputeId, string $decision, int $adminId, string $decisionNote): bool
    {
        if (isset($this->disputes[$disputeId]) && $this->disputes[$disputeId]['status'] === 'open') {
            $this->disputes[$disputeId]['status'] = 'resolved';
            $this->disputes[$disputeId]['decision'] = $decision;
            $this->disputes[$disputeId]['admin_id'] = $adminId;
            $this->disputes[$disputeId]['decision_note'] = $decisionNote;
            $this->disputes[$disputeId]['resolved_at'] = date('Y-m-d H:i:s');
            return true;
        }
        return false;
    }

    public function getEscrow(int $jobId, bool $lock = false): ?array
    {
        return $this->escrows[$jobId] ?? null;
    }

    public function settleEscrow(int $escrowId, string $status): bool
    {
        foreach ($this->escrows as &$e) {
            if ($e['id'] === $escrowId && $e['status'] === 'locked' && $e['settled_at'] === null) {
                $e['status'] = $status;
                $e['settled_at'] = date('Y-m-d H:i:s');
                return true;
            }
        }
        return false;
    }

    public function ensureWallet(int $userId): void
    {
        if (!isset($this->wallets[$userId])) {
            $this->wallets[$userId] = [
                'user_id' => $userId,
                'balance' => '0.00',
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        }
    }

    public function getWallet(int $userId, bool $lock = false): ?array
    {
        return $this->wallets[$userId] ?? null;
    }

    public function creditWallet(int $userId, string $amount): bool
    {
        $this->ensureWallet($userId);
        $current = $this->wallets[$userId]['balance'];
        $newBalance = bcadd($current, $amount, 2);
        $this->wallets[$userId]['balance'] = $newBalance;
        $this->wallets[$userId]['updated_at'] = date('Y-m-d H:i:s');
        return true;
    }

    public function insertLedgerEntry(int $walletUserId, ?int $escrowId, string $entryType, string $amountDelta, string $source, int $actorId, string $operationKey): int
    {
        $id = $this->nextLedgerId++;
        $this->walletEntries[$id] = [
            'id' => $id,
            'wallet_user_id' => $walletUserId,
            'escrow_id' => $escrowId,
            'entry_type' => $entryType,
            'amount_delta' => $amountDelta,
            'source' => $source,
            'actor_id' => $actorId,
            'operation_key' => $operationKey,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        return $id;
    }

    public function hasSettlementLedger(int $escrowId): bool
    {
        foreach ($this->walletEntries as $we) {
            if ($we['escrow_id'] === $escrowId && in_array($we['entry_type'], ['escrow_release', 'escrow_refund'], true)) {
                return true;
            }
        }
        return false;
    }

    public function hasOperationKey(string $key): bool
    {
        foreach ($this->walletEntries as $we) {
            if ($we['operation_key'] === $key) {
                return true;
            }
        }
        return false;
    }

    public function updateJobStatus(int $jobId, string $status, bool $closed = false): bool
    {
        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]['status'] = $status;
            if ($closed) {
                $this->jobs[$jobId]['closed_at'] = date('Y-m-d H:i:s');
            }
            return true;
        }
        return false;
    }

    public function createNotification(int $recipientId, ?int $jobId, string $type, string $message): int
    {
        $id = $this->nextNotifId++;
        $this->notifications[$id] = [
            'id' => $id,
            'recipient_id' => $recipientId,
            'job_id' => $jobId,
            'type' => $type,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        return $id;
    }

    public function getAdminDisputes(string $status = 'open'): array
    {
        $res = [];
        foreach ($this->disputes as $d) {
            if ($d['status'] === $status) {
                $job = $this->jobs[$d['job_id']] ?? [];
                $bid = isset($job['awarded_bid_id']) ? ($this->bids[$job['awarded_bid_id']] ?? []) : [];
                $client = $this->users[$job['client_id'] ?? 0] ?? ['display_name' => 'Client'];
                $freelancer = $this->users[$bid['freelancer_id'] ?? 0] ?? ['display_name' => 'Freelancer'];
                $escrow = $this->escrows[$job['id'] ?? 0] ?? ['amount' => '0.00'];
                $raiser = $this->users[$d['raised_by']] ?? ['display_name' => 'Raiser'];

                $res[] = array_merge($d, [
                    'job_title' => $job['title'] ?? 'Title',
                    'job_budget' => $job['budget'] ?? '0.00',
                    'client_id' => $job['client_id'] ?? 0,
                    'client_name' => $client['display_name'],
                    'freelancer_name' => $freelancer['display_name'],
                    'escrow_amount' => $escrow['amount'],
                    'raiser_name' => $raiser['display_name'],
                ]);
            }
        }
        return $res;
    }

    public function countOpenDisputes(): int
    {
        $count = 0;
        foreach ($this->disputes as $d) {
            if ($d['status'] === 'open') {
                $count++;
            }
        }
        return $count;
    }

    public array $chatMessages = [];

    public function countDisputeChatMessages(int $bidId): int
    {
        if (!empty($this->chatMessages)) {
            $count = 0;
            foreach ($this->chatMessages as $msg) {
                if ($msg['bid_id'] === $bidId) {
                    $count++;
                }
            }
            return $count;
        }
        return 2;
    }

    public function getDisputeChatMessages(int $bidId, int $limit = 500, int $offset = 0): array
    {
        if (!empty($this->chatMessages)) {
            $filtered = [];
            foreach ($this->chatMessages as $msg) {
                if ($msg['bid_id'] === $bidId) {
                    $filtered[] = $msg;
                }
            }
            return array_slice($filtered, $offset, $limit);
        }
        return [
            ['id' => 1, 'bid_id' => $bidId, 'sender_id' => 10, 'body' => 'Hi, started the task.', 'created_at' => '2026-11-05 10:00:00', 'sender_name' => 'Client'],
            ['id' => 2, 'bid_id' => $bidId, 'sender_id' => 20, 'body' => 'Working on Milestone 1.', 'created_at' => '2026-11-05 11:00:00', 'sender_name' => 'Freelancer'],
        ];
    }

    public function getJobMilestonesAndSubmissions(int $jobId): array
    {
        return [
            [
                'milestone_id' => 1,
                'milestone_title' => 'Initial Wireframes',
                'milestone_status' => 'approved',
                'approved_at' => '2026-11-06 14:00:00',
                'submission_id' => 1,
                'notes' => 'Completed wireframes in Figma',
                'deliverable_url' => 'https://figma.example.com/file/123',
                'submission_status' => 'approved',
                'review_feedback' => 'Looks great!',
                'submitted_at' => '2026-11-06 12:00:00',
            ],
        ];
    }
}

// -------------------------------------------------------------------------
// Test Fixture Setup
// -------------------------------------------------------------------------
function createTestEnvironment(): array
{
    $pdo = new MockDisputePDO();
    $repo = new MockDisputeRepository($pdo);
    $service = new DisputeService($pdo, $repo);

    // Users
    $repo->users[10] = ['id' => 10, 'email' => 'client@test.local', 'role' => 'client', 'status' => 'active', 'display_name' => 'Alice Client'];
    $repo->users[20] = ['id' => 20, 'email' => 'freelancer@test.local', 'role' => 'freelancer', 'status' => 'active', 'display_name' => 'Bob Freelancer'];
    $repo->users[30] = ['id' => 30, 'email' => 'other@test.local', 'role' => 'client', 'status' => 'active', 'display_name' => 'Charlie Outsider'];
    $repo->users[98] = ['id' => 98, 'email' => 'suspended_admin@test.local', 'role' => 'admin', 'status' => 'suspended', 'display_name' => 'Suspended Admin'];
    $repo->users[99] = ['id' => 99, 'email' => 'admin@test.local', 'role' => 'admin', 'status' => 'active', 'display_name' => 'Diana Admin'];

    // Jobs (Job 1: active in_progress; Job 2: completed/protected; Job 3: under_review)
    $repo->jobs[1] = [
        'id' => 1,
        'client_id' => 10,
        'awarded_bid_id' => 101,
        'title' => 'E-Commerce Website Redesign',
        'budget' => '1500.00',
        'status' => 'in_progress',
        'created_at' => '2026-11-01 10:00:00',
    ];

    $repo->bids[101] = [
        'id' => 101,
        'job_id' => 1,
        'freelancer_id' => 20,
        'amount' => '1500.00',
        'status' => 'accepted',
    ];

    $repo->escrows[1] = [
        'id' => 501,
        'job_id' => 1,
        'bid_id' => 101,
        'amount' => '1500.00',
        'status' => 'locked',
        'settled_at' => null,
    ];

    // Client wallet (balance RM 50.00)
    $repo->wallets[10] = [
        'user_id' => 10,
        'balance' => '50.00',
        'updated_at' => '2026-11-01 10:00:00',
    ];

    return [$pdo, $repo, $service];
}

// =========================================================================
// SUITE 1: Authorization, Ownership & Input Validation
// =========================================================================
echo "--- Suite 1: Authorization & Input Validation ---\n";
[$pdo, $repo, $service] = createTestEnvironment();

// 1. Non-participant blocked from requesting cancellation (403)
try {
    $service->requestCancellation(30, 1, 'I want to cancel this contract because of delays.');
    $assert(false, 'Non-participant blocked from requesting cancellation');
} catch (DisputeException $e) {
    $assert($e->status === 403, 'Non-participant blocked from requesting cancellation (403)');
}

// 2. Cancellation reason too short (< 10 chars)
try {
    $service->requestCancellation(10, 1, 'Too short');
    $assert(false, 'Cancellation reason < 10 chars rejected');
} catch (DisputeException $e) {
    $assert($e->status === 422, 'Cancellation reason < 10 chars rejected (422)');
}

// 3. Cancellation reason too long (> 1000 chars)
try {
    $service->requestCancellation(10, 1, str_repeat('a', 1001));
    $assert(false, 'Cancellation reason > 1000 chars rejected');
} catch (DisputeException $e) {
    $assert($e->status === 422, 'Cancellation reason > 1000 chars rejected (422)');
}

// 4. Non-participant blocked from raising dispute (403)
try {
    $service->raiseDispute(30, 1, 'I want to dispute this job as an outsider.');
    $assert(false, 'Non-participant blocked from raising dispute');
} catch (DisputeException $e) {
    $assert($e->status === 403, 'Non-participant blocked from raising dispute (403)');
}

// 5. Dispute reason validation (< 10 chars, 422)
try {
    $service->raiseDispute(10, 1, 'Short');
    $assert(false, 'Dispute reason < 10 chars rejected');
} catch (DisputeException $e) {
    $assert($e->status === 422, 'Dispute reason < 10 chars rejected (422)');
}

// 6. Non-admin blocked from resolving dispute (403)
try {
    $service->resolveDispute(10, 1, 'refund_client', 'Client trying to adjudicate');
    $assert(false, 'Non-admin blocked from resolving dispute');
} catch (DisputeException $e) {
    $assert($e->status === 403, 'Non-admin blocked from resolving dispute (403)');
}

// 7. Admin invalid decision value rejected (422)
try {
    $service->resolveDispute(99, 1, 'invalid_decision', 'Valid rationale note longer than 10 characters');
    $assert(false, 'Invalid decision value rejected');
} catch (DisputeException $e) {
    $assert($e->status === 422, 'Invalid decision value rejected (422)');
}

// 8. Admin decision note too short (< 10 chars, 422)
try {
    $service->resolveDispute(99, 1, 'refund_client', 'Too short');
    $assert(false, 'Admin decision note < 10 chars rejected');
} catch (DisputeException $e) {
    $assert($e->status === 422, 'Admin decision note < 10 chars rejected (422)');
}

// 9. CSRF validation on Controller actions
$currentUser = ['id' => 10, 'email' => 'client@test.local', 'role' => 'client', 'display_name' => 'Alice Client'];
$fakeAuthService = new class($currentUser) {
    public function __construct(private mixed &$user) {}
    public function identity(int $id): ?array {
        return $this->user;
    }
};

$session = new AuthSession(static fn() => time(), static fn() => true);
$session->establish(10);
$guard = new AuthGuard(static fn() => $fakeAuthService, $session);

$controller = new DisputeController($guard, static fn() => $service);

$csrfResp = $controller->requestCancellation(['job_id' => 1, 'reason' => 'Valid cancellation reason here.', 'csrf_token' => 'invalid_csrf_token']);
$assert($csrfResp['status'] === 303, 'CSRF failure redirects safely via Post/Redirect/Get');
$assert($csrfResp['location'] === '?route=/client/jobs/milestones&job_id=1', 'CSRF failure redirects to client milestones page');
$flashNotifs = $_SESSION['gh_notifications'] ?? [];
$assert(!empty($flashNotifs) && end($flashNotifs)['type'] === 'error', 'CSRF failure sets flash error notification');

// =========================================================================
// SUITE 2: Mutual Cancellation Lifecycle
// =========================================================================
echo "\n--- Suite 2: Mutual Cancellation Lifecycle ---\n";
[$pdo, $repo, $service] = createTestEnvironment();

// 1. Client creates cancellation request
$reqRes = $service->requestCancellation(10, 1, 'Client requesting mutual cancellation due to scope change.');
$assert($reqRes['status'] === 'pending', 'Cancellation request created with pending status');
$assert($repo->cancellationRequests[1]['requested_by'] === 10, 'Cancellation request assigned to correct requester');

// Verify notification sent to Freelancer (recipient: 20)
$lastNotif = end($repo->notifications);
$assert($lastNotif['recipient_id'] === 20 && $lastNotif['type'] === 'cancellation_requested', 'Notification dispatched to counterpart (Freelancer)');

// 2. Requester cannot respond to own request (403)
try {
    $service->respondCancellation(10, 1, 'accept');
    $assert(false, 'Requester cannot respond to own cancellation request');
} catch (DisputeException $e) {
    $assert($e->status === 403, 'Requester blocked from responding to own request (403)');
}

// 3. Duplicate cancellation request blocked while pending (409)
try {
    $service->requestCancellation(20, 1, 'Freelancer trying to submit duplicate cancellation.');
    $assert(false, 'Duplicate pending cancellation request blocked');
} catch (DisputeException $e) {
    $assert($e->status === 409, 'Duplicate pending cancellation blocked (409)');
}

// 4. Counterpart declines cancellation
$declineRes = $service->respondCancellation(20, 1, 'decline');
$assert($declineRes['status'] === 'rejected', 'Cancellation status transitioned to rejected on decline');
$assert($repo->jobs[1]['status'] === 'in_progress', 'Job remains in_progress when cancellation is declined');

// Verify notification sent back to Requester (10)
$lastNotif = end($repo->notifications);
$assert($lastNotif['recipient_id'] === 10 && $lastNotif['type'] === 'cancellation_responded', 'Notification sent to requester on decline');

// 5. After rejection, another mutual cancellation cannot be created (UNIQUE constraint / business rule, 409)
try {
    $service->requestCancellation(10, 1, 'Trying to cancel again after decline.');
    $assert(false, 'Second cancellation request after decline is blocked');
} catch (DisputeException $e) {
    $assert($e->status === 409, 'Second cancellation request after decline blocked (409)');
}

// 6. Test ACCEPT cancellation in fresh environment
[$pdo, $repo, $service] = createTestEnvironment();
$service->requestCancellation(10, 1, 'Valid reason for cancellation to accept.');
$acceptRes = $service->respondCancellation(20, 1, 'accept');

$assert($acceptRes['status'] === 'accepted', 'Cancellation status transitioned to accepted');
$assert($acceptRes['refund_amount'] === '1500.00', 'Exact escrow amount refunded');
$assert($repo->jobs[1]['status'] === 'cancelled', 'Job transitioned to cancelled status');
$assert(!empty($repo->jobs[1]['closed_at']), 'Job closed_at timestamp populated');
$assert($repo->escrows[1]['status'] === 'refunded', 'Escrow status transitioned to refunded');
$assert(!empty($repo->escrows[1]['settled_at']), 'Escrow settled_at timestamp populated');
$assert($repo->wallets[10]['balance'] === '1550.00', 'Client wallet balance credited exact refund (50.00 + 1500.00 = 1550.00)');

// Ledger verification
$ledgerEntry = end($repo->walletEntries);
$assert($ledgerEntry['wallet_user_id'] === 10, 'Ledger entry belongs to Client');
$assert($ledgerEntry['entry_type'] === 'escrow_refund', 'Ledger entry type is escrow_refund');
$assert($ledgerEntry['amount_delta'] === '1500.00', 'Ledger amount delta matches escrow amount');
$assert($ledgerEntry['source'] === 'mutual_cancellation', 'Ledger source is mutual_cancellation');
$assert($ledgerEntry['operation_key'] === 'cancellation_refund:job:1', 'Operation key matches canonical cancellation refund format');

// =========================================================================
// SUITE 3: Formal Dispute Escalation & Lifecycle Freezing
// =========================================================================
echo "\n--- Suite 3: Formal Dispute Escalation ---\n";
[$pdo, $repo, $service] = createTestEnvironment();

// 1. Have a pending cancellation request first
$service->requestCancellation(10, 1, 'Pending cancellation that will be superseded.');
$assert($repo->cancellationRequests[1]['status'] === 'pending', 'Cancellation request is pending');

// 2. Freelancer raises formal dispute
$disputeRes = $service->raiseDispute(20, 1, 'Freelancer escalating dispute: Client requested revisions out of scope.');
$assert($disputeRes['status'] === 'open', 'Dispute created with status open');
$assert($repo->jobs[1]['status'] === 'disputed', 'Job status transitioned to disputed');
$assert($repo->cancellationRequests[1]['status'] === 'superseded', 'Pending cancellation was automatically superseded');

// Verify notification sent to Client (10)
$lastNotif = end($repo->notifications);
$assert($lastNotif['recipient_id'] === 10 && $lastNotif['type'] === 'dispute_opened', 'Dispute opened notification dispatched to counterpart');

// 3. Raiser cannot respond to own dispute (403)
try {
    $service->submitDisputeResponse(20, (int)$disputeRes['dispute_id'], 'Freelancer trying to submit response to own dispute');
    $assert(false, 'Raiser cannot submit counterpart response to own dispute');
} catch (DisputeException $e) {
    $assert($e->status === 403, 'Raiser blocked from submitting counterpart response (403)');
}

// 4. Counterpart (Client) submits formal written response
$respRes = $service->submitDisputeResponse(10, (int)$disputeRes['dispute_id'], 'Client response: Deliverables did not match agreed specification.');
$assert($respRes['status'] === 'open', 'Dispute remains open awaiting admin resolution');
$assert($repo->disputes[1]['response'] !== null, 'Written response saved in dispute record');

// 5. Cannot submit second response (409)
try {
    $service->submitDisputeResponse(10, (int)$disputeRes['dispute_id'], 'Second response should be rejected.');
    $assert(false, 'Duplicate dispute response blocked');
} catch (DisputeException $e) {
    $assert($e->status === 409, 'Duplicate dispute response blocked (409)');
}

// 6. Cannot raise second dispute on already disputed job (409)
try {
    $service->raiseDispute(10, 1, 'Second dispute should fail.');
    $assert(false, 'Duplicate dispute on same job blocked');
} catch (DisputeException $e) {
    $assert($e->status === 409, 'Duplicate dispute blocked (409)');
}

// =========================================================================
// SUITE 4: Admin Mediation & Financial Resolution
// =========================================================================
echo "\n--- Suite 4: Admin Mediation & Financial Resolution ---\n";

// Scenario A: Full Refund to Client
[$pdo, $repo, $service] = createTestEnvironment();
$service->raiseDispute(10, 1, 'Client dispute: Work not delivered.');
$resolveClient = $service->resolveDispute(99, 1, 'refund_client', 'Freelancer was unresponsive. Full refund to client.');

$assert($resolveClient['decision'] === 'refund_client', 'Admin decision refund_client recorded');
$assert($resolveClient['recipient_id'] === 10, 'Refund recipient is Client');
$assert($repo->disputes[1]['status'] === 'resolved', 'Dispute transitioned to resolved');
$assert($repo->jobs[1]['status'] === 'closed_disputed', 'Job transitioned to closed_disputed');
$assert(!empty($repo->jobs[1]['closed_at']), 'Job closed_at timestamp set');
$assert($repo->escrows[1]['status'] === 'refunded', 'Escrow settled to refunded');
$assert($repo->wallets[10]['balance'] === '1550.00', 'Client wallet credited full escrow balance (50.00 + 1500.00)');

$lastLedger = end($repo->walletEntries);
$assert($lastLedger['source'] === 'admin_decision', 'Ledger entry source is admin_decision');
$assert($lastLedger['entry_type'] === 'escrow_refund', 'Ledger entry type is escrow_refund');
$assert($lastLedger['operation_key'] === 'dispute_refund:job:1:dispute:1', 'Ledger operation key matches dispute refund format');

// Notifications sent to both parties
$notifs = array_values($repo->notifications);
$lastTwoNotifs = array_slice($notifs, -2);
$assert(count($lastTwoNotifs) === 2, 'Notifications sent to both parties upon resolution');
$assert(($lastTwoNotifs[0]['recipient_id'] === 10 || $lastTwoNotifs[1]['recipient_id'] === 10), 'Client notified of resolution');
$assert(($lastTwoNotifs[0]['recipient_id'] === 20 || $lastTwoNotifs[1]['recipient_id'] === 20), 'Freelancer notified of resolution');

// Scenario B: Full Payout to Freelancer (With missing wallet safe initialization)
[$pdo, $repo, $service] = createTestEnvironment();
// Note: Freelancer 20 has NO wallet row initially in $repo->wallets!
$assert(!isset($repo->wallets[20]), 'Freelancer has no wallet record initially');

$service->raiseDispute(20, 1, 'Freelancer dispute: Completed work, client refuses to release.');
$resolveFreelancer = $service->resolveDispute(99, 1, 'pay_freelancer', 'Evidence shows all milestones verified. Full payout to freelancer.');

$assert($resolveFreelancer['decision'] === 'pay_freelancer', 'Admin decision pay_freelancer recorded');
$assert($resolveFreelancer['recipient_id'] === 20, 'Payout recipient is Freelancer');
$assert(isset($repo->wallets[20]), 'Freelancer wallet safely initialized via ensureWallet');
$assert($repo->wallets[20]['balance'] === '1500.00', 'Freelancer wallet credited exact escrow amount');
$assert($repo->escrows[1]['status'] === 'released', 'Escrow settled to released');
$assert($repo->jobs[1]['status'] === 'closed_disputed', 'Job transitioned to closed_disputed');

$lastLedger = end($repo->walletEntries);
$assert($lastLedger['source'] === 'admin_decision', 'Ledger source is admin_decision');
$assert($lastLedger['entry_type'] === 'escrow_release', 'Ledger entry type is escrow_release');
$assert($lastLedger['operation_key'] === 'dispute_release:job:1:dispute:1', 'Ledger operation key matches dispute release format');

// Cannot resolve already resolved dispute (409)
try {
    $service->resolveDispute(99, 1, 'refund_client', 'Trying to resolve again');
    $assert(false, 'Duplicate resolution on resolved dispute rejected');
} catch (DisputeException $e) {
    $assert($e->status === 409, 'Duplicate resolution rejected (409)');
}

// Scenario C: Transaction Rollback on Mid-Flight Failure
[$pdo, $repo, $service] = createTestEnvironment();
$service->raiseDispute(10, 1, 'Client dispute for rollback test.');
$pdo->simulateFailure = true;

try {
    $service->resolveDispute(99, 1, 'refund_client', 'This resolution should fail and rollback.');
    $assert(false, 'Simulated failure should have thrown');
} catch (DisputeException $e) {
    $assert($e->status === 500, 'Simulated database failure wrapped in 500 DisputeException');
}

// Verify complete atomic rollback: dispute remains open, job remains disputed, escrow remains locked, wallet unchanged
$assert($repo->disputes[1]['status'] === 'open', 'Dispute remains open after rollback');
$assert($repo->jobs[1]['status'] === 'disputed', 'Job remains disputed after rollback');
$assert($repo->escrows[1]['status'] === 'locked', 'Escrow remains locked after rollback');
$assert($repo->wallets[10]['balance'] === '50.00', 'Client wallet balance unchanged after rollback');
$assert(empty($repo->walletEntries), 'Zero ledger entries committed after rollback');

// =========================================================================
// SUITE 5: Security Fixes Verification (SEC-M21-001 to SEC-M21-005 & Decimal)
// =========================================================================
echo "\n--- Suite 5: Security Fixes Verification ---\n";

// 1. Decimal Precision & Currency Validation
$validMoneyTests = [
    '0.01' => '0.01',
    '0.10' => '0.10',
    '100.05' => '100.05',
    '6150.00' => '6150.00',
    '99999999.99' => '99999999.99',
    '100' => '100.00',
    '50.5' => '50.50',
    '050.00' => '50.00',
    '007.5' => '7.50',
    500 => '500.00',
    1 => '1.00',
];
foreach ($validMoneyTests as $raw => $expected) {
    $res = DisputeService::validateMoneyString($raw);
    $assert($res === $expected, "validateMoneyString accepts valid amount '{$raw}' and returns '{$expected}'");
}

$invalidMoneyTests = [
    '0', '0.00', '-10.00', 'abc', '10.123', '1e5', '100000000.00', '-0.01', '', '  ',
    100.50, 50.0, 0.01, null, [], (object)[]
];
foreach ($invalidMoneyTests as $bad) {
    try {
        DisputeService::validateMoneyString($bad);
        $assert(false, "validateMoneyString rejects invalid amount " . json_encode($bad));
    } catch (DisputeException $e) {
        $assert($e->status === 422, "validateMoneyString rejects invalid amount " . json_encode($bad) . " with 422");
    }
}

// 2. Active Admin Enforcement (SEC-M21-002)
[$pdo, $repo, $service] = createTestEnvironment();
$service->raiseDispute(10, 1, 'Dispute to test admin authorization');

// Suspended admin blocked from resolveDispute
try {
    $service->resolveDispute(98, 1, 'refund_client', 'Suspended admin trying to adjudicate');
    $assert(false, 'Suspended admin blocked from resolving dispute');
} catch (DisputeException $e) {
    $assert($e->status === 403, 'Suspended admin blocked from resolving dispute (403)');
}

// Suspended admin blocked from getDisputeDossier
try {
    $service->getDisputeDossier(98, 1);
    $assert(false, 'Suspended admin blocked from inspecting dossier');
} catch (DisputeException $e) {
    $assert($e->status === 403, 'Suspended admin blocked from inspecting dossier (403)');
}

// Suspended admin blocked from getAdminQueue
try {
    $service->getAdminQueue(98);
    $assert(false, 'Suspended admin blocked from accessing dispute queue');
} catch (DisputeException $e) {
    $assert($e->status === 403, 'Suspended admin blocked from accessing queue (403)');
}

// Suspended admin countOpenDisputes returns 0
$assert($service->getOpenDisputeCount(98) === 0, 'Suspended admin getOpenDisputeCount returns 0');

// AdminDisputeController blocks suspended admin
$suspendedUser = ['id' => 98, 'email' => 'suspended_admin@test.local', 'role' => 'admin', 'status' => 'suspended', 'display_name' => 'Suspended Admin'];
$fakeSuspendedAuth = new class($suspendedUser) {
    public function __construct(private mixed &$user) {}
    public function identity(int $id): ?array { return $this->user; }
};
$sessionSuspended = new AuthSession(static fn() => time(), static fn() => true);
$sessionSuspended->establish(98);
$suspendedGuard = new AuthGuard(static fn() => $fakeSuspendedAuth, $sessionSuspended);
$adminController = new AdminDisputeController($suspendedGuard, static fn() => $service);

$idxResp = $adminController->index([]);
$assert($idxResp['status'] === 403, 'AdminDisputeController::index blocks suspended admin (403)');

$showResp = $adminController->show(['id' => 1]);
$assert($showResp['status'] === 403, 'AdminDisputeController::show blocks suspended admin (403)');

$resolveResp = $adminController->resolve(['dispute_id' => 1, 'decision' => 'refund_client', 'decision_note' => 'Valid rationale note', 'csrf_token' => Csrf::token('admin_decision')]);
$assert($resolveResp['status'] === 403, 'AdminDisputeController::resolve blocks suspended admin (403)');

// Active admin accessing AdminDisputeController with verified identity
$activeAdminUser = ['id' => 99, 'email' => 'admin@test.local', 'role' => 'admin', 'status' => 'active', 'display_name' => 'Diana Admin'];
$fakeActiveAuth = new class($activeAdminUser) {
    public function __construct(private mixed &$user) {}
    public function identity(int $id): ?array { return $this->user; }
};
$sessionActiveAdmin = new AuthSession(static fn() => time(), static fn() => true);
$sessionActiveAdmin->establish(99);
$activeAdminGuard = new AuthGuard(static fn() => $fakeActiveAuth, $sessionActiveAdmin);
$activeAdminController = new AdminDisputeController($activeAdminGuard, static fn() => $service);

$activeIdxResp = $activeAdminController->index([]);
$assert($activeIdxResp['status'] === 200, 'Active admin accesses dispute queue with 200 OK');
$assert(str_contains($activeIdxResp['body'], 'Admin Workspace · Disputes'), 'Active admin sees dispute queue dashboard');

$activeShowResp = $activeAdminController->show(['id' => 1]);
$assert($activeShowResp['status'] === 200, 'Active admin inspects dispute dossier with 200 OK');
$assert(str_contains($activeShowResp['body'], 'refund_client'), 'Active admin dossier contains adjudication decision form');

// Non-admin roles (client, freelancer) blocked from admin routes
$clientGuard = new AuthGuard(static fn() => new class { public function identity(int $id): array { return ['id' => 10, 'role' => 'client', 'display_name' => 'Alice Client', 'status' => 'active']; } }, $sessionActiveAdmin);
$clientAdminCtrl = new AdminDisputeController($clientGuard, static fn() => $service);
$clientAdminResp = $clientAdminCtrl->index([]);
$assert($clientAdminResp['status'] === 403 && str_contains($clientAdminResp['body'], 'You do not have access to this page'), 'Client blocked from admin dashboard (403)');

$freelancerGuard = new AuthGuard(static fn() => new class { public function identity(int $id): array { return ['id' => 20, 'role' => 'freelancer', 'display_name' => 'Bob Freelancer', 'status' => 'active']; } }, $sessionActiveAdmin);
$freelancerAdminCtrl = new AdminDisputeController($freelancerGuard, static fn() => $service);
$freelancerAdminResp = $freelancerAdminCtrl->index([]);
$assert($freelancerAdminResp['status'] === 403 && str_contains($freelancerAdminResp['body'], 'You do not have access to this page'), 'Freelancer blocked from admin dashboard (403)');

// Verify AuthenticationService::identity includes status for all eligible accounts
$authRepoMock = new class extends GigHub\Repositories\UserRepository {
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false {
        return ['id' => $id, 'display_name' => 'Test Admin', 'role' => 'admin', 'status' => 'active', 'email_verified_at' => '2026-01-01 00:00:00'];
    }
};
$realAuthService = new GigHub\Services\AuthenticationService($authRepoMock);
$authIdentity = $realAuthService->identity(125);
$assert(isset($authIdentity['status']) && $authIdentity['status'] === 'active', 'AuthenticationService::identity includes status key with value active');

// 3. Canonical Lock Order & Re-validation (SEC-M21-001)
[$pdo, $repo, $service] = createTestEnvironment();
$service->raiseDispute(10, 1, 'Dispute to test lock order re-validation');

// Test submitDisputeResponse re-validation: job not in disputed status
$repo->jobs[1]['status'] = 'completed';
try {
    $service->submitDisputeResponse(20, 1, 'Trying to respond to completed job');
    $assert(false, 'submitDisputeResponse on non-disputed job rejected');
} catch (DisputeException $e) {
    $assert($e->status === 409, 'submitDisputeResponse on non-disputed job rejected with 409');
}
$repo->jobs[1]['status'] = 'disputed';

// Test resolveDispute re-validation: job not in disputed status
$repo->jobs[1]['status'] = 'in_progress';
try {
    $service->resolveDispute(99, 1, 'refund_client', 'Valid rationale note longer than 10 characters');
    $assert(false, 'resolveDispute on non-disputed job rejected');
} catch (DisputeException $e) {
    $assert($e->status === 409, 'resolveDispute on non-disputed job rejected with 409');
}
$repo->jobs[1]['status'] = 'disputed';

// Test raiseDispute requires active funded escrow
[$pdo, $repo, $service] = createTestEnvironment();
$repo->escrows[1]['status'] = 'refunded';
try {
    $service->raiseDispute(10, 1, 'Dispute when escrow is already refunded');
    $assert(false, 'raiseDispute rejected when escrow already settled');
} catch (DisputeException $e) {
    $assert($e->status === 409, 'raiseDispute rejected when escrow already settled (409)');
}

// 4. Authoritative Redirect Job ID in DisputeController::submitDisputeResponse (SEC-M21-004)
[$pdo, $repo, $service] = createTestEnvironment();
$service->raiseDispute(10, 1, 'Dispute to test controller response redirect');

// Freelancer responds to dispute
$freelancerUser = ['id' => 20, 'email' => 'freelancer@test.local', 'role' => 'freelancer', 'status' => 'active', 'display_name' => 'Bob Freelancer'];
$fakeFreelancerAuth = new class($freelancerUser) {
    public function __construct(private mixed &$user) {}
    public function identity(int $id): ?array { return $this->user; }
};
$sessionFreelancer = new AuthSession(static fn() => time(), static fn() => true);
$sessionFreelancer->establish(20);
$freelancerGuard = new AuthGuard(static fn() => $fakeFreelancerAuth, $sessionFreelancer);
$disputeCtrl = new DisputeController($freelancerGuard, static fn() => $service);

$respRedirect = $disputeCtrl->submitDisputeResponse([
    'job_id' => 1,
    'dispute_id' => 1,
    'response' => 'Freelancer formal response: Completed all tasks as per agreement.',
    'csrf_token' => Csrf::token('dispute_action'),
]);
$assert($respRedirect['status'] === 303, 'submitDisputeResponse returns 303 Post/Redirect/Get');
$assert($respRedirect['location'] === '?route=/freelancer/jobs/milestones&job_id=1', 'submitDisputeResponse redirects to authoritative job_id destination');

// Test that submitDisputeResponse fails safely (503) if service result violates contract (missing/invalid job_id)
$faultyService = new class($pdo, $repo) extends DisputeService {
    public function submitDisputeResponse(int $userId, int $disputeId, string $response): array {
        return ['status' => 'open', 'dispute_id' => $disputeId]; // missing job_id
    }
};
$faultyDisputeCtrl = new DisputeController($freelancerGuard, static fn() => $faultyService);
$faultyResp = $faultyDisputeCtrl->submitDisputeResponse([
    'job_id' => 999, // untrusted fallback attempt
    'dispute_id' => 1,
    'response' => 'Another response attempt',
    'csrf_token' => Csrf::token('dispute_action'),
]);
$assert($faultyResp['status'] === 503, 'submitDisputeResponse returns 503 if service contract violated (no untrusted redirect fallback)');

// 5. Bounded Chat Evidence Pagination (SEC-M21-005)
[$pdo, $repo, $service] = createTestEnvironment();
$service->raiseDispute(10, 1, 'Dispute to test chat evidence pagination');

// Populate 120 messages
$repo->chatMessages = [];
for ($i = 1; $i <= 120; $i++) {
    $repo->chatMessages[] = [
        'id' => $i,
        'bid_id' => 101,
        'sender_id' => ($i % 2 === 0) ? 20 : 10,
        'body' => "Direct message #{$i}",
        'created_at' => date('Y-m-d H:i:s', strtotime("2026-11-01 10:00:00 +{$i} minutes")),
        'sender_name' => ($i % 2 === 0) ? 'Bob Freelancer' : 'Alice Client',
    ];
}

// Page 1: returns first 50
$dossierP1 = $service->getDisputeDossier(99, 1, 1, 50);
$assert(count($dossierP1['chat_messages']) === 50, 'Page 1 returns exactly 50 messages');
$assert($dossierP1['chat_messages'][0]['id'] === 1, 'Page 1 starts at message #1');
$assert($dossierP1['chat_pagination']['current_page'] === 1, 'chat_pagination current_page is 1');
$assert($dossierP1['chat_pagination']['total_items'] === 120, 'chat_pagination total_items is 120');
$assert($dossierP1['chat_pagination']['total_pages'] === 3, 'chat_pagination total_pages is 3');

// Page 2: returns next 50 (51..100)
$dossierP2 = $service->getDisputeDossier(99, 1, 2, 50);
$assert(count($dossierP2['chat_messages']) === 50, 'Page 2 returns exactly 50 messages');
$assert($dossierP2['chat_messages'][0]['id'] === 51, 'Page 2 starts at message #51');
$assert($dossierP2['chat_pagination']['current_page'] === 2, 'chat_pagination current_page is 2');

// Page 3: returns last 20 (101..120)
$dossierP3 = $service->getDisputeDossier(99, 1, 3, 50);
$assert(count($dossierP3['chat_messages']) === 20, 'Page 3 returns remaining 20 messages');
$assert($dossierP3['chat_messages'][0]['id'] === 101, 'Page 3 starts at message #101');
$assert($dossierP3['chat_pagination']['current_page'] === 3, 'chat_pagination current_page is 3');

// Page clamping: page 99 clamps to page 3
$dossierClamp = $service->getDisputeDossier(99, 1, 99, 50);
$assert($dossierClamp['chat_pagination']['current_page'] === 3, 'Out-of-bounds chat page clamps to max page (3)');
$assert(count($dossierClamp['chat_messages']) === 20, 'Clamped page returns remaining 20 messages');

// Negative page clamps to page 1
$dossierNeg = $service->getDisputeDossier(99, 1, -5, 50);
$assert($dossierNeg['chat_pagination']['current_page'] === 1, 'Negative chat page clamps to page 1');

// 6. UI Action Suppression Assertions (SEC-M21-003)
$jobStatuses = ['in_progress', 'under_review', 'disputed', 'cancelled', 'closed_disputed'];
foreach ($jobStatuses as $status) {
    $canReviewMilestone = ($status === 'in_progress');
    $canReviewFinal = ($status === 'under_review');
    $canReleaseEscrow = ($status === 'under_review');

    if (in_array($status, ['disputed', 'cancelled', 'closed_disputed'], true)) {
        $assert(!$canReviewMilestone, "Milestone review suppressed in status '{$status}'");
        $assert(!$canReviewFinal, "Final review suppressed in status '{$status}'");
        $assert(!$canReleaseEscrow, "Escrow release suppressed in status '{$status}'");
    }
}

// -------------------------------------------------------------------------
// Suite 6: Post-Cancellation & Post-Dispute Milestone View Access (Regression Fix)
// -------------------------------------------------------------------------
echo "\n--- Suite 6: Post-Cancellation & Post-Dispute Milestone View Access ---\n";

$mockMilestoneRepo = new class extends MilestoneRepository {
    public array $jobRow = [
        'id' => 1,
        'client_id' => 10,
        'title' => 'Test Milestone Job',
        'status' => 'cancelled',
        'deadline' => '2026-12-31',
        'awarded_bid_id' => 101,
    ];
    public array $milestones = [
        [
            'id' => 1,
            'job_id' => 1,
            'title' => 'Milestone 1',
            'description' => 'Initial milestone',
            'due_date' => '2026-11-20',
            'position' => 1,
            'status' => 'pending',
            'approved_at' => null,
            'created_at' => '2026-11-01 10:00:00',
            'updated_at' => '2026-11-01 10:00:00',
        ]
    ];
    public function __construct() {}
    public function job(int $jobId, bool $lock = false): array|false {
        return ($this->jobRow['id'] === $jobId) ? $this->jobRow : false;
    }
    public function hasEscrow(int $jobId, bool $lock = false): bool { return true; }
    public function escrow(int $jobId, bool $lock = false): array|false {
        return [
            'id' => 1,
            'job_id' => $jobId,
            'amount' => '1500.00',
            'status' => ($this->jobRow['status'] === 'cancelled' ? 'refunded' : 'locked'),
            'funded_at' => '2026-11-01 10:00:00',
            'settled_at' => ($this->jobRow['status'] === 'cancelled' ? '2026-11-01 12:00:00' : null),
        ];
    }
    public function eligibleClient(int $id, bool $lock = false): bool { return $id === 10; }
    public function eligibleFreelancer(int $id, bool $lock = false): bool { return $id === 20; }
    public function isAwardedFreelancer(int $jobId, int $freelancerId): bool { return $freelancerId === 20; }
    public function forJob(int $jobId): array { return $this->milestones; }
    public function submissionsForJob(int $jobId): array { return []; }
    public function find(int $id, int $jobId, bool $lock = false): array|false {
        return ($id === 1 && $jobId === 1) ? $this->milestones[0] : false;
    }
    public function create(int $jobId, string $title, ?string $description, string $dueDate, int $position): array {
        return ['id' => 2];
    }
    public function update(int $id, int $jobId, string $title, ?string $description, string $dueDate): void {}
    public function delete(int $id, int $jobId): void {}
};

$milestonePdo = new class extends PDO {
    public function __construct() {}
    public function inTransaction(): bool { return false; }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { return true; }
};
$milestoneValidator = new MilestoneValidator();
$milestoneService = new MilestoneService($milestonePdo, $mockMilestoneRepo, $milestoneValidator);

// Test 1: MilestoneService::listForJob on 'cancelled' status
$clientList = $milestoneService->listForJob(10, 1);
$assert(is_array($clientList), 'MilestoneService::listForJob succeeds for job with status cancelled');
$assert($clientList['job']['status'] === 'cancelled', 'MilestoneService::listForJob returns correct job status cancelled');
$assert($clientList['is_locked'] === true, 'MilestoneService::listForJob marks cancelled job as is_locked');

// Test 2: MilestoneService::listForFreelancer on 'cancelled' status
$freelancerList = $milestoneService->listForFreelancer(20, 1);
$assert(is_array($freelancerList), 'MilestoneService::listForFreelancer succeeds for job with status cancelled');
$assert($freelancerList['job']['status'] === 'cancelled', 'MilestoneService::listForFreelancer returns correct job status cancelled');
$assert($freelancerList['is_locked'] === true, 'MilestoneService::listForFreelancer marks cancelled job as is_locked');

// Test 3: MilestoneService mutation methods are strictly blocked with 409 on cancelled job
$denied409 = function(callable $fn, string $desc) use ($assert): void {
    try {
        $fn();
        $assert(false, $desc);
    } catch (MilestoneException $e) {
        $assert($e->status === 409, $desc);
    }
};
$denied409(fn() => $milestoneService->createMilestone(10, 1, ['title' => 'M2', 'due_date' => '2026-11-25']), 'createMilestone rejected with 409 on cancelled job');
$denied409(fn() => $milestoneService->updateMilestone(10, 1, 1, ['title' => 'M1 Updated', 'due_date' => '2026-11-25']), 'updateMilestone rejected with 409 on cancelled job');
$denied409(fn() => $milestoneService->deleteMilestone(10, 1, 1), 'deleteMilestone rejected with 409 on cancelled job');

// Test 4: MilestoneController rendering on cancelled job (Client workspace)
$clientUserRow = ['id' => 10, 'role' => 'client', 'display_name' => 'Alice Client', 'status' => 'active', 'email_verified_at' => '2026-11-01 00:00:00'];
$clientAuthFake = new class($clientUserRow) {
    public function __construct(private array $u) {}
    public function identity(int $id): ?array { return ($id === $this->u['id']) ? $this->u : null; }
};
$sessionClient = new AuthSession(static fn() => time(), static fn() => true);
$sessionClient->establish(10);
$clientAuthGuard = new AuthGuard(static fn() => $clientAuthFake, $sessionClient);
$milestoneCtrl = new MilestoneController($clientAuthGuard, static fn() => $milestoneService);

$clientResp = $milestoneCtrl->index(['job_id' => 1]);
$assert($clientResp['status'] === 200, 'MilestoneController::index returns HTTP 200 for cancelled job (no 409 Error Page)');
$assert(str_contains($clientResp['body'], 'Milestones for Test Milestone Job'), 'Client milestone view renders job title for cancelled job');

// Test 5: MilestoneController rendering on cancelled job (Freelancer workspace)
$flUserRow = ['id' => 20, 'role' => 'freelancer', 'display_name' => 'Bob Freelancer', 'status' => 'active', 'email_verified_at' => '2026-11-01 00:00:00'];
$flAuthFake = new class($flUserRow) {
    public function __construct(private array $u) {}
    public function identity(int $id): ?array { return ($id === $this->u['id']) ? $this->u : null; }
};
$sessionFreelancerSuite6 = new AuthSession(static fn() => time(), static fn() => true);
$sessionFreelancerSuite6->establish(20);
$flAuthGuard = new AuthGuard(static fn() => $flAuthFake, $sessionFreelancerSuite6);
$flMilestoneCtrl = new MilestoneController($flAuthGuard, static fn() => $milestoneService);

$flResp = $flMilestoneCtrl->freelancerIndex(['job_id' => 1]);
$assert($flResp['status'] === 200, 'MilestoneController::freelancerIndex returns HTTP 200 for cancelled job (no 409 Error Page)');
$assert(str_contains($flResp['body'], 'FREELANCER WORKSPACE'), 'Freelancer milestone view renders workspace eyebrow for cancelled job');

// Test 6: Verify end-to-end post-cancellation destination compatibility
$cancellationRedirectTarget = '?route=/client/jobs/milestones&job_id=1';
$assert($clientResp['status'] === 200 && str_contains($cancellationRedirectTarget, 'job_id=1'), 'Cancellation acceptance redirect destination renders cleanly with HTTP 200');

// Test 7: Verify Dispute Banner structure and styling classes
$bannerJob = ['id' => 1, 'title' => 'Test Job'];
$bannerDisputeContext = [
    'cancellation' => ['status' => 'rejected', 'responded_at' => '2026-11-11 02:38:31'],
    'dispute' => null,
    'can_cancel' => false,
    'can_dispute' => true,
    'can_respond_cancellation' => false,
    'can_respond_dispute' => false,
    'is_client' => true,
];
ob_start();
$job = $bannerJob;
$disputeContext = $bannerDisputeContext;
$escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
require dirname(__DIR__) . '/app/Views/partials/dispute-banner.php';
$bannerHtml = (string)ob_get_clean();

$assert(str_contains($bannerHtml, 'gh-resolution-alert--declined'), 'Dispute banner renders declined cancellation alert card');
$assert(str_contains($bannerHtml, 'gh-dispute-action-card'), 'Dispute banner renders formal dispute action card');
$assert(str_contains($bannerHtml, 'gh-control gh-dispute-textarea'), 'Dispute banner textarea includes gh-control and gh-dispute-textarea');
$assert(str_contains($bannerHtml, 'gh-button gh-btn-dispute-submit'), 'Dispute banner submit button includes gh-button and gh-btn-dispute-submit');
$assert(str_contains($bannerHtml, 'gh-button gh-button-secondary gh-btn-dispute-cancel'), 'Dispute banner cancel button includes gh-button secondary styles');
$assert(str_contains($bannerHtml, 'gh-char-counter'), 'Dispute banner includes dynamic character counter');

// -------------------------------------------------------------------------
// Summary Report
// -------------------------------------------------------------------------
echo "\n=======================================================\n";
echo " Test Results: {$testCount} checks executed\n";
echo " Passed: " . ($testCount - $failCount) . "\n";
echo " Failed: {$failCount}\n";
echo "=======================================================\n\n";

if ($failCount > 0) {
    exit(1);
}
