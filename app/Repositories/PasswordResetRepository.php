<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;

final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function lockByEmail(string $email): array|false
    {
        $query = $this->pdo->prepare('SELECT id, role, status, email_verified_at FROM users WHERE email = ? FOR UPDATE');
        $query->execute([$email]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function lockById(int $id): array|false
    {
        $query = $this->pdo->prepare('SELECT id, role, status, email_verified_at FROM users WHERE id = ? FOR UPDATE');
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function owner(string $hash): ?int
    {
        $query = $this->pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()');
        $query->execute([$hash]);
        $id = $query->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function recentlyIssued(int $id): bool
    {
        $query = $this->pdo->prepare('SELECT 1 FROM password_reset_tokens WHERE user_id = ? AND issued_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE LIMIT 1');
        $query->execute([$id]);
        return $query->fetchColumn() !== false;
    }

    public function issue(int $id, string $hash): void
    {
        $query = $this->pdo->prepare('INSERT INTO password_reset_tokens (token_hash, user_id, issued_at, expires_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 30 MINUTE)');
        $query->execute([$hash, $id]);
    }

    public function revoke(int $id): void
    {
        $query = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
        $query->execute([$id]);
    }

    public function updatePassword(int $id, #[\SensitiveParameter] string $hash): void
    {
        $query = $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $query->execute([$hash, $id]);
    }
}
