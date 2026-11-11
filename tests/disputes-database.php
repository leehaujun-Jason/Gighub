<?php
declare(strict_types=1);

namespace GigHub\Tests;

use GigHub\Core\Csrf;
use GigHub\Repositories\DisputeRepository;
use GigHub\Repositories\EscrowRepository;
use GigHub\Repositories\NotificationRepository;
use GigHub\Repositories\WalletRepository;
use GigHub\Repositories\MilestoneRepository;
use GigHub\Services\DisputeException;
use GigHub\Services\DisputeService;
use GigHub\Services\EscrowException;
use GigHub\Services\EscrowService;
use GigHub\Services\MilestoneException;
use GigHub\Services\MilestoneService;
use GigHub\Services\MilestoneValidator;
use GigHub\Services\WalletException;
use GigHub\Services\WalletService;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (count($argv) < 2 || ($argv[1] ?? '') !== '--run-isolated') {
    fwrite(STDERR, "Explicit opt-in required: php tests/disputes-database.php --run-isolated\n");
    exit(2);
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');

$root = dirname(__DIR__);
require_once __DIR__ . '/integration-bootstrap.php';
require_once $root . '/app/Core/Csrf.php';
require_once $root . '/app/Repositories/DisputeRepository.php';
require_once $root . '/app/Services/DisputeException.php';
require_once $root . '/app/Services/DisputeService.php';
require_once $root . '/app/Repositories/EscrowRepository.php';
require_once $root . '/app/Services/EscrowException.php';
require_once $root . '/app/Services/EscrowService.php';
require_once $root . '/app/Repositories/WalletRepository.php';
require_once $root . '/app/Services/WalletException.php';
require_once $root . '/app/Services/WalletService.php';
require_once $root . '/app/Repositories/NotificationRepository.php';
require_once $root . '/app/Repositories/MilestoneRepository.php';
require_once $root . '/app/Services/MilestoneValidator.php';
require_once $root . '/app/Services/MilestoneException.php';
require_once $root . '/app/Services/MilestoneService.php';

// -----------------------------------------------------------------------------
// WORKER MODE (SPAWNED BY IT-10 FOR MULTI-PROCESS CONCURRENCY)
// -----------------------------------------------------------------------------
if (isset($argv[2]) && str_starts_with($argv[2], '--worker=')) {
    $scenario = substr($argv[2], 9);
    $jobId = (int)($argv[3] ?? 0);
    $disputeId = (int)($argv[4] ?? 0);
    $escrowId = (int)($argv[5] ?? 0);
    $userId = (int)($argv[6] ?? 0);
    $decision = (string)($argv[7] ?? '');
    $role = (string)($argv[8] ?? ''); // 'holder' (Process A) or 'competitor' (Process B)
    $sentinel = (string)($argv[9] ?? '');

    try {
        $pdo = IntegrationDatabase::connection(['disputes-worker', '--run-isolated']);
        $disputeRepo = new DisputeRepository($pdo);
        $disputeService = new DisputeService($pdo, $disputeRepo);

        if ($scenario === 'admin_resolve') {
            if ($role === 'holder') {
                // Process A: Begin transaction, lock job row FOR UPDATE, signal sentinel, hold, resolve, commit
                $pdo->beginTransaction();
                $pdo->query("SELECT id FROM jobs WHERE id = $jobId FOR UPDATE");
                if ($sentinel !== '') {
                    file_put_contents($sentinel, 'locked');
                }
                // Hold lock for 400ms to guarantee Process B blocks on MariaDB row lock
                usleep(400000);

                // Commit resolution directly inside transaction
                $escrow = $disputeRepo->getEscrow($jobId, true);
                $amount = (string)$escrow['amount'];
                $pdo->prepare("UPDATE disputes SET status = 'resolved', decision = ?, admin_id = ?, decision_note = 'Resolved by Process A', resolved_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$decision, $userId, $disputeId]);
                $pdo->prepare("UPDATE escrows SET status = 'refunded', settled_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$escrowId]);
                $pdo->prepare("UPDATE jobs SET status = 'closed_disputed', closed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$jobId]);
                $recipientId = (int)$pdo->query("SELECT client_id FROM jobs WHERE id = $jobId")->fetchColumn();
                $pdo->prepare("UPDATE wallets SET balance = balance + ?, updated_at = UTC_TIMESTAMP() WHERE user_id = ?")->execute([$amount, $recipientId]);
                $opKey = 'dispute_refund:job:' . $jobId . ':dispute:' . $disputeId;
                $pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key, created_at) VALUES (?, ?, 'escrow_refund', ?, 'admin_decision', ?, ?, UTC_TIMESTAMP())")->execute([$recipientId, $escrowId, $amount, $userId, $opKey]);
                $pdo->commit();
                echo json_encode(['success' => true, 'http_status' => 200, 'message' => 'Process A resolved dispute']);
            } else {
                // Process B: Wait for Process A sentinel, then attempt resolveDispute (will block on row lock)
                if ($sentinel !== '') {
                    $waited = 0;
                    while (!file_exists($sentinel) && $waited < 20) {
                        usleep(25000);
                        ++$waited;
                    }
                }
                $res = $disputeService->resolveDispute($userId, $disputeId, $decision, 'Process B competing adjudication');
                echo json_encode(['success' => true, 'http_status' => 200, 'data' => $res]);
            }
        } elseif ($scenario === 'cancel_accept') {
            if ($role === 'holder') {
                // Process A: Begin transaction, lock job row FOR UPDATE, signal sentinel, hold, accept cancellation, commit
                $pdo->beginTransaction();
                $pdo->query("SELECT id FROM jobs WHERE id = $jobId FOR UPDATE");
                if ($sentinel !== '') {
                    file_put_contents($sentinel, 'locked');
                }
                usleep(400000);

                $req = $disputeRepo->getCancellationRequest($jobId, true);
                $escrow = $disputeRepo->getEscrow($jobId, true);
                $amount = (string)$escrow['amount'];
                $pdo->prepare("UPDATE cancellation_requests SET status = 'accepted', responded_by = ?, responded_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$userId, (int)$req['id']]);
                $pdo->prepare("UPDATE escrows SET status = 'refunded', settled_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$escrowId]);
                $pdo->prepare("UPDATE jobs SET status = 'cancelled', closed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$jobId]);
                $recipientId = (int)$pdo->query("SELECT client_id FROM jobs WHERE id = $jobId")->fetchColumn();
                $pdo->prepare("UPDATE wallets SET balance = balance + ?, updated_at = UTC_TIMESTAMP() WHERE user_id = ?")->execute([$amount, $recipientId]);
                $opKey = 'cancellation_refund:job:' . $jobId;
                $pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key, created_at) VALUES (?, ?, 'escrow_refund', ?, 'mutual_cancellation', ?, ?, UTC_TIMESTAMP())")->execute([$recipientId, $escrowId, $amount, $userId, $opKey]);
                $pdo->commit();
                echo json_encode(['success' => true, 'http_status' => 200, 'message' => 'Process A accepted cancellation']);
            } else {
                if ($sentinel !== '') {
                    $waited = 0;
                    while (!file_exists($sentinel) && $waited < 20) {
                        usleep(25000);
                        ++$waited;
                    }
                }
                $res = $disputeService->respondCancellation($userId, $jobId, 'accept');
                echo json_encode(['success' => true, 'http_status' => 200, 'data' => $res]);
            }
        } else {
            echo json_encode(['success' => false, 'http_status' => 400, 'error' => 'Unknown worker scenario']);
        }
        exit(0);
    } catch (DisputeException $e) {
        echo json_encode(['success' => false, 'http_status' => $e->getCode(), 'error' => $e->getMessage()]);
        exit(0);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'http_status' => 500, 'error' => $e->getMessage()]);
        exit(0);
    }
}

// -----------------------------------------------------------------------------
// MAIN INTEGRATION TEST SUITE
// -----------------------------------------------------------------------------
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: $label");
    }
    ++$checks;
};

$pdo = IntegrationDatabase::connection($argv);
$check($pdo->query('SELECT DATABASE()')->fetchColumn() === 'gighub_schema_test', 'Connected database must be gighub_schema_test');
$check($pdo->query('SELECT CURRENT_USER()')->fetchColumn() === 'gighub_test@127.0.0.1', 'Connected account must be gighub_test@127.0.0.1');
$check(!$pdo->inTransaction(), 'Active transaction detected before test run');

$disputeRepo = new DisputeRepository($pdo);
$disputeService = new DisputeService($pdo, $disputeRepo);
$escrowRepo = new EscrowRepository($pdo);
$walletRepo = new WalletRepository($pdo);

echo "===================================================================\n";
echo " GIGHUB MODULE 21 — REAL DATABASE INTEGRATION TEST SUITE\n";
echo " Endpoint: 127.0.0.1:3309 | Database: gighub_schema_test\n";
echo "===================================================================\n\n";

// -----------------------------------------------------------------------------
// FIXTURE CREATION (DETERMINISTIC, EXACT DECIMAL STRINGS, DYNAMIC KEYS)
// -----------------------------------------------------------------------------
echo "[SETUP] Creating synthetic fixtures in gighub_schema_test...\n";
$prefix = 'm21-' . bin2hex(random_bytes(4)) . '-';
$passHash = password_hash('SyntheticTestPassword2026!', PASSWORD_ARGON2ID);

// 1. Users
$createUser = static function (string $role, string $name, string $status = 'active') use ($pdo, $prefix, $passHash): int {
    $email = $prefix . strtolower(str_replace(' ', '', $name)) . '@test.invalid';
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name, role, status, email_verified_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$email, $passHash, $name, $role, $status]);
    $userId = (int)$pdo->lastInsertId();

    if ($role === 'client') {
        $pdo->prepare("INSERT INTO client_profiles (user_id, company_name, bio) VALUES (?, 'Test Company', 'Client profile bio')")->execute([$userId]);
    } elseif ($role === 'freelancer') {
        $pdo->prepare("INSERT INTO freelancer_profiles (user_id, bio, portfolio_description, portfolio_url) VALUES (?, 'Freelancer bio', 'Portfolio text', 'https://example.invalid')")->execute([$userId]);
    }
    return $userId;
};

