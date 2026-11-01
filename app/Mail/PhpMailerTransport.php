<?php
declare(strict_types=1);
namespace GigHub\Mail;

use Closure;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

final class PhpMailerTransport implements MailTransport
{
    /** Factory injection allows offline tests to replace the SMTP boundary. */
    public function __construct(
        #[\SensitiveParameter] private readonly array $config,
        private readonly ?Closure $mailerFactory = null,
    ) {
        $fail = static function (): never { throw new RuntimeException('Mail transport unavailable.'); };
        if (!class_exists(PHPMailer::class) || !extension_loaded('openssl')) { $fail(); }
        foreach (['host', 'encryption', 'username', 'password', 'from_email', 'from_name'] as $key) {
            if (!is_string($config[$key] ?? null)) { $fail(); }
        }
        // A single host only: PHPMailer supports fallback host lists, which we prohibit.
        if (strlen($config['host']) > 253 || (!filter_var($config['host'], FILTER_VALIDATE_IP)
            && !filter_var($config['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            || preg_match('/[;:\s\/\\\\]/', $config['host'])
            || !is_int($config['port'] ?? null) || $config['port'] < 1 || $config['port'] > 65535
            || !in_array($config['encryption'], ['starttls', 'smtps'], true)
            || !is_int($config['timeout'] ?? null) || $config['timeout'] < 1 || $config['timeout'] > 30
            || strlen($config['from_email']) > 254 || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)
            || strlen($config['from_name']) > 400 || !mb_check_encoding($config['from_name'], 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $config['from_name'] . $config['from_email'])) { $fail(); }
        foreach (['username', 'password'] as $key) {
            if ($config[$key] === '' || strlen($config[$key]) > 4096 || preg_match('/\A<.*>\z/s', $config[$key])
                || preg_match('/[\x00\r\n]/', $config[$key])) { $fail(); }
        }
    }

    public function send(VerificationMail $mail): string
    {
        SmtpDiagnostics::reset();
        $smtp = null;
        $sending = false;
        try {
            // Fresh instance per attempt prevents recipient/content carry-over.
            $mailer = $this->mailerFactory !== null ? ($this->mailerFactory)() : new PHPMailer(true);
            if (!$mailer instanceof PHPMailer) { throw new RuntimeException(); }
            if ($this->mailerFactory === null) { $mailer->setSMTPInstance(new DiagnosticSmtp()); }
            $smtp = $mailer->getSMTPInstance();
            $mailer->clearAllRecipients();
            $mailer->clearAttachments();
            $mailer->clearCustomHeaders();
            $mailer->clearReplyTos();
            $mailer->isSMTP();
            $mailer->Host = $this->config['host'];
            $mailer->Port = $this->config['port'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $this->config['username'];
            $mailer->Password = $this->config['password'];
            $mailer->SMTPSecure = $this->config['encryption'] === 'starttls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mailer->SMTPAutoTLS = false; // Explicit SMTPSecure requires encryption, rather than opportunistic TLS.
            $mailer->SMTPDebug = 0;
            $mailer->Debugoutput = static function (): void {}; // Defense against accidental diagnostics.
            $mailer->SMTPKeepAlive = false;
            $mailer->Timeout = $this->config['timeout'];
            $mailer->getSMTPInstance()->Timelimit = $this->config['timeout'];
            $mailer->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($this->config['from_email'], $this->config['from_name']);
            $mailer->addAddress($mail->to);
            $mailer->Subject = $mail->subject;
            $mailer->isHTML(true);
            $mailer->Body = $mail->html;
            $mailer->AltBody = $mail->text;
            // One attempt only. Acceptance is not proof of inbox delivery.
            $sending = true;
            if ($mailer->send()) { return 'accepted'; }
        } catch (Throwable) {
            // Never read/log ErrorInfo or raw exception details.
        }
        $tracked = $smtp instanceof DiagnosticSmtp;
        $category = $tracked ? ($smtp->failureCategory ?? 'unknown') : 'unknown';
        $code = $tracked ? $smtp->failureCode : null;
        $definite = !$sending || ($tracked && $smtp->observed && !$smtp->dataAttempted)
            || ($tracked && !$smtp->dataAccepted && $smtp->failureCategory === 'delivery_uncertain' && is_int($code) && $code >= 400 && $code <= 599);
        if (!$definite && $tracked && $smtp->dataAttempted) { $category = 'delivery_uncertain'; }
        if ($definite && $tracked && $smtp->dataAttempted) { $category = 'unknown'; } // Explicit DATA rejection; no dedicated allowlisted category.
        SmtpDiagnostics::record($category, $code);
        if ($definite) { throw new RuntimeException('Mail transport unavailable.'); }
        return 'uncertain';
    }
}
