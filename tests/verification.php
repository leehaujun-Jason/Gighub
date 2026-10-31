<?php
declare(strict_types=1);
// Pure service tests: PDO/repository doubles never open a connection or write files.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
foreach (['Core/Csrf','Core/RegistrationThrottle','Mail/MailTransport','Mail/VerificationMail','Mail/InMemoryMailTransport',
    'Repositories/UserRepository','Services/RegistrationValidator','Services/RegistrationValidationException',
    'Services/RegistrationService','Controllers/RegistrationController',
    'Repositories/VerificationTokenRepository','Services/EmailVerificationService'] as $class) { require_once $root . '/app/' . $class . '.php'; }
use GigHub\Mail\MailTransport;
use GigHub\Mail\VerificationMail;
use GigHub\Mail\InMemoryMailTransport;
use GigHub\Repositories\VerificationTokenRepository;
use GigHub\Services\EmailVerificationService;

final class VerificationTestPdo extends PDO
{
    public bool $transaction = false;
    public array $events = [];
    public bool $failCommit = false;
    public function __construct() {}
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool { $this->events[] = 'begin'; $this->transaction = true; return true; }
    public function commit(): bool { $this->events[] = 'commit'; if ($this->failCommit) { throw new RuntimeException('Synthetic commit failure'); } $this->transaction = false; return true; }
    public function rollBack(): bool { $this->events[] = 'rollback'; $this->transaction = false; return true; }
    public function exec(string $statement): int|false {
        if (!in_array($statement, ['SAVEPOINT gighub_verification','RELEASE SAVEPOINT gighub_verification','ROLLBACK TO SAVEPOINT gighub_verification',
            'SAVEPOINT gighub_registration','RELEASE SAVEPOINT gighub_registration','ROLLBACK TO SAVEPOINT gighub_registration'], true)) { throw new LogicException('Unexpected SQL in pure test'); }
        $this->events[] = $statement; return 0;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { throw new LogicException('Database access prohibited'); }
}
final class VerificationTestRepository extends VerificationTokenRepository
{
    public array|false $user = ['id' => 42,'email' => 'synthetic@example.invalid','role' => 'client','status' => 'pending_verification','email_verified_at' => null];
    public array $history = ['latest' => null,'hourly' => 0];
    public array $records = [];
    public bool $failInsert = false;
    public bool $failActivation = false;
    public function __construct(private readonly VerificationTestPdo $connection) { parent::__construct($connection); }
    public function lockUserByEmail(string $email): array|false { $this->connection->events[] = 'lock-user-email'; return $this->user; }
    public function lockUser(int $id): array|false { $this->connection->events[] = 'lock-user'; return $this->user; }
    public function issuanceHistory(int $userId, string $cutoff, string $now): array { $this->connection->events[] = 'history'; return $this->history; }
    public function revokeOutstanding(int $userId, string $now): void {
        $this->connection->events[] = 'revoke';
        foreach ($this->records as &$record) { if ($record['used_at'] === null && $record['revoked_at'] === null) { $record['revoked_at'] = $now; } }
    }
    public function insert(int $userId, string $hash, string $created, string $expires): int {
        $this->connection->events[] = 'insert'; if ($this->failInsert) { throw new RuntimeException('Synthetic insert failure'); }
        $id = count($this->records) + 1;
        $this->records[$id] = ['id' => $id,'user_id' => $userId,'token_hash' => $hash,'created_at' => $created,'expires_at' => $expires,'used_at' => null,'revoked_at' => null]; return $id;
    }
    public function tokenUserId(int $id): int|false { $this->connection->events[] = 'selector'; return isset($this->records[$id]) ? $this->records[$id]['user_id'] : false; }
    public function lockToken(int $id, int $userId): array|false { $this->connection->events[] = 'lock-token'; return $this->records[$id] ?? false; }
    public function markUsed(int $id, string $now): bool { $this->connection->events[] = 'mark-used'; $this->records[$id]['used_at'] = $now; return true; }
    public function activate(int $userId, string $now): bool {
        $this->connection->events[] = 'activate'; if ($this->failActivation) { return false; }
        $this->user['status'] = 'active'; $this->user['email_verified_at'] = $now; return true;
    }
}
final class VerificationTestTransport implements MailTransport
{
    public int $attempts = 0;
    public function __construct(private readonly VerificationTestPdo $connection, private readonly InMemoryMailTransport $capture) {}
    public function send(VerificationMail $mail): string {
        ++$this->attempts;
        if ($this->connection->inTransaction() || !in_array('commit', $this->connection->events, true)) { throw new LogicException('Send before commit'); }
        $this->connection->events[] = 'send'; return $this->capture->send($mail);
    }
}
final class VerificationRegistrationTestRepository extends GigHub\Repositories\UserRepository
{
    public array $users = [];
    public array $profiles = [];
    public bool $duplicate = false;
    public bool $failUserInsert = false;
    public function __construct(private readonly VerificationTestPdo $connection) { parent::__construct($connection); }
    public function emailExists(string $email): bool { $this->connection->events[] = 'email-exists'; return $this->duplicate; }
    public function insertUser(array $data, string $hash): int {
        $this->connection->events[] = 'insert-user';
        if ($this->failUserInsert) {
            $error = new PDOException('Synthetic uq_users_email conflict'); $error->errorInfo = ['23000',1062,'Synthetic conflict']; throw $error;
        }
        $this->users[42] = ['email' => $data['email'],'role' => $data['role'],'password_hash' => $hash,'status' => 'pending_verification','email_verified_at' => null]; return 42;
    }
    public function insertProfile(int $userId, array $data): void { $this->connection->events[] = 'insert-profile'; $this->profiles[$userId] = ['role' => $data['role']]; }
}
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void { if (!$condition) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; };
$now = new DateTimeImmutable('2030-01-01 12:00:00', new DateTimeZone('UTC'));
$harness = static function (string $mode = 'captured') use (&$now): array {
    $pdo = new VerificationTestPdo(); $repository = new VerificationTestRepository($pdo); $capture = new InMemoryMailTransport($mode);
    $transport = new VerificationTestTransport($pdo,$capture);
    $clock = static function () use (&$now, $pdo): DateTimeImmutable { $pdo->events[] = 'clock'; return $now; };
    $service = new EmailVerificationService($pdo,$transport,'https://gighub.example.invalid/',$clock,$repository);
    return [$service,$pdo,$repository,$transport,$capture];
};
foreach (['captured' => 'captured','accepted' => 'accepted','uncertain' => 'uncertain','failure' => 'failed'] as $mode => $status) {
    [$service,$pdo,$repository,$transport,$capture] = $harness($mode);
    $check($service->request('synthetic@example.invalid') === $status, 'transport outcome ' . $mode);
    $check($transport->attempts === 1 && $capture->attempts === 1, 'exactly one attempt ' . $mode);
    $check($pdo->events === ['begin','lock-user-email','clock','history','revoke','insert','commit','send'], 'lock/clock/commit/send order ' . $mode);
    $check(count($repository->records) === 1 && $repository->records[1]['revoked_at'] === null, 'transport result invalidated token ' . $mode);
    if ($mode !== 'failure') {
        $check(count($capture->messages) === 1, 'capture missing'); $mail = $capture->messages[0];
        $check(preg_match('/\A1\.[0-9a-f]{64}\z/', $mail->key) === 1 && str_contains($mail->text,$mail->key) && str_contains($mail->html,$mail->key), 'manual key fallback missing');
        $check(str_contains($mail->text,$mail->url) && str_contains($mail->html,'Verify Email'), 'primary verification link missing');
        $check(parse_url($mail->url,PHP_URL_FRAGMENT) === 'verification=' . $mail->key && !str_contains(parse_url($mail->url,PHP_URL_QUERY),$mail->key), 'token outside fragment');
        if ($mode === 'uncertain' || $mode === 'captured') { $check($service->verify($mail->key) === 'verified', 'captured or uncertain token unusable'); }
    } else { $check($capture->messages === [], 'failed transport capture unexpected'); }
}
foreach (['insert','commit'] as $failure) {
    [$service,$pdo,$repository,$transport,$capture] = $harness();
    if ($failure === 'insert') { $repository->failInsert = true; } else { $pdo->failCommit = true; }
    $thrown = false; try { $service->request('synthetic@example.invalid'); } catch (RuntimeException $error) { $thrown = true; }
    $check($thrown && in_array('rollback',$pdo->events,true) && !$pdo->inTransaction(), 'transaction failure did not rollback ' . $failure);
    $check($transport->attempts === 0 && $capture->attempts === 0, 'send after failure/rollback ' . $failure);
}
[$service,$pdo,$repository,$transport] = $harness(); $pdo->transaction = true; $refused = false;
try { $service->request('synthetic@example.invalid'); } catch (RuntimeException $error) { $refused = true; }
$check($refused && $pdo->events === [] && $repository->records === [] && $transport->attempts === 0, 'outer transaction request guard');
$mail = $service->issue('synthetic@example.invalid');
$check($mail instanceof VerificationMail && $pdo->inTransaction() && !in_array('commit',$pdo->events,true) && $transport->attempts === 0, 'outer issue savepoint/send behavior');
$check($pdo->events[0] === 'SAVEPOINT gighub_verification' && end($pdo->events) === 'RELEASE SAVEPOINT gighub_verification', 'outer issue savepoint lifecycle');
$pdo->events = []; $check($service->verify($mail->key) === 'verified', 'outer verification failed');
$check($pdo->events === ['SAVEPOINT gighub_verification','selector','lock-user','lock-token','clock','mark-used','activate','revoke','RELEASE SAVEPOINT gighub_verification'], 'verification user-before-token locking and clock order');
$check($pdo->inTransaction() && $transport->attempts === 0, 'verification committed outer transaction or sent mail');
[$service,$pdo,$repository,$transport] = $harness(); $repository->user = false;
$check($service->request('unknown@example.invalid') === 'not_sent' && $transport->attempts === 0 && $repository->records === [], 'unknown sends mail');
foreach ([['admin','pending_verification',null],['client','active','2029-01-01 00:00:00'],['freelancer','suspended',null],['client','pending_verification','2029-01-01 00:00:00']] as [$role,$status,$verified]) {
    [$service,$pdo,$repository,$transport] = $harness(); $repository->user['role'] = $role; $repository->user['status'] = $status; $repository->user['email_verified_at'] = $verified;
    $check($service->request('synthetic@example.invalid') === 'not_sent' && $repository->records === [] && $transport->attempts === 0, 'ineligible request sends mail');
}
foreach ([['latest' => '2030-01-01 11:59:01','hourly' => 1],['latest' => '2030-01-01 11:59:00','hourly' => 5]] as $history) {
    [$service,$pdo,$repository,$transport] = $harness(); $repository->history = $history;
    $check($service->request('synthetic@example.invalid') === 'not_sent' && $repository->records === [] && $transport->attempts === 0, 'limited request sends mail');
}
[$service,$pdo,$repository,$transport] = $harness();
foreach (['', 'not-an-email', str_repeat('x',255) . '@example.invalid'] as $email) { $check($service->request($email) === 'not_sent', 'malformed email accepted'); }
$check($pdo->events === [] && $transport->attempts === 0, 'malformed email accessed persistence');
foreach (['','0.' . str_repeat('a',64),'4294967296.' . str_repeat('a',64),'1.' . str_repeat('A',64),'1.short',"1." . str_repeat('a',64) . "\n"] as $key) { $check($service->verify($key) === 'invalid', 'malformed key accepted'); }
$check($pdo->events === [], 'malformed key accessed persistence');
foreach (['http://evil.example/','ftp://localhost/','https://user:pass@gighub.example/','https://gighub.example/?x=1','https://gighub.example/#x',"https://gighub.example/\r\nx",'//gighub.example/'] as $base) {
    $refused = false; try { new EmailVerificationService(new VerificationTestPdo(),new InMemoryMailTransport(),$base); } catch (RuntimeException $error) { $refused = true; }
    $check($refused, 'unsafe trusted URL accepted');
}
$_SERVER['HTTP_HOST'] = 'attacker.example'; $_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.example';
foreach (['https://gighub.example.invalid/','http://localhost/GigHub/','http://127.0.0.1/GigHub/','http://[::1]/GigHub/'] as $base) {
    $pdo = new VerificationTestPdo(); $repository = new VerificationTestRepository($pdo);
    $service = new EmailVerificationService($pdo,new InMemoryMailTransport(),$base,static fn () => $now,$repository);
    $mail = $service->issue('synthetic@example.invalid');
    $check(str_starts_with($mail->url,$base . 'index.php?route=/verify-email#verification=') && !str_contains($mail->url,'attacker.example'), 'trusted URL influenced by request host');
}
$_SESSION = []; $csrf = GigHub\Core\Csrf::token();
$check(GigHub\Core\Csrf::valid($csrf) && !GigHub\Core\Csrf::valid([]) && !GigHub\Core\Csrf::valid('bad'), 'CSRF primitive validation');
GigHub\Core\Csrf::rotate(); $check(!GigHub\Core\Csrf::valid($csrf), 'CSRF rotation');
$registrationCsrf = GigHub\Core\Csrf::token(); $verificationCsrf = GigHub\Core\Csrf::token('verification');
$check($registrationCsrf !== $verificationCsrf && GigHub\Core\Csrf::valid($verificationCsrf,'verification'), 'purpose-specific CSRF token');
$check(!GigHub\Core\Csrf::valid($registrationCsrf,'verification') && !GigHub\Core\Csrf::valid($verificationCsrf), 'cross-purpose CSRF accepted');
GigHub\Core\Csrf::rotate('verification');
$check(!GigHub\Core\Csrf::valid($verificationCsrf,'verification') && GigHub\Core\Csrf::valid($registrationCsrf), 'verification rotation invalidated registration purpose');
$newVerificationCsrf = GigHub\Core\Csrf::token('verification'); GigHub\Core\Csrf::rotate();
$check(GigHub\Core\Csrf::valid($newVerificationCsrf,'verification'), 'registration rotation invalidated verification purpose');
$check(!isset($_SESSION['user_id']) && !isset($_SESSION['role']), 'login side effect');
$registrationInput = ['display_name' => 'Synthetic Integration Test','email' => 'synthetic@example.invalid','role' => 'client',
    'password' => 'Synthetic integration passphrase 2026!','password_confirmation' => 'Synthetic integration passphrase 2026!',
    'company_name' => 'Synthetic Test','bio' => 'Pure test only'];
foreach (['captured','failure','issuance-failure'] as $mode) {
    $_SESSION = []; [$verification,$pdo,$verificationRepository,$transport,$capture] = $harness($mode === 'failure' ? 'failure' : 'captured');
    if ($mode === 'issuance-failure') { $verificationRepository->failInsert = true; }
    $users = new VerificationRegistrationTestRepository($pdo);
    $registration = new GigHub\Services\RegistrationService($pdo,$users); $factoryCalls = 0;
    $controller = new GigHub\Controllers\RegistrationController(static fn () => $registration,
        static function () use ($pdo,$verification,&$factoryCalls): EmailVerificationService { ++$factoryCalls; $pdo->events[] = 'verification-factory'; return $verification; });
    $response = $controller->submit(['csrf_token' => GigHub\Core\Csrf::token()] + $registrationInput);
    $check($response['status'] === 303 && $response['location'] === '?route=/register/pending', 'registration integration pending redirect ' . $mode);
    $check($factoryCalls === 1 && array_search('commit',$pdo->events,true) < array_search('verification-factory',$pdo->events,true), 'verification factory before registration commit ' . $mode);
    $check($users->users[42]['status'] === 'pending_verification' && $users->users[42]['email_verified_at'] === null && isset($users->profiles[42]), 'registration user/profile lost after transport outcome ' . $mode);
    $check(password_verify($registrationInput['password'],$users->users[42]['password_hash']) && password_get_info($users->users[42]['password_hash'])['algoName'] === 'argon2id', 'integration user hash');
    $check($_SESSION['registration_delivery_status'] === match ($mode) { 'captured' => 'captured', 'failure' => 'failed', default => 'unavailable' }, 'registration delivery status ' . $mode);
    $check($transport->attempts === ($mode === 'issuance-failure' ? 0 : 1), 'registration integration send count ' . $mode);
    $check(!isset($_SESSION['user_id']) && !isset($_SESSION['role']), 'registration integration logged in user');
}
$_SESSION = []; [$verification,$pdo,$verificationRepository,$transport] = $harness();
$users = new VerificationRegistrationTestRepository($pdo); $users->duplicate = true;
$registration = new GigHub\Services\RegistrationService($pdo,$users); $factoryCalls = 0;
$controller = new GigHub\Controllers\RegistrationController(static fn () => $registration,
    static function () use (&$factoryCalls,$verification): EmailVerificationService { ++$factoryCalls; return $verification; });
$response = $controller->submit(['csrf_token' => GigHub\Core\Csrf::token()] + $registrationInput);
$check($response['status'] === 422 && $factoryCalls === 0 && $transport->attempts === 0 && $users->users === [] && $users->profiles === [], 'failed registration invoked verification');
$_SESSION = []; $pdo->events = []; $users->duplicate = false; $users->failUserInsert = true;
$response = $controller->submit(['csrf_token' => GigHub\Core\Csrf::token()] + $registrationInput);
$check($response['status'] === 422 && $factoryCalls === 0 && $transport->attempts === 0 && $users->users === [] && $users->profiles === [], 'rolled-back registration invoked verification');
$check($pdo->events === ['email-exists','begin','insert-user','rollback'] && !$pdo->inTransaction(), 'registration insert failure did not roll back before response');
$_SESSION = []; [$verification,$pdo,$verificationRepository,$transport] = $harness(); $pdo->transaction = true;
$users = new VerificationRegistrationTestRepository($pdo); $registration = new GigHub\Services\RegistrationService($pdo,$users); $factoryCalls = 0;
$controller = new GigHub\Controllers\RegistrationController(static fn () => $registration,
    static function () use (&$factoryCalls,$verification): EmailVerificationService { ++$factoryCalls; return $verification; });
$response = $controller->submit(['csrf_token' => GigHub\Core\Csrf::token()] + $registrationInput);
$check($response['status'] === 303 && $factoryCalls === 0 && $transport->attempts === 0 && $_SESSION['registration_delivery_status'] === 'unavailable', 'outer registration savepoint invoked verification');
$check($pdo->inTransaction() && $pdo->events === ['email-exists','SAVEPOINT gighub_registration','insert-user','insert-profile','RELEASE SAVEPOINT gighub_registration'], 'registration ended external transaction');
$check(isset($users->users[42],$users->profiles[42]), 'registration savepoint lost user/profile');
echo "PASS: {$checks} verification unit checks; no database connection, filesystem writes or network transport.\n";
echo "LIMIT: Transaction doubles prove service call ordering, not database isolation/rollback. HTTP CSRF, scanner GET and fragment clearing require HTTP/browser tests.\n";