$client1Id = $createUser('client', 'Client Alpha');
$client2Id = $createUser('client', 'Client Beta');
$freelancer1Id = $createUser('freelancer', 'Freelancer One');
$freelancer2Id = $createUser('freelancer', 'Freelancer Two');
$freelancer3Id = $createUser('freelancer', 'Freelancer Three'); // No wallet row initially (for IT-08)
$admin1Id = $createUser('admin', 'Admin Active');
$admin2Id = $createUser('admin', 'Admin Suspended', 'suspended'); // Suspended (for IT-09)
$user3Id = $createUser('client', 'Unrelated User');

$check($client1Id > 0 && $client2Id > 0 && $freelancer1Id > 0 && $freelancer2Id > 0 && $admin1Id > 0, 'Synthetic users created');

// 2. Initial Wallets & Double-Entry Demo Credit Seeding
$seedWallet = static function (int $userId, string $amount) use ($pdo): void {
    $pdo->prepare("INSERT INTO wallets (user_id, balance, updated_at) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$userId, $amount]);
    $opKey = 'demo:user:' . $userId;
    $pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key, created_at) VALUES (?, NULL, 'demo_credit', ?, 'demo_seed', ?, ?, UTC_TIMESTAMP())")->execute([$userId, $amount, $userId, $opKey]);
};

