<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;

class VerificationTokenRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    public function lockUserByEmail(string $email): array|false
    {
        $query = $this->pdo->prepare('SELECT id,email,role,status,email_verified_at FROM users WHERE email=? FOR UPDATE');
        $query->execute([$email]); return $query->fetch();
    }

    public function tokenUserId(int $id): int|false
    {
        // Non-locking lookup only. All eligibility/proof checks follow locked current reads.
        $query = $this->pdo->prepare('SELECT user_id FROM email_verification_tokens WHERE id=?');
        $query->execute([$id]); $value = $query->fetchColumn();
        return $value === false ? false : (int) $value;
    }

    public function lockUser(int $id): array|false
    {
        $query = $this->pdo->prepare('SELECT id,email,role,status,email_verified_at FROM users WHERE id=? FOR UPDATE');
        $query->execute([$id]); return $query->fetch();
    }

    public function lockToken(int $id, int $userId): array|false
    {
        $query = $this->pdo->prepare('SELECT * FROM email_verification_tokens WHERE id=? AND user_id=? FOR UPDATE');
        $query->execute([$id,$userId]); return $query->fetch();
    }

    public function issuanceHistory(int $userId, string $cutoff, string $now): array
    {
        // Current locked reads also work when a caller already has a REPEATABLE READ snapshot.
        $query = $this->pdo->prepare('SELECT created_at FROM email_verification_tokens WHERE user_id=? ORDER BY id FOR UPDATE');
        $query->execute([$userId]);
        $latest = null; $hourly = 0;
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $created) {
            if ($latest === null || $created > $latest) $latest = $created;
            if ($created > $cutoff && $created <= $now) $hourly++;
        }
        return ['latest' => $latest, 'hourly' => $hourly];
    }

    public function revokeOutstanding(int $userId, string $now): void
    {
        $query = $this->pdo->prepare('UPDATE email_verification_tokens SET revoked_at=? WHERE user_id=? AND used_at IS NULL AND revoked_at IS NULL');
        $query->execute([$now,$userId]);
    }

    public function insert(int $userId, string $hash, string $created, string $expires): int
    {
        $query = $this->pdo->prepare('INSERT INTO email_verification_tokens (user_id,token_hash,created_at,expires_at) VALUES (?,?,?,?)');
        $query->execute([$userId,$hash,$created,$expires]); return (int) $this->pdo->lastInsertId();
    }

    public function activate(int $userId, string $now): bool
    {
        $query = $this->pdo->prepare("UPDATE users SET status='active',email_verified_at=? WHERE id=? AND status='pending_verification' AND email_verified_at IS NULL AND role IN ('client','freelancer')");
        $query->execute([$now,$userId]); return $query->rowCount() === 1;
    }

    public function markUsed(int $id, string $now): bool
    {
        $query = $this->pdo->prepare('UPDATE email_verification_tokens SET used_at=? WHERE id=? AND used_at IS NULL AND revoked_at IS NULL AND expires_at>?');
        $query->execute([$now,$id,$now]); return $query->rowCount() === 1;
    }
}
