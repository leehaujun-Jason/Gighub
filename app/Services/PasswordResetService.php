<?php
declare(strict_types=1);
namespace GigHub\Services;

use Closure;
use GigHub\Mail\VerificationMail;
use GigHub\Repositories\PasswordResetRepository;
use PDO;
use Throwable;

final class PasswordResetService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PasswordResetRepository $resets,
        private readonly Closure $transport,
        private readonly string $baseUrl,
    ) {}

    public function request(string $email): void
    {
        $parts = parse_url($this->baseUrl);
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)
            || !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['scheme'] ?? '') !== 'https'
                && !(($parts['scheme'] ?? '') === 'http'
                    && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true)))) {
            return;
        }

        $key = bin2hex(random_bytes(32));
        $issued = $this->transaction(function () use ($email, $key): bool {
            $user = $this->resets->lockByEmail($email);
            if (!self::eligible($user) || $this->resets->recentlyIssued((int) $user['id'])) {
                return false;
            }
            $this->resets->revoke((int) $user['id']);
            $this->resets->issue((int) $user['id'], hash('sha256', $key));
            return true;
        });

        if ($issued) {
            $url = rtrim($this->baseUrl, '/') . '/index.php?route=/reset-password#reset=' . $key;
            $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ($this->transport)()->send(new VerificationMail(
                $email,
                'Reset your GigHub password',
                "Open this link to reset your password: $url\n\nIt expires in 30 minutes. If needed, paste this reset key on the form: $key\n\nIf you did not request a reset, ignore this message.",
                '<p><a href="' . $safeUrl . '">Reset your GigHub password</a></p>'
                    . '<p>This link expires in 30 minutes. If needed, paste this reset key on the form: <code>' . $key . '</code>.</p>'
                    . '<p>If you did not request a reset, ignore this message.</p>',
                $key,
                $url,
            ));
        }
    }

    public function reset(#[\SensitiveParameter] string $key, #[\SensitiveParameter] string $password): bool
    {
        $hash = hash('sha256', $key);
        $id = $this->resets->owner($hash);
        if ($id === null) {
            return false;
        }
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
        return $this->transaction(function () use ($id, $hash, $passwordHash): bool {
            $user = $this->resets->lockById($id);
            if (!self::eligible($user) || $this->resets->owner($hash) !== $id) {
                return false;
            }
            $this->resets->updatePassword($id, $passwordHash);
            $this->resets->revoke($id);
            return true;
        });
    }

    private static function eligible(array|false $user): bool
    {
        return $user !== false && $user['status'] === 'active' && $user['email_verified_at'] !== null
            && in_array($user['role'], ['client', 'freelancer'], true);
    }

    private function transaction(Closure $operation): bool
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
