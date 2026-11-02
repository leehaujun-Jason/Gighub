<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;

class BidRepository
{
    public function __construct(private readonly PDO $pdo) {}
    public function job(int $id,bool $lock=false): array|false
    {
        $q=$this->pdo->prepare('SELECT id,client_id,title,status,awarded_bid_id,awarded_at,deadline FROM jobs WHERE id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC);
    }
    public function eligible(int $id,string $role,bool $lock=false): bool
    {
        // Role is an internal allowlist, never a submitted table name.
        $table=match($role) {'client'=>'client_profiles','freelancer'=>'freelancer_profiles',default=>throw new \InvalidArgumentException('Invalid role.')};
        $q=$this->pdo->prepare("SELECT u.id FROM users u JOIN $table p ON p.user_id=u.id WHERE u.id=? AND u.role=? AND u.status='active' AND u.email_verified_at IS NOT NULL".($lock?' FOR UPDATE':''));
        $q->execute([$id,$role]); return $q->fetchColumn()!==false;
    }
    public function owned(int $id,int $freelancerId,bool $lock=false): array|false
    {
        $q=$this->pdo->prepare('SELECT id,job_id,freelancer_id,amount,delivery_days,cover_letter,status FROM bids WHERE id=? AND freelancer_id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$id,$freelancerId]); return $q->fetch(PDO::FETCH_ASSOC);
    }
    public function forJob(int $jobId,int $freelancerId,bool $lock=false): array|false
    {
        $q=$this->pdo->prepare('SELECT id,job_id,freelancer_id,amount,delivery_days,cover_letter,status FROM bids WHERE job_id=? AND freelancer_id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$jobId,$freelancerId]); return $q->fetch(PDO::FETCH_ASSOC);
    }
    public function insert(int $jobId,int $freelancerId,array $data): int
    {
        $q=$this->pdo->prepare("INSERT INTO bids (job_id,freelancer_id,amount,delivery_days,cover_letter,status) VALUES (?,?,?,?,?,'pending')");
        $q->execute([$jobId,$freelancerId,$data['amount'],$data['delivery_days'],$data['cover_letter']]); return (int)$this->pdo->lastInsertId();
    }
    public function update(int $id,int $freelancerId,array $data,string $from): void
    {
        $q=$this->pdo->prepare("UPDATE bids SET amount=?,delivery_days=?,cover_letter=?,status='pending' WHERE id=? AND freelancer_id=? AND status=?");
        $q->execute([$data['amount'],$data['delivery_days'],$data['cover_letter'],$id,$freelancerId,$from]);
    }
    public function withdraw(int $id,int $freelancerId): void
    {
        $q=$this->pdo->prepare("UPDATE bids SET status='withdrawn' WHERE id=? AND freelancer_id=? AND status='pending'"); $q->execute([$id,$freelancerId]);
    }
    public function supportsWithdrawal(): bool
    {
        $q=$this->pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bids' AND COLUMN_NAME='status'");
        return str_contains((string)$q->fetchColumn(),"'withdrawn'");
    }
    public function mine(int $freelancerId,int $page): array
    {
        $q=$this->pdo->prepare('SELECT b.id,b.job_id,b.amount,b.delivery_days,b.cover_letter,b.status,b.created_at,j.title,j.status AS job_status,j.awarded_bid_id FROM bids b JOIN jobs j ON j.id=b.job_id WHERE b.freelancer_id=? ORDER BY b.created_at DESC,b.id DESC LIMIT 21 OFFSET ?');
        $q->bindValue(1,$freelancerId,PDO::PARAM_INT); $q->bindValue(2,($page-1)*20,PDO::PARAM_INT); $q->execute(); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function clientBids(int $jobId,int $clientId,int $page): array
    {
        $q=$this->pdo->prepare("SELECT b.id,b.freelancer_id,b.amount,b.delivery_days,b.cover_letter,b.status,b.created_at,u.display_name,(u.role='freelancer' AND u.status='active' AND u.email_verified_at IS NOT NULL) AS bidder_eligible FROM bids b JOIN jobs j ON j.id=b.job_id JOIN users u ON u.id=b.freelancer_id WHERE j.id=? AND j.client_id=? ORDER BY b.created_at DESC,b.id DESC LIMIT 21 OFFSET ?");
        $q->bindValue(1,$jobId,PDO::PARAM_INT); $q->bindValue(2,$clientId,PDO::PARAM_INT); $q->bindValue(3,($page-1)*20,PDO::PARAM_INT); $q->execute(); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function bidOnJob(int $jobId,int $bidId,bool $lock=false): array|false
    {
        $q=$this->pdo->prepare('SELECT id,job_id,freelancer_id,status FROM bids WHERE job_id=? AND id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$jobId,$bidId]); return $q->fetch(PDO::FETCH_ASSOC);
    }
    public function selectAward(int $jobId,int $clientId,int $bidId): bool
    {
        $q=$this->pdo->prepare("UPDATE jobs SET status='awarded',awarded_bid_id=?,awarded_at=UTC_TIMESTAMP() WHERE id=? AND client_id=? AND status='open' AND awarded_bid_id IS NULL AND awarded_at IS NULL");
        $q->execute([$bidId,$jobId,$clientId]); return $q->rowCount()===1;
    }
}
