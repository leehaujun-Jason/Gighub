<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
foreach ([
    'Core/Csrf',
    'Core/AuthSession',
    'Core/AuthGuard',
    'Core/Router',
    'Repositories/UserRepository',
    'Services/LoginValidator',
    'Services/AuthenticationService',
    'Repositories/WalletRepository',
    'Services/WalletException',
    'Services/WalletService',
    'Controllers/WalletController',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

use GigHub\Core\Csrf;
use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Repositories\UserRepository;
use GigHub\Services\AuthenticationService;
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
    } catch (WalletException $e) {
        check($e->status === $status, $label);
    }
}

class WalletTestPDO extends PDO {
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

$pdo = new WalletTestPDO();

$repo = new class extends WalletRepository {
    public bool $clientEligible = true;
    public ?array $walletRow = null;
    public array $entries = [];
    public bool $failUpdate = false;
    public bool $failInsert = false;
    public bool $duplicateRace = false;
    public array $events = [];

    public function __construct() {}

    public function eligibleClient(int $id, bool $lock = false): bool {
        $this->events[] = 'client:' . ($lock ? 'lock' : 'read');
        return $this->clientEligible;
    }

    public function getWallet(int $userId, bool $lock = false): array|false {
        $this->events[] = 'wallet:' . ($lock ? 'lock' : 'read');
        return $this->walletRow ?? false;
    }

    public function ensureWallet(int $userId): void {
        $this->events[] = 'ensure_wallet';
        if ($this->walletRow === null) {
            $this->walletRow = ['user_id' => $userId, 'balance' => '0.00', 'updated_at' => '2026-11-04 00:00:00'];
        }
    }

    public function hasDemoCredit(int $userId, bool $lock = false): bool {
        $this->events[] = 'demo_credit:' . ($lock ? 'lock' : 'read');
        foreach ($this->entries as $e) {
            if ($e['wallet_user_id'] === $userId && $e['entry_type'] === 'demo_credit') {
                return true;
            }
        }
        return false;
    }

    public function creditBalance(int $userId, string $amount): int {
        if ($this->failUpdate) throw new RuntimeException('database error on balance update');
        $this->events[] = 'credit_balance';
        if ($this->walletRow !== null && $this->walletRow['user_id'] === $userId) {
            $current = (float)$this->walletRow['balance'];
            $this->walletRow['balance'] = number_format($current + (float)$amount, 2, '.', '');
            return 1;
        }
        return 0;
    }

    public function insertEntry(int $userId, int $actorId, string $amount, string $operationKey): int {
        if ($this->duplicateRace) {
            $e = new PDOException('Duplicate entry \'demo:user:' . $userId . '\' for key \'uq_wallet_operation\'', 23000);
            $e->errorInfo = ['23000', 1062, 'Duplicate entry'];
            throw $e;
        }
        if ($this->failInsert) throw new RuntimeException('database error on ledger insert');
        $this->events[] = 'insert_entry';
        $entry = [
            'id' => count($this->entries) + 1,
            'wallet_user_id' => $userId,
            'escrow_id' => null,
            'entry_type' => 'demo_credit',
            'amount_delta' => $amount,
            'source' => 'demo_seed',
            'actor_id' => $actorId,
            'operation_key' => $operationKey,
            'created_at' => '2026-11-04 12:00:00',
        ];
        $this->entries[] = $entry;
        return $entry['id'];
    }

    public function getEntries(int $userId, int $page = 1): array {
        $this->events[] = 'get_entries';
        $userEntries = array_values(array_filter($this->entries, fn($e) => $e['wallet_user_id'] === $userId));
        return array_slice($userEntries, ($page - 1) * 20, 21);
    }

    public function isClientJobOwner(int $clientId, int $jobId): bool {
        return $jobId === 2 && $clientId === 10;
    }
};

$service = new WalletService($pdo, $repo);

// 1. Initial overview before credit
$overview = $service->getWalletOverview(10);
check($overview['wallet']['balance'] === '0.00', 'initial balance defaults to 0.00');
check($overview['has_demo_credit'] === false, 'demo credit initially not claimed');
check($overview['entries'] === [], 'initial entries list is empty');

// 2. Successful first-time claim
$service->claimDemoCredit(10);
check($repo->walletRow['balance'] === '10000.00', 'balance updated to RM10,000.00');
check(count($repo->entries) === 1, 'exactly one ledger entry created');
check($repo->entries[0]['operation_key'] === 'demo:user:10', 'operation key is demo:user:10');
check($repo->entries[0]['entry_type'] === 'demo_credit', 'entry type is demo_credit');
check($repo->entries[0]['amount_delta'] === '10000.00', 'amount delta is 10000.00');
check($pdo->events === ['begin', 'commit'], 'transaction started and committed');
check($repo->events === [
    'client:read', 'wallet:read', 'demo_credit:read', 'get_entries',
    'client:lock', 'demo_credit:lock', 'ensure_wallet', 'wallet:lock', 'credit_balance', 'insert_entry'
], 'proper lock order: client lock -> demo_credit lock -> wallet lock -> credit balance -> insert entry');

// 3. Overview after claim
$overviewAfter = $service->getWalletOverview(10);
check($overviewAfter['wallet']['balance'] === '10000.00', 'overview reflects 10000.00 balance');
check($overviewAfter['has_demo_credit'] === true, 'has_demo_credit is true');
check(count($overviewAfter['entries']) === 1, 'overview shows 1 ledger entry');

// 4. Duplicate claim rejected (HTTP 409)
$pdo->events = [];
denied(fn() => $service->claimDemoCredit(10), 409, 'repeat claim fails with 409');
check($repo->walletRow['balance'] === '10000.00', 'balance unchanged after rejected repeat');
check(count($repo->entries) === 1, 'no second entry created on repeat');
check($pdo->events === ['begin', 'rollback'], 'repeat claim rolled back transaction');

// 5. Ineligible Client rejected (HTTP 403)
$repo->clientEligible = false;
denied(fn() => $service->claimDemoCredit(11), 403, 'ineligible client denied claim');
denied(fn() => $service->getWalletOverview(11), 403, 'ineligible client denied overview');
$repo->clientEligible = true;

// 6. Concurrency / Unique constraint violation caught safely (HTTP 409)
$repo->walletRow = null;
$repo->entries = [];
$repo->duplicateRace = true;
$pdo->events = [];
denied(fn() => $service->claimDemoCredit(12), 409, 'concurrency duplicate key safely returns 409');
check($pdo->events === ['begin', 'rollback'], 'duplicate key transaction rolled back');
$repo->duplicateRace = false;

// 7. Atomic rollback on insertion error
$repo->walletRow = null;
$repo->entries = [];
$repo->failInsert = true;
$pdo->events = [];
$threw = false;
try {
    $service->claimDemoCredit(13);
} catch (RuntimeException $e) {
    $threw = true;
    check($e->getMessage() === 'Wallet service is temporarily unavailable.', 'internal SQL error masked');
}
check($threw, 'runtime exception thrown on failed insert');
check($pdo->events === ['begin', 'rollback'], 'transaction rolled back on insert failure');
$repo->failInsert = false;

// 8. Savepoint handling when outer transaction active
$pdo->active = true;
$pdo->events = [];
$repo->walletRow = null;
$repo->entries = [];
$service->claimDemoCredit(14);
check(in_array('SAVEPOINT gighub_wallet', $pdo->events, true), 'savepoint created');
check(in_array('RELEASE SAVEPOINT gighub_wallet', $pdo->events, true), 'savepoint released');
$pdo->active = false;

// 9. Controller tests
$users = new class extends UserRepository {
    public array $row = ['id' => 10, 'role' => 'client', 'display_name' => 'Test Client', 'status' => 'active', 'email_verified_at' => '2026-11-04 00:00:00'];
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false { return $this->row; }
};

$_SESSION = [];
$session = new AuthSession(null, static fn() => true);
$auth = new AuthenticationService($users);
$guard = new AuthGuard(static fn() => $auth, $session);
$controller = new WalletController($guard, fn() => $service);

// Anonymous user -> 303 redirect to login
$anonShow = $controller->show([]);
check($anonShow['status'] === 303 && $anonShow['location'] === '?route=/login', 'anonymous show redirects to login');
$anonClaim = $controller->claimDemoCredit(['csrf_token' => 'x']);
check($anonClaim['status'] === 303 && $anonClaim['location'] === '?route=/login', 'anonymous claim redirects to login');

// Authenticate Client 10
$session->establish(10);

// Controller GET show (uncredited state)
$repo->walletRow = null;
$repo->entries = [];
$showResp = $controller->show([]);
check($showResp['status'] === 200, 'show returns 200 for authenticated client');
check(str_contains($showResp['body'], 'Claim RM10,000 Demo Credit'), 'claim button shown when uncredited');
check(str_contains($showResp['body'], 'RM0.00'), 'balance RM0.00 displayed');

// Controller POST claim with invalid CSRF -> 403
$invalidCsrf = $controller->claimDemoCredit(['csrf_token' => 'invalid']);
check($invalidCsrf['status'] === 403, 'invalid CSRF rejected with 403');

// Controller POST claim with valid CSRF -> 303 PRG
$validToken = Csrf::token('wallet_credit');
$claimResp = $controller->claimDemoCredit(['csrf_token' => $validToken]);
check($claimResp['status'] === 303, 'valid claim returns 303 redirect');
check($claimResp['location'] === '?route=/client/wallet', 'redirects to ?route=/client/wallet');
check(isset($_SESSION['wallet_notice']), 'flash notice set in session');

// Controller GET show (credited state)
$showAfter = $controller->show([]);
check($showAfter['status'] === 200, 'show after credit returns 200');
check(str_contains($showAfter['body'], 'RM10,000.00'), 'balance RM10,000.00 displayed');
check(str_contains($showAfter['body'], 'Demo Credit Claimed'), 'badge displayed');
check(!str_contains($showAfter['body'], 'Claim RM10,000 Demo Credit'), 'claim button hidden once claimed');
check(str_contains($showAfter['body'], '+RM10,000.00'), 'entry delta rendered');
check(str_contains($showAfter['body'], 'Related Job'), 'Related Job column header rendered');
check(str_contains($showAfter['body'], 'General Wallet Activity'), 'fallback General Wallet Activity rendered for demo credit');
check(str_contains($showAfter['body'], '← Back to Client Workspace'), 'default back to client workspace link rendered');

$showWithJob = $controller->show(['job_id' => 2]);
check($showWithJob['status'] === 200, 'show with job_id returns 200');
check(str_contains($showWithJob['body'], '← Back to Job Milestones'), 'back to job milestones link rendered when job_id supplied');
check(str_contains($showWithJob['body'], '?route=/client/jobs/milestones&amp;job_id=2'), 'back to job milestones href correct');

// Role restrictions (Freelancer & Admin)
foreach (['freelancer', 'admin'] as $role) {
    $users->row['role'] = $role;
    $resp = $controller->show([]);
    check($resp['status'] === 403, "role $role forbidden from client wallet show");
    $claim = $controller->claimDemoCredit(['csrf_token' => $validToken]);
    check($claim['status'] === 403, "role $role forbidden from client wallet claim");
}
$users->row['role'] = 'client';

echo "PASS: {$count} wallet offline checks; no database connections.\n";
