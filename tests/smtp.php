<?php
declare(strict_types=1);
// No sockets: the real PHPMailer MIME builder runs, but smtpSend is replaced.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
foreach (['MailTransport', 'VerificationMail', 'InMemoryMailTransport'] as $class) { require $root . '/app/Mail/' . $class . '.php'; }
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$count;
};
$check(is_file($root . '/app/Mail/PhpMailerTransport.php'), 'SMTP adapter exists');
foreach (['DisabledMailTransport', 'SmtpDiagnostics', 'DiagnosticSmtp', 'PhpMailerTransport', 'MailTransportFactory'] as $class) { require $root . '/app/Mail/' . $class . '.php'; }
use GigHub\Mail\PhpMailerTransport;
use GigHub\Mail\MailTransportFactory;
use GigHub\Mail\VerificationMail;
use PHPMailer\PHPMailer\PHPMailer;
final class OfflineSmtpMailer extends PHPMailer
{
    public int $attempts = 0;
    public string $outcome = 'accepted';
    protected function smtpSend($header, $body) {
        ++$this->attempts;
        if ($this->outcome === 'throw') { throw new RuntimeException('synthetic-private-password raw-token internal SMTP detail'); }
        return $this->outcome !== 'false';
    }
}
$config = ['enabled' => true, 'transport' => 'smtp', 'host' => 'smtp.example.invalid', 'port' => 587,
    'encryption' => 'starttls', 'username' => 'synthetic-user', 'password' => 'synthetic-private-password',
    'from_email' => 'sender@example.invalid', 'from_name' => 'GigHub', 'timeout' => 10];