$seedWallet($client1Id, '10000.00');
$seedWallet($client2Id, '10000.00');
$pdo->prepare("INSERT INTO wallets (user_id, balance, updated_at) VALUES (?, 0.00, UTC_TIMESTAMP())")->execute([$freelancer1Id]);
$pdo->prepare("INSERT INTO wallets (user_id, balance, updated_at) VALUES (?, 0.00, UTC_TIMESTAMP())")->execute([$freelancer2Id]);
// Freelancer 3 intentionally has NO row in wallets table

// 3. Contract Creation Helper
$createContract = static function (int $clientId, int $freelancerId, string $title, string $amount) use ($pdo): array {
    // A. Post Job
    $stmt = $pdo->prepare("INSERT INTO jobs (client_id, title, description, budget, deadline, status, created_at, updated_at) VALUES (?, ?, 'Contract test description', ?, DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY), 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$clientId, $title, $amount]);
    $jobId = (int)$pdo->lastInsertId();

    // B. Submit Bid
    $stmt = $pdo->prepare("INSERT INTO bids (job_id, freelancer_id, amount, delivery_days, cover_letter, status, created_at, updated_at) VALUES (?, ?, ?, 14, 'Bid cover letter', 'accepted', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $stmt->execute([$jobId, $freelancerId, $amount]);
    $bidId = (int)$pdo->lastInsertId();

    // C. Award Bid & Job to In Progress
    $pdo->prepare("UPDATE jobs SET awarded_bid_id = ?, awarded_at = UTC_TIMESTAMP(), status = 'in_progress' WHERE id = ?")->execute([$bidId, $jobId]);

    // D. Create Milestone
    $pdo->prepare("INSERT INTO milestones (job_id, title, due_date, position, status, created_at, updated_at) VALUES (?, 'Milestone 1', DATE_ADD(CURRENT_DATE, INTERVAL 14 DAY), 1, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$jobId]);

    // E. Debit Client Wallet
    $pdo->prepare("UPDATE wallets SET balance = balance - ?, updated_at = UTC_TIMESTAMP() WHERE user_id = ?")->execute([$amount, $clientId]);

    // F. Create Locked Escrow
    $stmt = $pdo->prepare("INSERT INTO escrows (job_id, bid_id, amount, status, funded_at) VALUES (?, ?, ?, 'locked', UTC_TIMESTAMP())");
    $stmt->execute([$jobId, $bidId, $amount]);
    $escrowId = (int)$pdo->lastInsertId();

    // G. Append Ledger Debit
    $opKey = 'escrow_fund:job:' . $jobId;
    $pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key, created_at) VALUES (?, ?, 'escrow_fund', ?, 'client_funding', ?, ?, UTC_TIMESTAMP())")->execute([$clientId, $escrowId, '-' . $amount, $clientId, $opKey]);

    return ['job_id' => $jobId, 'bid_id' => $bidId, 'escrow_id' => $escrowId, 'amount' => $amount];
};

// Create Dedicated Synthetic Contracts
$cA = $createContract($client1Id, $freelancer1Id, 'Contract A (IT-01)', '1000.00'); // Client 1 balance: 9000.00
$cB = $createContract($client1Id, $freelancer1Id, 'Contract B (IT-02)', '1500.00'); // Client 1 balance: 7500.00
$cC = $createContract($client2Id, $freelancer2Id, 'Contract C (IT-03, IT-04)', '2000.00'); // Client 2 balance: 8000.00
$cD = $createContract($client2Id, $freelancer2Id, 'Contract D (IT-05)', '2500.00'); // Client 2 balance: 5500.00
$cE = $createContract($client1Id, $freelancer1Id, 'Contract E (IT-10A Concurrency)', '500.00'); // Client 1 balance: 7000.00
$cF = $createContract($client2Id, $freelancer2Id, 'Contract F (IT-10B Concurrency)', '600.00'); // Client 2 balance: 4900.00
$cG = $createContract($client1Id, $freelancer2Id, 'Contract G (IT-07 Rollback)', '700.00'); // Client 1 balance: 6300.00
$cH = $createContract($client2Id, $freelancer3Id, 'Contract H (IT-08 Missing Wallet)', '800.00'); // Client 2 balance: 4100.00

// Verify Initial Balance Invariant
$bal1 = (string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $client1Id")->fetchColumn();
$bal2 = (string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $client2Id")->fetchColumn();
$check($bal1 === '6300.00', "Client 1 initial balance verified ($bal1 === 6300.00)");
$check($bal2 === '4100.00', "Client 2 initial balance verified ($bal2 === 4100.00)");

echo "[SETUP] Synthetic fixtures initialized successfully.\n\n";

// =============================================================================
// IT-01: MUTUAL CANCELLATION REQUEST & DECLINE
// =============================================================================
echo "Running IT-01: Mutual Cancellation Request & Decline...\n";
$res1A = $disputeService->requestCancellation($client1Id, $cA['job_id'], 'Schedule conflict: client requesting cancellation');
$check($res1A['status'] === 'pending', 'IT-01: Cancellation request created as pending');

$reqA = $disputeRepo->getCancellationRequest($cA['job_id']);
$check($reqA !== null && $reqA['status'] === 'pending' && (int)$reqA['requested_by'] === $client1Id, 'IT-01: cancellation_requests row verified');
$check($reqA['responded_by'] === null && $reqA['responded_at'] === null, 'IT-01: Responded fields initially null');

$res1B = $disputeService->respondCancellation($freelancer1Id, $cA['job_id'], 'decline');
$check($res1B['status'] === 'rejected', 'IT-01: Cancellation declined by freelancer');

$reqAAfter = $disputeRepo->getCancellationRequest($cA['job_id']);
$check($reqAAfter['status'] === 'rejected', 'IT-01: Cancellation row status updated to rejected');
$check((int)$reqAAfter['responded_by'] === $freelancer1Id && $reqAAfter['responded_at'] !== null, 'IT-01: Responded by freelancer recorded');

$jobA = $disputeRepo->getJob($cA['job_id']);
$escrowA = $disputeRepo->getEscrow($cA['job_id']);
$check($jobA['status'] === 'in_progress', 'IT-01: Job remains in_progress');
$check($escrowA['status'] === 'locked' && $escrowA['settled_at'] === null, 'IT-01: Escrow remains locked');

$check((string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $client1Id")->fetchColumn() === '6300.00', 'IT-01: Client 1 wallet balance untouched');
$check((string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $freelancer1Id")->fetchColumn() === '0.00', 'IT-01: Freelancer 1 wallet balance untouched');

$notif1 = $pdo->query("SELECT type, recipient_id FROM notifications WHERE job_id = {$cA['job_id']} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$check($notif1['type'] === 'cancellation_responded' && (int)$notif1['recipient_id'] === $client1Id, 'IT-01: Notification cancellation_responded sent to Client');
echo "  [PASS] IT-01 passed.\n\n";

// =============================================================================
// IT-02: MUTUAL CANCELLATION ACCEPTANCE & EXACT ESCROW REFUND
// =============================================================================
echo "Running IT-02: Mutual Cancellation Acceptance & Exact Escrow Refund...\n";
$res2A = $disputeService->requestCancellation($freelancer1Id, $cB['job_id'], 'Hardware failure, unable to deliver milestone');
$check($res2A['status'] === 'pending', 'IT-02: Cancellation request created by freelancer');

$res2B = $disputeService->respondCancellation($client1Id, $cB['job_id'], 'accept');
$check($res2B['status'] === 'accepted', 'IT-02: Cancellation accepted by client');
$check($res2B['refund_amount'] === '1500.00', 'IT-02: Refund amount returned correctly');

$reqB = $disputeRepo->getCancellationRequest($cB['job_id']);
$check($reqB['status'] === 'accepted' && (int)$reqB['responded_by'] === $client1Id, 'IT-02: cancellation_requests updated to accepted');

$jobB = $disputeRepo->getJob($cB['job_id']);
$escrowB = $disputeRepo->getEscrow($cB['job_id']);
$check($jobB['status'] === 'cancelled' && $jobB['closed_at'] !== null, 'IT-02: Job transitioned to cancelled with closed_at');
$check($escrowB['status'] === 'refunded' && $escrowB['settled_at'] !== null, 'IT-02: Escrow transitioned to refunded with settled_at');

$bal1AfterB = (string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $client1Id")->fetchColumn();
$check($bal1AfterB === '7800.00', "IT-02: Client 1 wallet balance credited from 6300.00 to 7800.00 ($bal1AfterB === 7800.00)");

$ledgerB = $pdo->query("SELECT * FROM wallet_entries WHERE escrow_id = {$cB['escrow_id']} AND entry_type = 'escrow_refund'")->fetch(PDO::FETCH_ASSOC);
$check(is_array($ledgerB), 'IT-02: Exactly one escrow_refund ledger entry created');
$check((int)$ledgerB['wallet_user_id'] === $client1Id, 'IT-02: Ledger wallet_user_id matches Client');
$check($ledgerB['amount_delta'] === '1500.00', 'IT-02: Ledger amount_delta is 1500.00');
$check($ledgerB['source'] === 'mutual_cancellation', 'IT-02: Ledger source is mutual_cancellation');
$check((int)$ledgerB['actor_id'] === $client1Id, 'IT-02: Ledger actor_id is Client');
$check($ledgerB['operation_key'] === 'cancellation_refund:job:' . $cB['job_id'], 'IT-02: Operation key matches expected format');
$check((int)$ledgerB['settlement_escrow_id'] === $cB['escrow_id'], 'IT-02: STORED generated settlement_escrow_id matches escrow');

$msB = $pdo->query("SELECT status FROM milestones WHERE job_id = {$cB['job_id']}")->fetchColumn();
$check($msB === 'pending', 'IT-02: Milestones table status untouched by cancellation');

// Regression verification: MilestoneService allows viewing cancelled contract without 409
$milestoneRepo = new MilestoneRepository($pdo);
$milestoneValidator = new MilestoneValidator();
$milestoneService = new MilestoneService($pdo, $milestoneRepo, $milestoneValidator);

$clientMsView = $milestoneService->listForJob($client1Id, $cB['job_id']);
$check(is_array($clientMsView) && $clientMsView['job']['status'] === 'cancelled', 'IT-02: MilestoneService::listForJob allows Client to list cancelled contract');
$check($clientMsView['is_locked'] === true, 'IT-02: MilestoneService::listForJob marks cancelled contract as is_locked');

$flMsView = $milestoneService->listForFreelancer($freelancer1Id, $cB['job_id']);
$check(is_array($flMsView) && $flMsView['job']['status'] === 'cancelled', 'IT-02: MilestoneService::listForFreelancer allows Freelancer to list cancelled contract');
$check($flMsView['is_locked'] === true, 'IT-02: MilestoneService::listForFreelancer marks cancelled contract as is_locked');

echo "  [PASS] IT-02 passed.\n\n";

// =============================================================================
// IT-03: DISPUTE ESCALATION & CANCELLATION SUPERSESSION
// =============================================================================
echo "Running IT-03: Dispute Escalation & Cancellation Supersession...\n";
$res3A = $disputeService->requestCancellation($client2Id, $cC['job_id'], 'Client requested cancellation first');
$check($res3A['status'] === 'pending', 'IT-03: Initial cancellation pending');

$res3B = $disputeService->raiseDispute($freelancer2Id, $cC['job_id'], 'Freelancer escalating to formal administrative dispute');
$check($res3B['status'] === 'open', 'IT-03: Dispute opened successfully');
$disputeCId = (int)$res3B['dispute_id'];

$reqC = $disputeRepo->getCancellationRequest($cC['job_id']);
$check($reqC['status'] === 'superseded', 'IT-03: Pending cancellation automatically marked superseded');

$jobC = $disputeRepo->getJob($cC['job_id']);
$check($jobC['status'] === 'disputed', 'IT-03: Job status transitioned to disputed');

$notif3 = $pdo->query("SELECT type, recipient_id FROM notifications WHERE job_id = {$cC['job_id']} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$check($notif3['type'] === 'dispute_opened' && (int)$notif3['recipient_id'] === $client2Id, 'IT-03: dispute_opened notification sent to counterpart');
echo "  [PASS] IT-03 passed.\n\n";

// =============================================================================
// IT-04: ADMIN DISPUTE RESOLUTION — FULL REFUND TO CLIENT
// =============================================================================
echo "Running IT-04: Admin Dispute Resolution — Full Refund to Client...\n";
$res4A = $disputeService->submitDisputeResponse($client2Id, $disputeCId, 'Client formal counterpart statement regarding dispute');
$check($res4A['status'] === 'open', 'IT-04: Counterpart response submitted');

$dispCAfterResp = $disputeRepo->getDisputeById($disputeCId);
$check($dispCAfterResp['response'] !== null, 'IT-04: Dispute response recorded in database');

$res4B = $disputeService->resolveDispute($admin1Id, $disputeCId, 'refund_client', 'Full refund awarded to Client by Admin');
$check($res4B['decision'] === 'refund_client' && (int)$res4B['recipient_id'] === $client2Id, 'IT-04: Dispute resolved with refund_client');

$dispCFinal = $disputeRepo->getDisputeById($disputeCId);
$check($dispCFinal['status'] === 'resolved' && $dispCFinal['decision'] === 'refund_client', 'IT-04: Dispute row status resolved');
$check((int)$dispCFinal['admin_id'] === $admin1Id && $dispCFinal['resolved_at'] !== null, 'IT-04: Admin ID and resolved_at recorded');

$jobCFinal = $disputeRepo->getJob($cC['job_id']);
$escrowCFinal = $disputeRepo->getEscrow($cC['job_id']);
$check($jobCFinal['status'] === 'closed_disputed' && $jobCFinal['closed_at'] !== null, 'IT-04: Job transitioned to closed_disputed with closed_at');
$check($escrowCFinal['status'] === 'refunded' && $escrowCFinal['settled_at'] !== null, 'IT-04: Escrow transitioned to refunded with settled_at');

$bal2AfterC = (string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $client2Id")->fetchColumn();
$check($bal2AfterC === '6100.00', "IT-04: Client 2 wallet balance credited from 4100.00 to 6100.00 ($bal2AfterC === 6100.00)");

$ledgerC = $pdo->query("SELECT * FROM wallet_entries WHERE escrow_id = {$cC['escrow_id']} AND entry_type = 'escrow_refund'")->fetch(PDO::FETCH_ASSOC);
$check(is_array($ledgerC), 'IT-04: Ledger refund entry created');
$check((int)$ledgerC['wallet_user_id'] === $client2Id, 'IT-04: Ledger recipient is Client 2');
$check($ledgerC['amount_delta'] === '2000.00', 'IT-04: Ledger amount_delta is 2000.00');
$check($ledgerC['source'] === 'admin_decision', 'IT-04: Ledger source is admin_decision');
$check((int)$ledgerC['actor_id'] === $admin1Id, 'IT-04: Ledger actor_id is Admin 1');
$check($ledgerC['operation_key'] === 'dispute_refund:job:' . $cC['job_id'] . ':dispute:' . $disputeCId, 'IT-04: Operation key format verified');
$check((int)$ledgerC['settlement_escrow_id'] === $cC['escrow_id'], 'IT-04: STORED settlement_escrow_id verified');
echo "  [PASS] IT-04 passed.\n\n";

// =============================================================================
// IT-05: ADMIN DISPUTE RESOLUTION — FULL PAYOUT TO FREELANCER
// =============================================================================
echo "Running IT-05: Admin Dispute Resolution — Full Payout to Freelancer...\n";
$res5A = $disputeService->raiseDispute($client2Id, $cD['job_id'], 'Dispute regarding milestone deliverables quality');
$disputeDId = (int)$res5A['dispute_id'];

$res5B = $disputeService->resolveDispute($admin1Id, $disputeDId, 'pay_freelancer', 'Milestone deliverables meet requirements; payout awarded');
$check($res5B['decision'] === 'pay_freelancer' && (int)$res5B['recipient_id'] === $freelancer2Id, 'IT-05: Dispute resolved with pay_freelancer');

$dispDFinal = $disputeRepo->getDisputeById($disputeDId);
$check($dispDFinal['status'] === 'resolved' && $dispDFinal['decision'] === 'pay_freelancer', 'IT-05: Dispute row resolved with pay_freelancer');

$jobDFinal = $disputeRepo->getJob($cD['job_id']);
$escrowDFinal = $disputeRepo->getEscrow($cD['job_id']);
$check($jobDFinal['status'] === 'closed_disputed' && $jobDFinal['closed_at'] !== null, 'IT-05: Job transitioned to closed_disputed with closed_at');
$check($escrowDFinal['status'] === 'released' && $escrowDFinal['settled_at'] !== null, 'IT-05: Escrow transitioned to released with settled_at');

$free2Bal = (string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $freelancer2Id")->fetchColumn();
$check($free2Bal === '2500.00', "IT-05: Freelancer 2 wallet balance credited from 0.00 to 2500.00 ($free2Bal === 2500.00)");

$ledgerD = $pdo->query("SELECT * FROM wallet_entries WHERE escrow_id = {$cD['escrow_id']} AND entry_type = 'escrow_release'")->fetch(PDO::FETCH_ASSOC);
$check(is_array($ledgerD), 'IT-05: Ledger release entry created');
$check((int)$ledgerD['wallet_user_id'] === $freelancer2Id, 'IT-05: Ledger recipient is Freelancer 2');
$check($ledgerD['amount_delta'] === '2500.00', 'IT-05: Ledger amount_delta is 2500.00');
$check($ledgerD['source'] === 'admin_decision', 'IT-05: Ledger source is admin_decision');
$check((int)$ledgerD['actor_id'] === $admin1Id, 'IT-05: Ledger actor_id is Admin 1');
$check($ledgerD['operation_key'] === 'dispute_release:job:' . $cD['job_id'] . ':dispute:' . $disputeDId, 'IT-05: Operation key matches expected format');
$check((int)$ledgerD['settlement_escrow_id'] === $cD['escrow_id'], 'IT-05: STORED settlement_escrow_id matches escrow');
echo "  [PASS] IT-05 passed.\n\n";

// =============================================================================
// IT-06: DOUBLE-SETTLEMENT PREVENTION & UNIQUE CONSTRAINTS
// =============================================================================
echo "Running IT-06: Double-Settlement Prevention & Unique Constraints...\n";
// 6A: Service level rejection
try {
    $disputeService->resolveDispute($admin1Id, $disputeDId, 'pay_freelancer', 'Repeat attempt to resolve');
    $check(false, 'IT-06A: Service allowed duplicate dispute resolution');
} catch (DisputeException $e) {
    $check($e->getCode() === 409, 'IT-06A: Service rejected duplicate dispute resolution with 409 Conflict');
}

try {
    $disputeService->respondCancellation($client1Id, $cB['job_id'], 'accept');
    $check(false, 'IT-06A: Service allowed duplicate cancellation response');
} catch (DisputeException $e) {
    $check($e->getCode() === 409, 'IT-06A: Service rejected duplicate cancellation response with 409 Conflict');
}

// 6B: Engine level generated column uniqueness
try {
    // Attempt direct SQL insertion of duplicate settlement_escrow_id
    $pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key, created_at) VALUES (?, ?, 'escrow_release', '100.00', 'admin_decision', ?, 'bogus_op_key_1', UTC_TIMESTAMP())")->execute([$freelancer2Id, $cD['escrow_id'], $admin1Id]);
    $check(false, 'IT-06B: Engine allowed duplicate settlement_escrow_id');
} catch (PDOException $e) {
    $check($e->getCode() === '23000' && str_contains($e->getMessage(), '1062'), 'IT-06B: Engine rejected duplicate settlement_escrow_id with Error 1062');
}

// 6C: Operation key uniqueness
try {
    $existingOpKey = 'dispute_release:job:' . $cD['job_id'] . ':dispute:' . $disputeDId;
    $pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key, created_at) VALUES (?, NULL, 'demo_credit', '100.00', 'demo_seed', ?, ?, UTC_TIMESTAMP())")->execute([$client1Id, $client1Id, $existingOpKey]);
    $check(false, 'IT-06C: Engine allowed duplicate operation_key');
} catch (PDOException $e) {
    $check($e->getCode() === '23000' && str_contains($e->getMessage(), '1062'), 'IT-06C: Engine rejected duplicate operation_key with Error 1062');
}
echo "  [PASS] IT-06 passed.\n\n";

// =============================================================================
// IT-07: TRANSACTION ROLLBACK ON CONTROLLED FAILURE
// =============================================================================
echo "Running IT-07: Transaction Rollback on Controlled Failure...\n";
$res7 = $disputeService->raiseDispute($client1Id, $cG['job_id'], 'Pre-failure dispute escalation');
$disputeGId = (int)$res7['dispute_id'];

// Controlled test failure injection via mockable wrapper or synthetic transaction abort
$bal1BeforeFail = (string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $client1Id")->fetchColumn();
$escrowGBeforeFail = $disputeRepo->getEscrow($cG['job_id']);

try {
    $pdo->beginTransaction();
    // Simulate mid-flight database exception after status update
    $pdo->prepare("UPDATE escrows SET status = 'refunded', settled_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$cG['escrow_id']]);
    // Intentionally trigger a foreign key violation to force mid-flight exception
    $pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key, created_at) VALUES (9999999, ?, 'escrow_refund', '700.00', 'admin_decision', 9999999, 'injected_fail_key', UTC_TIMESTAMP())")->execute([$cG['escrow_id']]);
    $pdo->commit();
    $check(false, 'IT-07: Injected failure did not throw');
} catch (PDOException) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$escrowGAfterFail = $disputeRepo->getEscrow($cG['job_id']);
$check($escrowGAfterFail['status'] === 'locked' && $escrowGAfterFail['settled_at'] === null, 'IT-07: Escrow remains locked after rollback');

$jobGAfterFail = $disputeRepo->getJob($cG['job_id']);
$check($jobGAfterFail['status'] === 'disputed', 'IT-07: Job remains disputed after rollback');

$bal1AfterFail = (string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $client1Id")->fetchColumn();
$check($bal1AfterFail === $bal1BeforeFail, 'IT-07: Wallet balance unchanged after rollback');

$failEntries = (int)$pdo->query("SELECT COUNT(*) FROM wallet_entries WHERE escrow_id = {$cG['escrow_id']} AND entry_type = 'escrow_refund'")->fetchColumn();
$check($failEntries === 0, 'IT-07: Zero refund entries committed after rollback');
echo "  [PASS] IT-07 passed.\n\n";

// =============================================================================
// IT-08: MISSING-WALLET INITIALIZATION
// =============================================================================
echo "Running IT-08: Missing-Wallet Safe Initialization...\n";
// Confirm Freelancer 3 has no wallet row
$hasWallet3 = $pdo->query("SELECT COUNT(*) FROM wallets WHERE user_id = $freelancer3Id")->fetchColumn();
$check((int)$hasWallet3 === 0, 'IT-08: Freelancer 3 initially has no wallet row');

$res8A = $disputeService->raiseDispute($client2Id, $cH['job_id'], 'Contract H dispute for missing wallet test');
$disputeHId = (int)$res8A['dispute_id'];

$res8B = $disputeService->resolveDispute($admin1Id, $disputeHId, 'pay_freelancer', 'Payout awarded to beneficiary without prior wallet');
$check($res8B['decision'] === 'pay_freelancer' && (int)$res8B['recipient_id'] === $freelancer3Id, 'IT-08: Dispute resolved awarding payout to Freelancer 3');

$bal3 = (string)$pdo->query("SELECT balance FROM wallets WHERE user_id = $freelancer3Id")->fetchColumn();
$check($bal3 === '800.00', "IT-08: Wallet row created automatically and credited to 800.00 ($bal3 === 800.00)");

$ledgerH = $pdo->query("SELECT * FROM wallet_entries WHERE escrow_id = {$cH['escrow_id']} AND entry_type = 'escrow_release'")->fetch(PDO::FETCH_ASSOC);
$check(is_array($ledgerH) && $ledgerH['amount_delta'] === '800.00', 'IT-08: Ledger release entry created for beneficiary');
echo "  [PASS] IT-08 passed.\n\n";

// =============================================================================
// IT-09: PARTICIPANT OWNERSHIP & ACTIVE ADMIN AUTHORIZATION
// =============================================================================
echo "Running IT-09: Participant Ownership & Active Admin Authorization...\n";
// 9A: Unrelated user cannot raise dispute or respond
try {
    $disputeService->requestCancellation($user3Id, $cA['job_id'], 'Unauthorized cancellation request');
    $check(false, 'IT-09A: Non-participant allowed to request cancellation');
} catch (DisputeException $e) {
    $check($e->getCode() === 403, 'IT-09A: Non-participant rejected with 403 Forbidden');
}

// 9B: Suspended admin cannot adjudicate dispute
try {
    $disputeService->resolveDispute($admin2Id, $disputeGId, 'refund_client', 'Suspended admin adjudication attempt');
    $check(false, 'IT-09B: Suspended admin allowed to resolve dispute');
} catch (DisputeException $e) {
    $check($e->getCode() === 403, 'IT-09B: Suspended admin rejected with 403 Forbidden');
}

// 9C: Suspended admin cannot inspect dossier
try {
    $disputeService->getDisputeDossier($admin2Id, $disputeGId);
    $check(false, 'IT-09C: Suspended admin allowed to inspect dispute dossier');
} catch (DisputeException $e) {
    $check($e->getCode() === 403, 'IT-09C: Suspended admin rejected with 403 Forbidden');
}
echo "  [PASS] IT-09 passed.\n\n";

// =============================================================================
// IT-10: REAL MULTI-PROCESS CONCURRENCY TESTS
// =============================================================================
echo "Running IT-10: Real Multi-Process Concurrency Tests...\n";

// Scenario 10A: Competing Admin Resolutions on Contract E
echo "  Sub-test 10A: Competing Admin Dispute Resolutions (Process A vs Process B)...\n";
$res10A_init = $disputeService->raiseDispute($client1Id, $cE['job_id'], 'Contract E concurrency dispute');
$disputeEId = (int)$res10A_init['dispute_id'];

$phpCli = 'C:\xampp\php\php.exe';
$script = __FILE__;
$tmpDir = 'C:\MariaDB_Recovery\GigHub_Clean_Test\tmp';
$sentinel10A = $tmpDir . '\sentinel_10a_' . bin2hex(random_bytes(4)) . '.tmp';

$cmdA = sprintf(
    '"%s" "%s" --run-isolated --worker=admin_resolve %d %d %d %d refund_client holder "%s"',
    $phpCli,
    $script,
    $cE['job_id'],
    $disputeEId,
    $cE['escrow_id'],
    $admin1Id,
    $sentinel10A
);

$cmdB = sprintf(
    '"%s" "%s" --run-isolated --worker=admin_resolve %d %d %d %d pay_freelancer competitor "%s"',
    $phpCli,
    $script,
    $cE['job_id'],
    $disputeEId,
    $cE['escrow_id'],
    $admin1Id,
    $sentinel10A
);

// Spawn Process A (holder)
$desc = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$procA = proc_open($cmdA, $desc, $pipesA);
$check(is_resource($procA), 'IT-10A: Background Process A spawned');

// Wait for Process A to signal it acquired the lock on job row
$wCount = 0;
while (!file_exists($sentinel10A) && $wCount < 40) {
    usleep(25000);
    ++$wCount;
}

// Spawn Process B (competitor) while Process A is holding lock
$procB = proc_open($cmdB, $desc, $pipesB);
$check(is_resource($procB), 'IT-10A: Background Process B spawned');

$outA = stream_get_contents($pipesA[1]);
fclose($pipesA[1]);
fclose($pipesA[2]);
$exitA = proc_close($procA);

$outB = stream_get_contents($pipesB[1]);
fclose($pipesB[1]);
fclose($pipesB[2]);
$exitB = proc_close($procB);

if (file_exists($sentinel10A)) {
    unlink($sentinel10A);
}

$check($exitA === 0, 'IT-10A: Process A completed cleanly (exit code 0)');
$check($exitB === 0, 'IT-10A: Process B completed cleanly (exit code 0)');

$jsonA = json_decode($outA, true);
$jsonB = json_decode($outB, true);

$check(is_array($jsonA) && ($jsonA['success'] ?? false) === true && ($jsonA['http_status'] ?? 0) === 200, 'IT-10A: Process A succeeded with 200 OK');
$check(is_array($jsonB) && ($jsonB['success'] ?? true) === false && ($jsonB['http_status'] ?? 0) === 409, 'IT-10A: Process B blocked on row lock and revalidated to 409 Conflict');

$ledgerECount = (int)$pdo->query("SELECT COUNT(*) FROM wallet_entries WHERE escrow_id = {$cE['escrow_id']} AND entry_type IN ('escrow_release', 'escrow_refund')")->fetchColumn();
$check($ledgerECount === 1, 'IT-10A: Exactly one settlement ledger entry committed');
echo "    [PASS] Sub-test 10A verified: Process A won, Process B blocked and returned 409 Conflict.\n";

// Scenario 10B: Competing Duplicate Cancellation Acceptance on Contract F
echo "  Sub-test 10B: Competing Duplicate Cancellation Acceptance (Process A vs Process B)...\n";
$res10B_init = $disputeService->requestCancellation($freelancer2Id, $cF['job_id'], 'Contract F concurrency cancellation request');
$sentinel10B = $tmpDir . '\sentinel_10b_' . bin2hex(random_bytes(4)) . '.tmp';

$cmdF_A = sprintf(
    '"%s" "%s" --run-isolated --worker=cancel_accept %d 0 %d %d accept holder "%s"',
    $phpCli,
    $script,
    $cF['job_id'],
    $cF['escrow_id'],
    $client2Id,
    $sentinel10B
);

$cmdF_B = sprintf(
    '"%s" "%s" --run-isolated --worker=cancel_accept %d 0 %d %d accept competitor "%s"',
    $phpCli,
    $script,
    $cF['job_id'],
    $cF['escrow_id'],
    $client2Id,
    $sentinel10B
);

$procF_A = proc_open($cmdF_A, $desc, $pipesF_A);
$wCountB = 0;
while (!file_exists($sentinel10B) && $wCountB < 40) {
    usleep(25000);
    ++$wCountB;
}

$procF_B = proc_open($cmdF_B, $desc, $pipesF_B);

$outF_A = stream_get_contents($pipesF_A[1]);
fclose($pipesF_A[1]);
fclose($pipesF_A[2]);
$exitF_A = proc_close($procF_A);

$outF_B = stream_get_contents($pipesF_B[1]);
fclose($pipesF_B[1]);
fclose($pipesF_B[2]);
$exitF_B = proc_close($procF_B);

if (file_exists($sentinel10B)) {
    unlink($sentinel10B);
}

$check($exitF_A === 0, 'IT-10B: Process A completed cleanly (exit code 0)');
$check($exitF_B === 0, 'IT-10B: Process B completed cleanly (exit code 0)');

$jsonF_A = json_decode($outF_A, true);
$jsonF_B = json_decode($outF_B, true);

$check(is_array($jsonF_A) && ($jsonF_A['success'] ?? false) === true && ($jsonF_A['http_status'] ?? 0) === 200, 'IT-10B: Process A accepted cancellation with 200 OK');
$check(is_array($jsonF_B) && ($jsonF_B['success'] ?? true) === false && ($jsonF_B['http_status'] ?? 0) === 409, 'IT-10B: Process B serialized on row lock and returned 409 Conflict');

$ledgerFCount = (int)$pdo->query("SELECT COUNT(*) FROM wallet_entries WHERE escrow_id = {$cF['escrow_id']} AND entry_type = 'escrow_refund'")->fetchColumn();
$check($ledgerFCount === 1, 'IT-10B: Exactly one cancellation refund committed');
echo "    [PASS] Sub-test 10B verified: Process A won, Process B blocked and returned 409 Conflict.\n";

echo "  [PASS] IT-10 passed.\n\n";

// -----------------------------------------------------------------------------
// POST-TEST FINANCIAL INTEGRITY & INVARIANT CHECKS
// -----------------------------------------------------------------------------
echo "[VERIFICATION] Running final financial accounting invariant verification...\n";
$totalDemoSeeded = '20000.00'; // Client 1 (10000.00) + Client 2 (10000.00)

$allWallets = $pdo->query("SELECT user_id, balance FROM wallets WHERE user_id IN ($client1Id, $client2Id, $freelancer1Id, $freelancer2Id, $freelancer3Id)")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalInWallets = '0.00';
foreach ($allWallets as $bal) {
    $totalInWallets = bcadd($totalInWallets, (string)$bal, 2);
}

// Active locked escrows created in this run
$runEscrowIds = implode(',', [$cA['escrow_id'], $cB['escrow_id'], $cC['escrow_id'], $cD['escrow_id'], $cE['escrow_id'], $cF['escrow_id'], $cG['escrow_id'], $cH['escrow_id']]);
$totalInEscrows = (string)$pdo->query("SELECT COALESCE(SUM(amount), 0.00) FROM escrows WHERE status = 'locked' AND id IN ($runEscrowIds)")->fetchColumn();
$systemTotal = bcadd($totalInWallets, $totalInEscrows, 2);

$check($systemTotal === $totalDemoSeeded, "Conservation of funds verified: Wallets ($totalInWallets) + Locked Escrows ($totalInEscrows) === Seeded ($totalDemoSeeded)");

// Assert zero violations on append-only ledger
$ledgerViolations = (int)$pdo->query("SELECT COUNT(*) FROM wallet_entries WHERE amount_delta = 0 OR amount_delta IS NULL")->fetchColumn();
$check($ledgerViolations === 0, 'Zero ledger entry delta anomalies');

echo "===================================================================\n";
echo " MODULE 21 INTEGRATION TEST SUITE SUMMARY\n";
echo " Checks executed and passed: $checks\n";
echo " Failures: 0\n";
echo " Status: PASS\n";
echo "===================================================================\n";
