<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (count($argv) !== 2 || ($argv[1] ?? '') !== '--run-isolated') {
    fwrite(STDERR, "Explicit opt-in required: php tests/registration-database.php --run-isolated\n"); exit(2);
}
ini_set('display_errors', '0'); ini_set('log_errors', '0');
$root = dirname(__DIR__); $pdo = null; $checks = 0; $failed = false; $prefix = '';
$check = static function (bool $value, string $label) use (&$checks): void {
    if (!$value) { throw new RuntimeException($label); } $checks++;
};
try {
    require_once __DIR__ . '/integration-bootstrap.php';
    foreach (['Core/Csrf', 'Core/RegistrationThrottle', 'Repositories/UserRepository',
        'Services/RegistrationValidator', 'Services/RegistrationValidationException',
        'Services/RegistrationService', 'Controllers/RegistrationController'] as $class) {
        require_once $root . '/app/' . $class . '.php';
    }
    $pdo = GigHub\Tests\IntegrationDatabase::connection($argv);
    $check($pdo->query('SELECT DATABASE()')->fetchColumn() === 'gighub_schema_test', 'Unexpected database');
    $check($pdo->query('SELECT CURRENT_USER()')->fetchColumn() === 'gighub_test@127.0.0.1', 'Unexpected account');
    $check(!$pdo->inTransaction(), 'Existing transaction is unsafe');
    $expected = explode(' ', 'users freelancer_profiles client_profiles email_verification_tokens skills user_skills jobs job_skills bids milestones work_submissions uploaded_files wallets wallet_entries escrows chat_messages notifications disputes cancellation_requests reviews');
    sort($expected);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); sort($tables);
    $check($tables === $expected, 'Unexpected table inventory');
    $check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND ENGINE='InnoDB'")->fetchColumn() === 20, 'Rollback safety requires InnoDB');
    foreach (['users' => ['id','email','password_hash','display_name','role','status','email_verified_at','created_at','updated_at'],
        'client_profiles' => ['user_id','company_name','bio'], 'freelancer_profiles' => ['user_id','bio','portfolio_description','portfolio_url']] as $table => $columns) {
        $actual = $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN);
        $check($actual === $columns, 'Live schema mismatch: ' . $table);
    }
    $counts = static function () use ($pdo, $tables): array {
        $result = []; foreach ($tables as $table) { $result[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); } return $result;
    };
    $before = $counts();
    $prefix = 'gighub-registration-test-' . bin2hex(random_bytes(8));
    $lookup = static function (string $email) use ($pdo): array|false {
        $query = $pdo->prepare('SELECT * FROM users WHERE email=?'); $query->execute([$email]); return $query->fetch();
    };
    $input = static fn (string $name, string $role = 'client'): array => [
        'display_name' => 'Synthetic Registration Test', 'email' => $prefix . '-' . $name . '@example.invalid',
        'password' => 'Twelve words', 'password_confirmation' => 'Twelve words',
        'role' => $role, 'company_name' => $role === 'client' ? 'Synthetic Company' : '', 'bio' => 'Synthetic test only',
        'portfolio_url' => 'javascript:ignored', 'portfolio_description' => 'Ignored stale profile data',
    ];
    $pdo->beginTransaction();
    $_SESSION = [];
    $repository = new GigHub\Repositories\UserRepository($pdo);
    $service = new GigHub\Services\RegistrationService($pdo, $repository);
    $client = $input('client'); $client['status'] = 'active'; $client['email_verified_at'] = '2020-01-01';
    $service->register($client); $row = $lookup($client['email']);
    $check(is_array($row), 'Client registration did not create user');
    $check($row['role'] === 'client' && $row['status'] === 'pending_verification' && $row['email_verified_at'] === null, 'Unsafe client account state');
    $check($row['password_hash'] !== $client['password'] && password_verify($client['password'], $row['password_hash']), 'Password hashing failed');
    $check(password_get_info($row['password_hash'])['algoName'] === 'argon2id', 'Wrong hash algorithm');
    $id = (int) $row['id'];
    $check((int) $pdo->query('SELECT COUNT(*) FROM client_profiles WHERE user_id=' . $id)->fetchColumn() === 1, 'Client profile missing');
    $check((int) $pdo->query('SELECT COUNT(*) FROM freelancer_profiles WHERE user_id=' . $id)->fetchColumn() === 0, 'Wrong-role profile created');
    $freelancer = $input('freelancer', 'freelancer'); $service->register($freelancer); $free = $lookup($freelancer['email']);
    $check($free !== false && $free['role'] === 'freelancer' && $free['status'] === 'pending_verification' && $free['email_verified_at'] === null, 'Unsafe freelancer state');
    $check((int) $pdo->query('SELECT COUNT(*) FROM freelancer_profiles WHERE user_id=' . (int) $free['id'])->fetchColumn() === 1, 'Freelancer profile missing');
    $profile = $pdo->query('SELECT portfolio_url, portfolio_description FROM freelancer_profiles WHERE user_id=' . (int) $free['id'])->fetch();
    $check($profile['portfolio_url'] === null && $profile['portfolio_description'] === null, 'Registration saved deferred portfolio data');
    try { $service->register($client); throw new LogicException('Duplicate accepted'); }
    catch (GigHub\Services\RegistrationValidationException $error) { $check(($error->errors['email'] ?? '') === 'This email is already registered.', 'Duplicate precheck missing feedback'); }
    // Force the uniqueness race path without racing unrelated requests.
    $raceRepository = new class($pdo) extends GigHub\Repositories\UserRepository {
        public function emailExists(string $email): bool { return false; }
    };
    try { (new GigHub\Services\RegistrationService($pdo, $raceRepository))->register($client); throw new LogicException('Duplicate race accepted'); }
    catch (GigHub\Services\RegistrationValidationException $error) { $check(($error->errors['email'] ?? '') === 'This email is already registered.' && !str_contains($error->getMessage(), 'SQLSTATE'), 'Unique race not safely handled'); }
    $check($pdo->inTransaction(), 'Failed registration ended the outer transaction');
    // Real strict-mode SQL failure after the user INSERT, not a schema/permission change.
    $failureRepository = new class($pdo) extends GigHub\Repositories\UserRepository {
        public function insertProfile(int $userId, array $data): void {
            parent::insertProfile($userId, array_replace($data, ['company_name' => str_repeat('x', 101)]));
        }
    };
    $rollbackInput = $input('rollback');
    try { (new GigHub\Services\RegistrationService($pdo, $failureRepository))->register($rollbackInput); throw new LogicException('Profile failure accepted'); }
    catch (RuntimeException $error) { $check($error->getMessage() === 'Registration is temporarily unavailable. Please try again.', 'Unsafe persistence failure message'); }
    $check($lookup($rollbackInput['email']) === false, 'User survived failed profile insert');
    $check($lookup($client['email']) !== false, 'Rollback affected an earlier account');
    $controller = new GigHub\Controllers\RegistrationController(static fn () => $service);
    $duplicate = $controller->submit(['csrf_token' => GigHub\Core\Csrf::token()] + $client);
    $check($duplicate['status'] === 422 && substr_count($duplicate['body'], 'This email is already registered.') === 1, 'Duplicate message repeated or missing');
    $check((bool) preg_match('/id="email"[^>]*aria-invalid="true"[^>]*aria-describedby="email-error"[^>]*><p class="field-error" id="email-error">This email is already registered\.<\/p>/', $duplicate['body']), 'Duplicate feedback not associated below email');
    $csrf = GigHub\Core\Csrf::token();
    $response = $controller->submit(['csrf_token' => $csrf] + $input('controller'));
    $check($response['status'] === 303 && $response['location'] === '?route=/register/pending', 'Success redirect failed');
    $check(!GigHub\Core\Csrf::valid($csrf), 'CSRF was not rotated');
    $check(array_diff(array_keys($_SESSION), ['registration_csrf','registration_notice','registration_attempts']) === [], 'Unexpected authenticated/session state');
    $check(!isset($_SESSION['user_id']) && !isset($_SESSION['role']), 'Automatic login detected');
    $notice = $controller->pending();
    $check($notice['status'] === 200 && str_contains($notice['body'], 'No verification email has been sent.'), 'Misleading completion notice');
    $check(!isset($_SESSION['registration_notice']), 'Completion notice not consumed');
    $check($controller->pending()['status'] === 303, 'Completion route not guarded');
    $during = $counts();
    foreach ($tables as $table) {
        if (!in_array($table, ['users','client_profiles','freelancer_profiles'], true)) { $check($during[$table] === $before[$table], 'Unexpected write to ' . $table); }
    }
    $check($during['users'] === $before['users'] + 3, 'Unexpected synthetic user count');
    $pdo->rollBack();
    $check($counts() === $before, 'Post-rollback counts changed');
    $query = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email LIKE ?'); $query->execute([$prefix . '%']);
    $check((int) $query->fetchColumn() === 0, 'Synthetic accounts remain');
    echo "PASS: {$checks} registration database checks; all synthetic rows rolled back; no wallets/tokens/credits created.\n";
} catch (Throwable $error) {
    $failed = true;
    // Never print driver details, config, input passwords or account hashes.
    fwrite(STDERR, "FAIL: Registration database validation stopped; private details suppressed.\n");
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
}
exit($failed ? 1 : 0);
