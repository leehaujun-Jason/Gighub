<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;

class UserRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    public function emailExists(string $email): bool
    {
        $query = $this->pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $query->execute([$email]);
        return $query->fetchColumn() !== false;
    }

    public function findForAuthentication(string $email): array|false
    {
        $query=$this->pdo->prepare('SELECT id,password_hash,display_name,role,status,email_verified_at FROM users WHERE email=? LIMIT 1');
        $query->execute([$email]);return $query->fetch();
    }

    public function findAuthIdentity(int $id): array|false
    {
        $query=$this->pdo->prepare('SELECT id,display_name,role,status,email_verified_at FROM users WHERE id=? LIMIT 1');
        $query->execute([$id]);return $query->fetch();
    }

    public function insertUser(array $data, string $hash): int
    {
        $query = $this->pdo->prepare(
            "INSERT INTO users (email, password_hash, display_name, role, status, email_verified_at) "
            . "VALUES (?, ?, ?, ?, 'pending_verification', NULL)"
        );
        $query->execute([$data['email'], $hash, $data['display_name'], $data['role']]);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertProfile(int $userId, array $data): void
    {
        $nullable = static fn (string $value): ?string => $value === '' ? null : $value;
        if ($data['role'] === 'client') {
            $query = $this->pdo->prepare('INSERT INTO client_profiles (user_id, company_name, bio) VALUES (?, ?, ?)');
            $query->execute([$userId, $nullable($data['company_name']), $nullable($data['bio'])]);
        } else {
            // Portfolio management belongs to the later verified-profile module.
            $query = $this->pdo->prepare('INSERT INTO freelancer_profiles (user_id, bio) VALUES (?, ?)');
            $query->execute([$userId, $nullable($data['bio'])]);
        }
    }
}
