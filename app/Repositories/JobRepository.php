<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;
class JobRepository
{
    public function __construct(protected readonly PDO $pdo) {}
    public function listOwned(int $clientId,int $page, ?string $status = null): array
    {
        $filters = $status === null ? [] : explode(',', $status);
        $placeholders = implode(',', array_fill(0, count($filters), '?'));
        $q=$this->pdo->prepare('SELECT j.id,title,budget,deadline,status,created_at,awarded_bid_id,awarded_at,
            EXISTS(SELECT 1 FROM bids b WHERE b.job_id=j.id) AS has_bids,
            EXISTS(SELECT 1 FROM milestones m WHERE m.job_id=j.id) AS has_milestones
            FROM jobs j WHERE client_id=?' . ($status === null ? '' : ' AND j.status IN (' . $placeholders . ')') . ' ORDER BY created_at DESC,id DESC LIMIT 21 OFFSET ?');
        $q->bindValue(1,$clientId,PDO::PARAM_INT);
        foreach ($filters as $index => $filter) $q->bindValue($index + 2,$filter);
        $q->bindValue(count($filters) + 2,($page-1)*20,PDO::PARAM_INT);
        $q->execute();return $q->fetchAll();
    }
    public function findOwned(int $id,int $clientId,bool $lock=false): array|false
    {
        $q=$this->pdo->prepare('SELECT * FROM jobs WHERE id=? AND client_id=?'.($lock?' FOR UPDATE':''));
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
        $q=$this->pdo->prepare("UPDATE jobs SET title=?,description=?,budget=?,deadline=?,status=? WHERE id=? AND client_id=? AND status IN ('draft','open') AND awarded_bid_id IS NULL");
        $q->execute([$data['title'],$data['description'],$data['budget'],$data['deadline'],$status,$id,$clientId]);
    }
    public function hasBids(int $id): bool
    {
        $q = $this->pdo->prepare('SELECT id FROM bids WHERE job_id=? LIMIT 1 FOR UPDATE');
        $q->execute([$id]);
        return $q->fetchColumn() !== false;
    }

    public function removeBeforeAward(int $id, int $clientId): void
    {
        if ($this->hasBids($id)) {
            $q = $this->pdo->prepare("UPDATE jobs SET status='cancelled', closed_at=UTC_TIMESTAMP() WHERE id=? AND client_id=?");
            $q->execute([$id, $clientId]);
            $q = $this->pdo->prepare("INSERT INTO notifications (recipient_id,job_id,type,message)
                SELECT DISTINCT freelancer_id,job_id,'job_cancelled','The client closed this job before awarding it.' FROM bids WHERE job_id=?");
            $q->execute([$id]);
            return;
        }
        $this->replaceSkills($id, []);
        $q=$this->pdo->prepare('DELETE FROM job_requirement_documents WHERE job_id=?');
        $q->execute([$id]);
        $q = $this->pdo->prepare('DELETE FROM jobs WHERE id=? AND client_id=?');
        $q->execute([$id, $clientId]);
    }
    public function replaceSkills(int $id,array $ids): void
    {
        $q=$this->pdo->prepare('DELETE FROM job_skills WHERE job_id=?');$q->execute([$id]);
        $q=$this->pdo->prepare('INSERT INTO job_skills (job_id,skill_id) VALUES (?,?)');foreach($ids as $skill)$q->execute([$id,$skill]);
    }
}
