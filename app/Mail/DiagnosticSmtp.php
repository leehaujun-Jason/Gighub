<?php
declare(strict_types=1);
namespace GigHub\Mail;

use PHPMailer\PHPMailer\SMTP;
use Throwable;

class DiagnosticSmtp extends SMTP
{
    public bool $dataAttempted = false;
    public bool $dataAccepted = false;
    public bool $observed = false;
    public ?string $failureCategory = null;
    public mixed $failureCode = null;
    protected function observe(string $stage, callable $operation): bool
    {
        $this->observed = true;
        try { $result = $operation(); }
        catch (Throwable $error) {
            $this->failureCategory = $this->dataAttempted ? 'delivery_uncertain' : 'unknown';
            $this->failureCode = null;
            throw $error;
        }
        if (!$result) {
            $this->failureCategory = $stage;
            // Copy only a numeric candidate before PHPMailer cleanup clears error state.
            $code = $this->getError()['smtp_code'] ?? null;
            $this->failureCode = (is_int($code) || is_string($code)) && preg_match('/\A[2-5][0-9]{2}\z/D', (string) $code) ? (int) $code : null;
        }
        return (bool) $result;
    }
    public function connect($host, $port = null, $timeout = 30, $options = []) { return $this->observe('connection', fn () => parent::connect($host, $port, $timeout, $options)); }
    public function startTLS() { return $this->observe('tls', fn () => parent::startTLS()); }
    public function authenticate($username, $password, $authtype = null, $OAuth = null) { return $this->observe('authentication', fn () => parent::authenticate($username, $password, $authtype, $OAuth)); }
    public function recipient($address, $dsn = '') { return $this->observe('recipient', fn () => parent::recipient($address, $dsn)); }
    public function data($msg_data) {
        $this->dataAttempted = true;
        return $this->observe('delivery_uncertain', function () use ($msg_data) { return $this->dataAccepted = parent::data($msg_data); });
    }
}
