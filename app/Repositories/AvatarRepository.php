<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;
use RuntimeException;
use Throwable;

final class AvatarRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function publicKey(int $id): ?string
    {
        $query = $this->pdo->prepare("SELECT u.avatar_key FROM users u JOIN freelancer_profiles p ON p.user_id = u.id
            WHERE u.id = ? AND u.role = 'freelancer' AND u.status = 'active' AND u.email_verified_at IS NOT NULL");
        $query->execute([$id]);
        $key = $query->fetchColumn();
        return is_string($key) ? $key : null;
    }

    public function replace(int $id, string $role, ?string $key): ?string
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare("SELECT avatar_key FROM users WHERE id = ? AND role = ?
                AND status = 'active' AND email_verified_at IS NOT NULL AND onboarding_pending = 0 FOR UPDATE");
            $query->execute([$id, $role]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('Photo owner unavailable.');
            }
            $query = $this->pdo->prepare('UPDATE users SET avatar_key = ? WHERE id = ?');
            $query->execute([$key, $id]);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        // A failed commit response can be ambiguous. Callers must retain the new file.
        $this->pdo->commit();
        return $row['avatar_key'];
    }
}
