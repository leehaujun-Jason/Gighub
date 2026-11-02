<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;

class FreelancerProfileRepository
{
    public function __construct(private readonly PDO $pdo) {}
    public function find(int $userId): array|false
    {
        // Deliberate public projection; never select email, portfolio or authentication fields.
        $q=$this->pdo->prepare("SELECT u.id,u.display_name,p.headline,p.bio FROM users u JOIN freelancer_profiles p ON p.user_id=u.id WHERE u.id=? AND u.role='freelancer' AND u.status='active' AND u.email_verified_at IS NOT NULL");
        $q->execute([$userId]); return $q->fetch(PDO::FETCH_ASSOC);
    }
    public function skills(int $userId): array
    {
        $q=$this->pdo->prepare('SELECT s.id,s.name FROM skills s JOIN user_skills us ON us.skill_id=s.id WHERE us.freelancer_id=? ORDER BY s.name,s.id');
        $q->execute([$userId]); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function lockEligible(int $userId): bool
    {
        $q=$this->pdo->prepare("SELECT id FROM users WHERE id=? AND role='freelancer' AND status='active' AND email_verified_at IS NOT NULL FOR UPDATE");
        $q->execute([$userId]); if ($q->fetchColumn()===false) return false;
        $q=$this->pdo->prepare('SELECT user_id FROM freelancer_profiles WHERE user_id=? FOR UPDATE');
        $q->execute([$userId]); return $q->fetchColumn()!==false;
    }
    public function update(int $userId,array $data): void
    {
        // Update the existing row only. Portfolio fields are intentionally untouched.
        $q=$this->pdo->prepare('UPDATE freelancer_profiles SET headline=?,bio=? WHERE user_id=?');
        $q->execute([$data['headline']===''?null:$data['headline'],$data['bio']===''?null:$data['bio'],$userId]);
        $q=$this->pdo->prepare('DELETE FROM user_skills WHERE freelancer_id=?'); $q->execute([$userId]);
        $q=$this->pdo->prepare('INSERT INTO user_skills (freelancer_id,skill_id) VALUES (?,?)');
        foreach ($data['skills'] as $skillId) $q->execute([$userId,$skillId]);
    }
}
