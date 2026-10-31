<?php
declare(strict_types=1);
// Opt-in, rollback-only integration checks. No SMTP, DDL, DELETE or fixture commits.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') !== '--run-synthetic') {
    fwrite(STDERR, "Explicit opt-in required: php tests/verification-database.php --run-synthetic\n"); exit(2);
}
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$root = dirname(__DIR__); $pdo = null; $contender = null; $checks = 0; $failed = false; $phase = 'preflight';
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); } ++$checks;
};
try {
    foreach (['Core/Config','Core/Database','Core/Csrf','Mail/MailTransport','Mail/VerificationMail',
        'Mail/InMemoryMailTransport','Repositories/VerificationTokenRepository','Services/EmailVerificationService'] as $class) {
        require_once $root . '/app/' . $class . '.php';
    }
    ob_start(); try { $config = GigHub\Core\Config::load($root . '/config'); } finally { ob_end_clean(); }
    $pdo = (new GigHub\Core\Database($config['database']))->connection();
    $contender = (new GigHub\Core\Database($config['database']))->connection(); unset($config);
    $check($pdo->query('SELECT DATABASE()')->fetchColumn() === 'gighub_dev', 'Unexpected database');
    $check($pdo->query('SELECT CURRENT_USER()')->fetchColumn() === 'gighub_app@127.0.0.1', 'Unexpected account');
    $check(!$pdo->inTransaction(), 'Existing transaction is unsafe');
    $check($contender->query('SELECT DATABASE()')->fetchColumn() === 'gighub_dev'
        && $contender->query('SELECT CURRENT_USER()')->fetchColumn() === 'gighub_app@127.0.0.1'
        && !$contender->inTransaction(), 'Unsafe contention connection');
    // Connection-local and bounded; never change global settings.
    $contender->exec('SET SESSION innodb_lock_wait_timeout=1');
    $expected = explode(' ', 'users freelancer_profiles client_profiles email_verification_tokens skills user_skills jobs job_skills bids milestones work_submissions uploaded_files wallets wallet_entries escrows chat_messages notifications disputes cancellation_requests reviews'); sort($expected);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($tables);
    $check($tables === $expected, 'Unexpected table inventory');
    $check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND ENGINE='InnoDB'")->fetchColumn() === 20, 'Rollback safety requires InnoDB');
    $columns = [];
    foreach ($tables as $table) { $columns[$table] = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN); }
    $check($columns['email_verification_tokens'] === ['id','user_id','token_hash','expires_at','used_at','revoked_at','created_at'], 'Token schema mismatch');
    // Compare both counts and row-content hashes for every table. Nothing private is printed.
    $snapshot = static function () use ($pdo, $tables, $columns): array {
        $result = [];
        foreach ($tables as $table) {
            $order = implode(',', array_map(static fn ($column) => '`' . $column . '`', $columns[$table]));
            $query = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY ' . $order);
            $digest = hash_init('sha256'); $count = 0;
            while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) { hash_update($digest, serialize($row)); ++$count; }
            $result[$table] = ['count' => $count, 'hash' => hash_final($digest)];
        }
        return $result;
    };
    $before = $snapshot();
    $prefix = 'gighub-verification-test-' . bin2hex(random_bytes(8));
    $password = password_hash('Synthetic verification fixture only 2026!', PASSWORD_ARGON2ID);
    $check(password_get_info($password)['algoName'] === 'argon2id', 'Fixture hash unavailable');
    $now = new DateTimeImmutable('2030-01-01 12:00:00', new DateTimeZone('UTC'));
    $clock = static function () use (&$now): DateTimeImmutable { return $now; };
    $transport = new GigHub\Mail\InMemoryMailTransport();
    $service = new GigHub\Services\EmailVerificationService($pdo, $transport, 'https://gighub.example.invalid', $clock);
    $pdo->beginTransaction(); $_SESSION = []; $phase = 'fixtures';
    $fixture = static function (string $name, string $role = 'client', string $status = 'pending_verification', ?string $verified = null) use ($pdo, $prefix, $password): array {
        $email = $prefix . '-' . $name . '@example.invalid';
        $query = $pdo->prepare('INSERT INTO users (email,password_hash,display_name,role,status,email_verified_at) VALUES (?,?,?,?,?,?)');
        $query->execute([$email,$password,'Synthetic Verification Test',$role,$status,$verified]); $id = (int) $pdo->lastInsertId();
        if ($role === 'client') { $query = $pdo->prepare('INSERT INTO client_profiles (user_id,company_name,bio) VALUES (?,?,?)'); $query->execute([$id,'Synthetic Test','Rollback only']); }
        elseif ($role === 'freelancer') { $query = $pdo->prepare('INSERT INTO freelancer_profiles (user_id,bio) VALUES (?,?)'); $query->execute([$id,'Rollback only']); }
        return ['id' => $id, 'email' => $email];
    };
    $user = static function (array $fixture) use ($pdo): array { $query = $pdo->prepare('SELECT * FROM users WHERE id=?'); $query->execute([$fixture['id']]); return $query->fetch(); };
    $tokens = static function (array $fixture) use ($pdo): array { $query = $pdo->prepare('SELECT * FROM email_verification_tokens WHERE user_id=? ORDER BY id'); $query->execute([$fixture['id']]); return $query->fetchAll(); };
    $issued = static function (array $fixture) use ($service, $check): GigHub\Mail\VerificationMail {
        $mail = $service->issue($fixture['email']); $check($mail instanceof GigHub\Mail\VerificationMail, 'Expected issuance'); return $mail;
    };
    $phase = 'expiry and replay';
    $client = $fixture('client'); $mail = $issued($client); $rows = $tokens($client);
    $phase = 'bounded user lock contention';
    $contender->beginTransaction(); $timedOut = false;
    try {
        $lockQuery = $contender->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
        $lockQuery->execute([$client['id']]);
    } catch (PDOException $error) {
        // Driver error 1205 is the expected lock wait timeout; never print details.
        $timedOut = (int) ($error->errorInfo[1] ?? 0) === 1205;
    } finally {
        if ($contender->inTransaction()) { $contender->rollBack(); }
    }
    $check($timedOut && $pdo->inTransaction() && !$contender->inTransaction(), 'Second connection did not wait on fixture user lock');
    $phase = 'expiry and replay';
    $check(count($rows) === 1 && preg_match('/\A[1-9][0-9]*\.[0-9a-f]{64}\z/', $mail->key) === 1, 'Malformed key');
    [$selector,$secret] = explode('.', $mail->key);
    $check((string) $rows[0]['id'] === $selector && $rows[0]['user_id'] == $client['id'], 'Wrong selector binding');
    $check(preg_match('/\A[0-9a-f]{64}\z/', $rows[0]['token_hash']) === 1 && !str_contains(serialize($rows[0]), $secret), 'Raw secret persisted');
    $expectedHash = hash('sha256', json_encode(['gighub-email-verification',(string) $client['id'],$client['email'],$secret],JSON_THROW_ON_ERROR));
    $check(hash_equals($expectedHash,$rows[0]['token_hash']) && $rows[0]['token_hash'] !== hash('sha256',$secret), 'Token lacks user/current-email context binding');
    $check($rows[0]['created_at'] === '2030-01-01 12:00:00' && $rows[0]['expires_at'] === '2030-01-01 12:30:00', 'Expiry is not exactly 30 minutes');
    $check($mail->url === 'https://gighub.example.invalid/index.php?route=/verify-email#verification=' . $mail->key, 'Wrong trusted fragment URL');
    $check($service->verify($selector . '.' . str_repeat('0',64)) === 'invalid', 'Wrong secret accepted');
    $check($user($client)['status'] === 'pending_verification' && $tokens($client)[0]['used_at'] === null, 'Failed verification mutated account');
    $now = $now->modify('+1799 seconds'); $check($service->verify($mail->key) === 'verified', '1799-second token rejected');
    $activated = $user($client); $used = $tokens($client);
    $check($activated['status'] === 'active' && $activated['email_verified_at'] === '2030-01-01 12:29:59', 'Activation incorrect');
    $check($used[0]['used_at'] !== null && $used[0]['revoked_at'] === null, 'Used token state incorrect');
    $check($service->verify($mail->key) === 'invalid' && $user($client) === $activated && $tokens($client) === $used, 'Replay changed state');
    $expiry = $fixture('expiry','freelancer'); $expired = $issued($expiry); $now = $now->modify('+1800 seconds');
    $check($service->verify($expired->key) === 'invalid' && $user($expiry)['status'] === 'pending_verification', '1800-second token accepted');
    $phase = 'cooldown and hourly cap';
    $limited = $fixture('limits'); $start = $now; $first = $issued($limited);
    $now = $start->modify('+59 seconds'); $check($service->issue($limited['email']) === null && count($tokens($limited)) === 1, '59-second cooldown failed');
    $now = $start->modify('+60 seconds'); $replacement = $issued($limited);
    $check($service->verify($first->key) === 'invalid', 'Replaced key accepted');
    $check($tokens($limited)[0]['revoked_at'] !== null && $tokens($limited)[0]['used_at'] === null, 'Replaced token not revoked');
    foreach ([120,180,240] as $seconds) { $now = $start->modify('+' . $seconds . ' seconds'); $issued($limited); }
    $now = $start->modify('+300 seconds'); $check($service->issue($limited['email']) === null && count($tokens($limited)) === 5, 'Five-per-hour cap failed');
    $now = $start->modify('+3599 seconds'); $check($service->issue($limited['email']) === null, 'Hourly cap released early');
    $now = $start->modify('+3600 seconds'); $issued($limited); $check(count($tokens($limited)) === 6, 'Rolling hour boundary failed');
    $phase = 'savepoint failure atomicity';
    $atomic = $fixture('atomic-issuance'); $atomicMail = $issued($atomic); $atomicRows = $tokens($atomic); $now = $now->modify('+60 seconds');
    $failingInsert = new class($pdo) extends GigHub\Repositories\VerificationTokenRepository {
        public function insert(int $userId, string $hash, string $created, string $expires): int { throw new RuntimeException('Synthetic insertion failure'); }
    };
    $failingService = new GigHub\Services\EmailVerificationService($pdo,$transport,'https://gighub.example.invalid/',$clock,$failingInsert);
    $refused = false; try { $failingService->issue($atomic['email']); } catch (RuntimeException $error) { $refused = true; }
    $check($refused && $pdo->inTransaction() && $tokens($atomic) === $atomicRows, 'Failed insertion did not restore outstanding token through savepoint');
    $failingActivation = new class($pdo) extends GigHub\Repositories\VerificationTokenRepository {
        public function activate(int $userId, string $now): bool { return false; }
    };
    $failingService = new GigHub\Services\EmailVerificationService($pdo,$transport,'https://gighub.example.invalid/',$clock,$failingActivation);
    $atomicUser = $user($atomic); $refused = false;
    try { $failingService->verify($atomicMail->key); } catch (RuntimeException $error) { $refused = true; }
    $check($refused && $pdo->inTransaction() && $tokens($atomic) === $atomicRows && $user($atomic) === $atomicUser, 'Failed activation left token consumed or user changed');
    $check($service->verify($atomicMail->key) === 'verified', 'Savepoint rollback lost previous valid token');
    $phase = 'account and email binding';
    $free = $fixture('freelancer-success','freelancer'); $check($service->verify($issued($free)->key) === 'verified', 'Eligible freelancer not activated');
    $bound = $fixture('bound'); $boundMail = $issued($bound);
    $other = $fixture('other'); $otherMail = $issued($other); [$otherSelector] = explode('.', $otherMail->key); [, $boundSecret] = explode('.', $boundMail->key);
    $check($service->verify($otherSelector . '.' . $boundSecret) === 'invalid', 'Wrong user selector accepted');
    $query = $pdo->prepare('UPDATE users SET email=? WHERE id=?'); $query->execute([$prefix . '-changed@example.invalid',$bound['id']]);
    $check($service->verify($boundMail->key) === 'invalid', 'Token accepted after email change');
    $suspended = $fixture('suspend-after-issue'); $suspendedMail = $issued($suspended);
    $query = $pdo->prepare('UPDATE users SET status=? WHERE id=?'); $query->execute(['suspended',$suspended['id']]);
    $check($service->verify($suspendedMail->key) === 'invalid' && $user($suspended)['status'] === 'suspended', 'Suspended account reactivated');
    foreach ([['active','client','active','2029-01-01 00:00:00'],['suspended','freelancer','suspended',null],['admin','admin','pending_verification',null],['already-verified','client','pending_verification','2029-01-01 00:00:00']] as [$name,$role,$status,$verified]) {
        $ineligible = $fixture($name,$role,$status,$verified); $state = $user($ineligible);
        $check($service->issue($ineligible['email']) === null && $tokens($ineligible) === [] && $user($ineligible) === $state, 'Ineligible issuance');
    }
    $check($service->issue($prefix . '-unknown@example.invalid') === null, 'Unknown account issuance');
    foreach (['', '0.' . str_repeat('a',64), '1.' . str_repeat('A',64), '1.short', '99999999999999999999.' . str_repeat('a',64)] as $invalid) { $check($service->verify($invalid) === 'invalid', 'Malformed key accepted'); }
    $phase = 'transaction send guard';
    $guarded = $fixture('request-guard'); $tokenState = $tokens($guarded); $refused = false;
    try { $service->request($guarded['email']); } catch (RuntimeException $error) { $refused = true; }
    $check($refused && $pdo->inTransaction() && $tokens($guarded) === $tokenState, 'Request did not refuse outer transaction before writes');
    $check($transport->attempts === 0 && $transport->messages === [], 'Email attempted before fixture commit');
    $check(!isset($_SESSION['user_id']) && !isset($_SESSION['role']), 'Automatic login side effect');
    $during = $snapshot();
    foreach ($tables as $table) { if (!in_array($table,['users','client_profiles','freelancer_profiles','email_verification_tokens'],true)) { $check($during[$table] === $before[$table], 'Unexpected table mutation'); } }
    $check($pdo->inTransaction(), 'Service ended outer rollback boundary');
    $pdo->rollBack(); $phase = 'post-rollback';
    $check($transport->attempts === 0 && $transport->messages === [], 'Email attempted after rollback');
    $check($snapshot() === $before, 'Database counts or row hashes changed after rollback');
    $query = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email LIKE ?'); $query->execute([$prefix . '%']);
    $check((int) $query->fetchColumn() === 0, 'Synthetic fixtures remain');
    echo "PASS: {$checks} verification database checks; all 20 table counts/content hashes unchanged after rollback.\n";
    echo "LIMIT: Bounded second-connection contention proves the fixture user lock serializes access; it does not simulate a complete verification/resend race. No successful request fixture commit. Auto-increment counters may advance despite rollback.\n";
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, "FAIL: Verification database validation stopped during {$phase}; private details suppressed.\n");
} finally {
    if ($contender instanceof PDO && $contender->inTransaction()) { $contender->rollBack(); }
    if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
}
exit($failed ? 1 : 0);
