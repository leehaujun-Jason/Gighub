<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;

class MilestoneRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    public function job(int $jobId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, client_id, title, status, deadline, awarded_bid_id FROM jobs WHERE id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function hasEscrow(int $jobId, bool $lock = false): bool
    {
        $sql = 'SELECT id FROM escrows WHERE job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetchColumn() !== false;
    }

    public function eligibleClient(int $id, bool $lock = false): bool
    {
        $sql = "SELECT u.id FROM users u JOIN client_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'client' AND u.status = 'active' AND u.email_verified_at IS NOT NULL" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$id]);
        return $q->fetchColumn() !== false;
    }

    public function eligibleFreelancer(int $id, bool $lock = false): bool
    {
        $sql = "SELECT u.id FROM users u JOIN freelancer_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'freelancer' AND u.status = 'active' AND u.email_verified_at IS NOT NULL" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$id]);
        return $q->fetchColumn() !== false;
    }

    public function isAwardedFreelancer(int $jobId, int $freelancerId): bool
    {
        $sql = "SELECT b.id FROM bids b JOIN jobs j ON j.id = b.job_id AND j.awarded_bid_id = b.id WHERE j.id = ? AND b.freelancer_id = ?";
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $freelancerId]);
        return $q->fetchColumn() !== false;
    }

    public function forJob(int $jobId): array
    {
        $q = $this->pdo->prepare('SELECT id, job_id, title, description, due_date, position, status, approved_at, created_at, updated_at FROM milestones WHERE job_id = ? ORDER BY position ASC, id ASC');
        $q->execute([$jobId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function submissionsForJob(int $jobId): array
    {
        if (!isset($this->pdo)) {
            return [];
        }
        $q = $this->pdo->prepare('SELECT id, job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at FROM work_submissions WHERE job_id = ? ORDER BY id DESC');
        $q->execute([$jobId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id, int $jobId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, job_id, title, description, due_date, position, status, approved_at, created_at, updated_at FROM milestones WHERE id = ? AND job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$id, $jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function nextPosition(int $jobId): int
    {
        $q = $this->pdo->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM milestones WHERE job_id = ? FOR UPDATE');
        $q->execute([$jobId]);
        return (int)$q->fetchColumn();
    }

    public function countForJob(int $jobId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM milestones WHERE job_id = ?');
        $q->execute([$jobId]);
        return (int)$q->fetchColumn();
    }

    public function insert(int $jobId, string $title, ?string $description, string $dueDate, int $position): int
    {
        $q = $this->pdo->prepare("INSERT INTO milestones (job_id, title, description, due_date, position, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $q->execute([$jobId, $title, $description, $dueDate, $position]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, int $jobId, string $title, ?string $description, string $dueDate): void
    {
        $q = $this->pdo->prepare("UPDATE milestones SET title = ?, description = ?, due_date = ? WHERE id = ? AND job_id = ? AND status = 'pending'");
        $q->execute([$title, $description, $dueDate, $id, $jobId]);
    }

    public function delete(int $id, int $jobId): void
    {
        $q = $this->pdo->prepare("DELETE FROM milestones WHERE id = ? AND job_id = ? AND status = 'pending'");
        $q->execute([$id, $jobId]);
    }

    public function shiftPositionsDown(int $jobId, int $deletedPosition): void
    {
        // Safe sequential reindexing: updates rows in ascending order so each moves into the vacated slot without duplicate collision.
        $q = $this->pdo->prepare('UPDATE milestones SET position = position - 1 WHERE job_id = ? AND position > ? ORDER BY position ASC');
        $q->execute([$jobId, $deletedPosition]);
    }
}
