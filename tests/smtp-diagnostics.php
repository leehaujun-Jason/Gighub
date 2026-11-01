<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/smtp.php';
$check(class_exists(GigHub\Mail\SmtpDiagnostics::class), 'safe diagnostic collector exists');
use GigHub\Mail\SmtpDiagnostics;
use GigHub\Mail\DiagnosticSmtp;
final class DiagnosticOfflineConnection extends DiagnosticSmtp
{
    public string $failure = '';
    private bool $open = false;
    public function connect($host, $port = null, $timeout = 30, $options = []) {
        return $this->observe('connection', function () { $this->open = $this->failure !== 'connection'; return $this->open; });
    }
    public function connected() { return $this->open; }
    public function hello($host = '') { return true; }
    public function startTLS() { return $this->observe('tls', fn () => $this->failure !== 'tls'); }
    public function authenticate($username, $password, $authtype = null, $OAuth = null) { return $this->observe('authentication', fn () => $this->failure !== 'authentication'); }
    public function mail($from) { return true; }
    public function recipient($address, $dsn = '') { return $this->observe('recipient', fn () => $this->failure !== 'recipient'); }
    public function data($msg_data) {
        $this->dataAttempted = true;
        return $this->observe('delivery_uncertain', function () {
            if ($this->failure === 'exception') { throw new RuntimeException('secret recipient@example.invalid raw-token'); }
            $this->dataAccepted = !in_array($this->failure, ['timeout','rejection'], true);
            return $this->dataAccepted;
        });
    }
    public function quit($close_on_error = true) { $this->open = false; return true; }
    public function close() { $this->open = false; }
    public function getServerExt($name) { return false; }
    public function getError() { return ['smtp_code' => match ($this->failure) { 'authentication' => '535', 'recipient','rejection' => '550', default => '' }, 'detail' => 'secret raw-token']; }
}
foreach (['connection','tls','authentication','recipient','timeout','rejection','exception'] as $failure) {
    SmtpDiagnostics::reset();
    $smtp = new DiagnosticOfflineConnection(); $smtp->failure = $failure;
    $mailer = new PHPMailer\PHPMailer\PHPMailer(true); $mailer->setSMTPInstance($smtp);
    $adapter = new GigHub\Mail\PhpMailerTransport($config, static fn () => $mailer);
    ob_start();
    try { $result = $adapter->send($mail); } catch (RuntimeException $error) { $result = 'failed'; $check($error->getMessage() === 'Mail transport unavailable.', 'generic definite failure'); }
    $output = ob_get_clean();
    $expected = in_array($failure, ['timeout','exception'], true) ? 'uncertain' : 'failed';
    $check($result === $expected && $output === '', 'definite versus uncertain ' . $failure);
    $diagnostic = SmtpDiagnostics::last();
    $category = match ($failure) { 'timeout','exception' => 'delivery_uncertain', 'rejection' => 'unknown', default => $failure };
    $check($diagnostic['category'] === $category, 'safe stage ' . $failure);
    $check(array_keys($diagnostic) === ['category','code'] && !str_contains(json_encode($diagnostic), 'secret'), 'allowlisted metadata only');
}
foreach (['535',535,'550 token',"535\n",999,[],null] as $value) {
    SmtpDiagnostics::record('authentication', $value);
    $check(SmtpDiagnostics::last()['code'] === (in_array($value, ['535',535], true) ? 535 : null), 'strict numeric code');
}
SmtpDiagnostics::record('secret raw-token', '535');
$check(SmtpDiagnostics::last()['category'] === 'unknown', 'unknown category sanitized');
SmtpDiagnostics::reset();
$reject(static fn () => GigHub\Mail\MailTransportFactory::create(array_replace($config, ['host' => '']), 'local'), 'configuration');
$check(SmtpDiagnostics::last()['category'] === 'configuration', 'configuration failure recorded');
$adapter = new GigHub\Mail\PhpMailerTransport($config, static function () { throw new Error('secret raw-token'); });
$reject(static fn () => $adapter->send($mail), 'unexpected pre-send PHP error');
$check(SmtpDiagnostics::last() === ['category' => 'unknown','code' => null], 'pre-send error sanitized');
$log = tmpfile(); $path = stream_get_meta_data($log)['uri']; $oldLog = ini_get('error_log');
try {
    ini_set('error_log', $path);
    $reject(static fn () => GigHub\Mail\MailTransportFactory::create(array_replace($config, ['host' => '', 'diagnostics' => true]), 'production'), 'production diagnostic gate');
    $check(file_get_contents($path) === '', 'production never enables diagnostic logging');
    $reject(static fn () => GigHub\Mail\MailTransportFactory::create(array_replace($config, ['host' => '', 'diagnostics' => true]), 'local'), 'local diagnostic opt-in');
    $check(preg_match('/\A[^\r\n]*\[GigHub SMTP\] category=configuration code=none\r?\n\z/', file_get_contents($path)) === 1, 'only fixed safe fields logged');
} finally { ini_set('error_log', $oldLog); SmtpDiagnostics::enable(false); fclose($log); }
echo "PASS: $count combined offline SMTP/diagnostic checks.\n";
