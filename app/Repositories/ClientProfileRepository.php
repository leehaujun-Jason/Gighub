<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;
use RuntimeException;
use Throwable;

final class ClientProfileRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function find(int $id, bool $lock = false): array|false
    {
        $query = $this->pdo->prepare("SELECT u.display_name, u.email, p.account_type, p.company_name, p.state, p.city, p.bio
            FROM users u JOIN client_profiles p ON p.user_id = u.id
            WHERE u.id = ? AND u.role = 'client' AND u.status = 'active'
              AND u.email_verified_at IS NOT NULL AND u.onboarding_pending = 0" . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    public function update(int $id, array $values): void
    {
        $this->pdo->beginTransaction();
        try {
            if ($this->find($id, true) === false) {
                throw new RuntimeException('Profile unavailable.');
            }
            $query = $this->pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?');
            $query->execute([$values['display_name'], $id]);
            $query = $this->pdo->prepare('UPDATE client_profiles SET account_type = ?, company_name = ?, state = ?, city = ?, bio = ? WHERE user_id = ?');
            $query->execute([
                $values['account_type'] === '' ? null : $values['account_type'],
                $values['company_name'] === '' ? null : $values['company_name'],
                $values['state'] === '' ? null : $values['state'],
                $values['city'] === '' ? null : $values['city'],
                $values['bio'] === '' ? null : $values['bio'],
                $id,
            ]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
