<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;
class JobRepository
{
    public function __construct(protected readonly PDO $pdo) {}
    public function listOwned(int $clientId,int $page): array
    {
        $q=$this->pdo->prepare('SELECT id,title,budget,deadline,status,created_at FROM jobs WHERE client_id=? ORDER BY created_at DESC,id DESC LIMIT 21 OFFSET ?');
        $q->bindValue(1,$clientId,PDO::PARAM_INT);$q->bindValue(2,($page-1)*20,PDO::PARAM_INT);$q->execute();return $q->fetchAll();
    }
    public function findOwned(int $id,int $clientId,bool $lock=false): array|false
    {
        $q=$this->pdo->prepare('SELECT id,client_id,title,description,budget,deadline,status FROM jobs WHERE id=? AND client_id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$id,$clientId]);return $q->fetch();
    }
    public function skillsFor(int $id): array
    {
        $q=$this->pdo->prepare('SELECT s.id,s.name FROM skills s JOIN job_skills js ON js.skill_id=s.id WHERE js.job_id=? ORDER BY s.name,s.id');$q->execute([$id]);return $q->fetchAll();
    }
    public function eligibleClient(int $id): bool
    {
        $q=$this->pdo->prepare("SELECT u.id FROM users u JOIN client_profiles p ON p.user_id=u.id WHERE u.id=? AND u.role='client' AND u.status='active' AND u.email_verified_at IS NOT NULL FOR UPDATE");
        $q->execute([$id]);return $q->fetchColumn()!==false;
    }
    public function insert(int $clientId,array $data,string $status): int
    {
        $q=$this->pdo->prepare('INSERT INTO jobs (client_id,title,description,budget,deadline,status) VALUES (?,?,?,?,?,?)');
        $q->execute([$clientId,$data['title'],$data['description'],$data['budget'],$data['deadline'],$status]);return (int)$this->pdo->lastInsertId();
    }
    public function updateDraft(int $id,int $clientId,array $data,string $status): void
    {
        $q=$this->pdo->prepare("UPDATE jobs SET title=?,description=?,budget=?,deadline=?,status=? WHERE id=? AND client_id=? AND status='draft'");
        $q->execute([$data['title'],$data['description'],$data['budget'],$data['deadline'],$status,$id,$clientId]);
    }
    public function replaceSkills(int $id,array $ids): void
    {
        $q=$this->pdo->prepare('DELETE FROM job_skills WHERE job_id=?');$q->execute([$id]);
        $q=$this->pdo->prepare('INSERT INTO job_skills (job_id,skill_id) VALUES (?,?)');foreach($ids as $skill)$q->execute([$id,$skill]);
    }
}
