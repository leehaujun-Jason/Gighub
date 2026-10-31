<?php
declare(strict_types=1);
namespace GigHub\Services;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use GigHub\Mail\MailTransport;
use GigHub\Mail\VerificationMail;
use GigHub\Repositories\VerificationTokenRepository;

final class EmailVerificationService
{
    private readonly VerificationTokenRepository $tokens;
    private readonly Closure $clock;
    private readonly string $baseUrl;

    public function __construct(
        private readonly PDO $pdo,
        private readonly MailTransport $transport,
        string $baseUrl,
        ?Closure $clock = null,
        ?VerificationTokenRepository $repository = null,
    ) {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !filter_var($baseUrl, FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\x7f]/', $baseUrl)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !str_ends_with($parts['path'] ?? '/', '/')
            || !in_array($parts['scheme'] ?? '', ['http','https'], true)
            || (($parts['scheme'] ?? '') === 'http' && !in_array(strtolower($parts['host'] ?? ''), ['localhost','127.0.0.1','[::1]'], true))) {
            throw new RuntimeException('Verification configuration unavailable.');
        }
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->tokens = $repository ?? new VerificationTokenRepository($pdo);
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function request(string $email): string
    {
        // A savepoint release is NOT an outer commit. Never send in that situation.
        if ($this->pdo->inTransaction()) { throw new RuntimeException('Verification delivery unavailable.'); }
        $mail = $this->issue($email);
        if ($mail === null) { return 'not_sent'; }
        if ($this->pdo->inTransaction()) { throw new RuntimeException('Verification delivery unavailable.'); }
        try {
            $result = $this->transport->send($mail);
            return in_array($result, ['captured','accepted','uncertain'], true) ? $result : 'failed';
        } catch (Throwable $error) {
            // Do not revoke: even an exception can follow uncertain SMTP acceptance.
            return 'failed';
        }
    }

    public function issue(string $email): ?VerificationMail
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { return null; }
        return $this->transaction(function () use ($email): ?VerificationMail {
            $user = $this->tokens->lockUserByEmail($email);
            if (!$this->eligible($user)) { return null; }
            $now = $this->now(); $stamp = $now->format('Y-m-d H:i:s');
            $history = $this->tokens->issuanceHistory((int) $user['id'], $now->modify('-1 hour')->format('Y-m-d H:i:s'), $stamp);
            if (($history['latest'] !== null && $now->getTimestamp() - (new DateTimeImmutable($history['latest'], new DateTimeZone('UTC')))->getTimestamp() < 60)
                || (int) $history['hourly'] >= 5) { return null; }
            $secret = bin2hex(random_bytes(32));
            $this->tokens->revokeOutstanding((int) $user['id'], $stamp);
            $id = $this->tokens->insert((int) $user['id'], self::hash($user, $secret), $stamp, $now->modify('+30 minutes')->format('Y-m-d H:i:s'));
            $key = $id . '.' . $secret;
            $url = $this->baseUrl . 'index.php?route=/verify-email#verification=' . rawurlencode($key);
            $text = "Verify your GigHub email address\n\nOpen this link, then choose Confirm Verification:\n" . $url
                . "\n\nThis link expires 30 minutes after it was requested. Only the newest link works.\n"
                . "If the link does not work, paste this verification key on the confirmation page:\n" . $key
                . "\n\nIf you did not create this account, ignore this message. Verification does not sign you in.\n";
            $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<h1>Verify your GigHub email address</h1><p><a href="' . $escape($url) . '">Verify Email</a></p>'
                . '<p>Open the link, then choose Confirm Verification. It expires 30 minutes after the request; only the newest link works.</p>'
                . '<p>If needed, paste this verification key on the confirmation page: <code>' . $escape($key) . '</code></p>'
                . '<p>If you did not create this account, ignore this message. Verification does not sign you in.</p>';
            return new VerificationMail($user['email'], 'Verify your GigHub email address', $text, $html, $key, $url);
        });
    }

    public function verify(string $key): string
    {
        if (!preg_match('/\A([1-9][0-9]{0,9})\.([0-9a-f]{64})\z/', $key, $parts) || (int) $parts[1] > 4294967295) { return 'invalid'; }
        return $this->transaction(function () use ($parts): string {
            $id = (int) $parts[1];
            $userId = $this->tokens->tokenUserId($id);
            if ($userId === false) { return 'invalid'; }
            $user = $this->tokens->lockUser($userId);
            if (!$this->eligible($user)) { return 'invalid'; }
            $token = $this->tokens->lockToken($id, $userId);
            $now = $this->now()->format('Y-m-d H:i:s');
            if ($token === false || $token['used_at'] !== null || $token['revoked_at'] !== null
                || $token['expires_at'] <= $now || !hash_equals($token['token_hash'], self::hash($user, $parts[2]))) { return 'invalid'; }
            if (!$this->tokens->markUsed($id, $now) || !$this->tokens->activate($userId, $now)) {
                throw new RuntimeException('Verification state changed.');
            }
            $this->tokens->revokeOutstanding($userId, $now);
            return 'verified';
        });
    }

    private static function hash(array $user, string $secret): string
    {
        return hash('sha256', json_encode(['gighub-email-verification', (string) $user['id'], $user['email'], $secret], JSON_THROW_ON_ERROR));
    }

    private function eligible(array|false $user): bool
    {
        return $user !== false && in_array($user['role'], ['client','freelancer'], true)
            && $user['status'] === 'pending_verification' && $user['email_verified_at'] === null;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'));
    }

    private function transaction(Closure $operation): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) $this->pdo->beginTransaction();
        else $this->pdo->exec('SAVEPOINT gighub_verification');
        try {
            $result = $operation();
            if ($owns) $this->pdo->commit();
            else $this->pdo->exec('RELEASE SAVEPOINT gighub_verification');
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                if ($owns) $this->pdo->rollBack();
                else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_verification');
                    $this->pdo->exec('RELEASE SAVEPOINT gighub_verification');
                }
            }
            // Suppress SQL, email, link and driver details at the service boundary.
            throw new RuntimeException('Email verification is temporarily unavailable.');
        }
    }
}