$defaults = require $root . '/config/defaults.php';
$example = require $root . '/config/local.example.php';
$check($defaults['mail']['enabled'] === false && $example['mail']['enabled'] === false, 'shipped configurations never enable mail');
$check($defaults['mail']['password'] === '' && $example['mail']['password'] === '<smtp-password>', 'public configuration contains no credentials');
$reject = static function (callable $operation, string $label) use ($check): void {
    try { $operation(); } catch (RuntimeException $error) {
        $check($error->getMessage() === 'Mail transport unavailable.' && $error->getPrevious() === null, $label . ' sanitized'); return;
    }
    $check(false, $label . ' rejected');
};
foreach (['host' => ['', 'smtp://host', 'one;two', "host\r\nX: bad"], 'port' => [0, 65536, '587'],
    'encryption' => ['', 'none', 'tls'], 'username' => ['', '<smtp-username>'], 'password' => ['', '<smtp-password>'],
    'from_email' => ['', 'bad', "sender@example.invalid\nX: bad"], 'from_name' => ["Name\r\nX: bad"],
    'timeout' => [0, 31, '10']] as $key => $values) {
    foreach ($values as $value) { $reject(static fn () => new PhpMailerTransport(array_replace($config, [$key => $value])), 'invalid ' . $key); }
}
$disabled = MailTransportFactory::create(array_replace($config, ['enabled' => false]), 'production');
$check($disabled instanceof GigHub\Mail\DisabledMailTransport, 'disabled never selects SMTP');
$mail = new VerificationMail('recipient@example.invalid', 'Verify GigHub', 'Plain verification raw-token', '<p>HTML verification raw-token</p>', 'raw-token', 'https://example.invalid/#verification=raw-token');
$reject(static fn () => $disabled->send($mail), 'disabled fails closed');
$fake = MailTransportFactory::create(['enabled' => true, 'transport' => 'memory'], 'local');
$check($fake instanceof GigHub\Mail\InMemoryMailTransport && $fake->send($mail) === 'captured', 'explicit local fake selection');
$reject(static fn () => MailTransportFactory::create(['enabled' => true, 'transport' => 'memory'], 'production'), 'production rejects fake');
$reject(static fn () => MailTransportFactory::create(['enabled' => 'false'], 'local'), 'boolean required');
$reject(static fn () => MailTransportFactory::create(['enabled' => true, 'transport' => 'unknown'], 'local'), 'unknown transport');
$check(MailTransportFactory::create($config, 'production') instanceof PhpMailerTransport, 'real selection does not send');
foreach (['starttls' => PHPMailer::ENCRYPTION_STARTTLS, 'smtps' => PHPMailer::ENCRYPTION_SMTPS] as $encryption => $expected) {
    $mailer = new OfflineSmtpMailer(true);
    $adapter = new PhpMailerTransport(array_replace($config, ['encryption' => $encryption]), static fn () => $mailer);
    ob_start(); $result = $adapter->send($mail); $output = ob_get_clean();
    $check($result === 'accepted' && $mailer->attempts === 1 && $output === '', 'single offline acceptance, no debug output');
    $check($mailer->Mailer === 'smtp' && $mailer->SMTPAuth && $mailer->SMTPSecure === $expected, 'authenticated explicit TLS');
    $check($mailer->SMTPDebug === 0 && !$mailer->SMTPKeepAlive && $mailer->Timeout === 10 && $mailer->getSMTPInstance()->Timelimit === 10, 'bounded timeouts and no debug/keepalive');
    $check($mailer->SMTPOptions['ssl']['verify_peer'] && $mailer->SMTPOptions['ssl']['verify_peer_name'] && !$mailer->SMTPOptions['ssl']['allow_self_signed'], 'certificate validation');
    $check($mailer->From === $config['from_email'] && $mailer->FromName === 'GigHub' && $mailer->getToAddresses() === [[$mail->to, '']], 'fixed sender and exact recipient');
    $check($mailer->Body === $mail->html && $mailer->AltBody === $mail->text && $mailer->CharSet === PHPMailer::CHARSET_UTF8, 'HTML and plain text intact');
    $mime = $mailer->getSentMIMEMessage();
    $check(str_contains($mime, 'multipart/alternative') && str_contains($mime, 'text/plain') && str_contains($mime, 'text/html'), 'real multipart MIME builder');
    $check(!str_contains($mime, $config['password']) && !str_contains($mime, $config['username']), 'no credentials in message');
    foreach (['false', 'throw'] as $outcome) {
        $mailer->outcome = $outcome; $before = $mailer->attempts;
        ob_start(); $result = $adapter->send($mail); $output = ob_get_clean();
        $check($result === 'uncertain' && $mailer->attempts === $before + 1 && $output === '', 'failure sanitized without retry');
        $check(count($mailer->getToAddresses()) === 1, 'reused test mailer recipients cleared');
    }
}
// Exercise PHPMailer's real SMTP orchestration, replacing only socket operations.
// These checks catch TLS downgrade, AUTH-before-STARTTLS and repeat-send regressions.
final class OfflineSmtpConnection extends \PHPMailer\PHPMailer\SMTP
{
    public array $events = [];
    public bool $open = false;
    public string $failure = '';
    public function connect($host, $port = null, $timeout = 30, $options = []) { $this->events[] = 'connect'; return $this->open = $this->failure !== 'connect'; }
    public function connected() { return $this->open; }
    public function hello($host = '') { $this->events[] = 'hello'; return true; }
    public function startTLS() { $this->events[] = 'tls'; return $this->failure !== 'tls'; }
    public function authenticate($username, $password, $authtype = null, $OAuth = null) { $this->events[] = 'auth'; return $this->failure !== 'auth'; }
    public function mail($from) { $this->events[] = 'mail'; return true; }
    public function recipient($address, $dsn = '') { $this->events[] = 'recipient'; return true; }
    public function data($msg_data) { $this->events[] = 'data'; return $this->failure !== 'data'; }
    public function quit($close_on_error = true) { $this->events[] = 'quit'; $this->open = false; return true; }
    public function close() { $this->open = false; }
    public function getServerExt($name) { return false; }
}
foreach (['', 'connect', 'tls', 'auth', 'data'] as $failure) {
    $connection = new OfflineSmtpConnection(); $connection->failure = $failure;
    $mailer = new PHPMailer(true); $mailer->setSMTPInstance($connection);
    $adapter = new PhpMailerTransport($config, static fn () => $mailer);
    ob_start(); $result = $adapter->send($mail); $output = ob_get_clean();
    $check($result === ($failure === '' ? 'accepted' : 'uncertain') && $output === '', 'real library failure outcome sanitized');
    $check(count(array_keys($connection->events, 'connect', true)) === 1 && count(array_keys($connection->events, 'data', true)) <= 1, 'no connection fallback or repeated DATA');
    if ($failure === 'tls' || $failure === 'connect') { $check(!in_array('auth', $connection->events, true) && !in_array('data', $connection->events, true), 'no authentication or message after encryption failure'); }
    if ($failure === '') { $check(array_slice($connection->events, 0, 5) === ['connect','hello','tls','hello','auth'], 'STARTTLS completes before authentication'); }
}
// Separate PHP process deliberately omits Composer: no autoload, no socket.
$code = 'require ' . var_export($root . '/app/Mail/MailTransport.php', true) . '; require ' . var_export($root . '/app/Mail/PhpMailerTransport.php', true)
    . '; try { new GigHub\\Mail\\PhpMailerTransport(' . var_export($config, true) . '); exit(1); } catch (RuntimeException $e) { exit($e->getMessage() === "Mail transport unavailable." ? 0 : 2); }';
$process = proc_open([PHP_BINARY, '-r', $code], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
$check(proc_close($process) === 0 && $out === '' && $err === '', 'missing dependency fails closed without output');
foreach (['accepted', 'uncertain', 'captured'] as $deliveryStatus) {
    ob_start(); require $root . '/app/Views/registration-pending.php'; $html = ob_get_clean();
    $check(!str_contains($html, 'synthetic-private-password') && !str_contains($html, 'raw-token'), 'pending no sensitive data');
    $check($deliveryStatus === 'captured' || !str_contains($html, 'No verification email has been sent.'), 'SMTP outcomes do not falsely claim no send');
    if ($deliveryStatus === 'accepted') { $check(str_contains($html, 'accepted') && !str_contains($html, 'No external email'), 'acceptance is not delivery'); }
}
echo "PASS: $count SMTP checks (offline; no database or SMTP connection).\n";
