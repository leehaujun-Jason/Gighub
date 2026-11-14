<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;
use RuntimeException;

final class OnboardingRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function profile(int $id, string $role, bool $lock = false): array
    {
        $table = $role === 'client' ? 'client_profiles' : 'freelancer_profiles';
        $fields = $role === 'client' ? 'bio, company_name, account_type, state, city' : 'bio, headline';
        $query = $this->pdo->prepare("SELECT $fields FROM $table WHERE user_id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Profile unavailable.');
        }
        return $row;
    }

    public function skillIds(int $id): array
    {
        $query = $this->pdo->prepare('SELECT skill_id FROM user_skills WHERE freelancer_id = ?');
        $query->execute([$id]);
        return array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    }

    public function complete(int $id, string $role, array $data): bool
    {
        $query = $this->pdo->prepare("UPDATE users SET display_name = ?, onboarding_pending = 0
            WHERE id = ? AND role = ? AND status = 'active' AND email_verified_at IS NOT NULL AND onboarding_pending = 1");
        $query->execute([$data['display_name'], $id, $role]);
        if ($query->rowCount() !== 1) {
            return false;
        }
        $this->profile($id, $role, true);
        if ($role === 'client') {
            $query = $this->pdo->prepare('UPDATE client_profiles SET account_type = ?, company_name = ?, state = ?, city = ?, bio = ? WHERE user_id = ?');
            $query->execute([$data['account_type'], $data['company_name'] ?: null, $data['state'] ?: null, $data['city'] ?: null, $data['bio'] ?: null, $id]);
        } else {
            $query = $this->pdo->prepare('UPDATE freelancer_profiles SET headline = ?, bio = ? WHERE user_id = ?');
            $query->execute([$data['headline'], $data['bio'], $id]);
            $existing = $this->skillIds($id);
            $remove = $this->pdo->prepare('DELETE FROM user_skills WHERE freelancer_id = ? AND skill_id = ?');
            foreach (array_diff($existing, $data['skills']) as $skill) {
                $remove->execute([$id, $skill]);
            }
            $add = $this->pdo->prepare('INSERT INTO user_skills (freelancer_id, skill_id) VALUES (?, ?)');
            foreach (array_diff($data['skills'], $existing) as $skill) {
                $add->execute([$id, $skill]);
            }
        }
        return true;
    }
}
