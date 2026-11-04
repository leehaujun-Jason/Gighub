<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;

class SubmissionRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    public function job(int $jobId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, client_id, title, budget, deadline, status, awarded_bid_id FROM jobs WHERE id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function milestone(int $jobId, int $milestoneId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, job_id, title, description, due_date, position, status, approved_at, created_at, updated_at'
            . ' FROM milestones WHERE id = ? AND job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$milestoneId, $jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function isAwardedFreelancer(int $jobId, int $freelancerId): bool
    {
        $sql = 'SELECT b.id FROM bids b JOIN jobs j ON j.id = b.job_id AND j.awarded_bid_id = b.id WHERE j.id = ? AND b.freelancer_id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $freelancerId]);
        return $q->fetchColumn() !== false;
    }

    public function eligibleFreelancer(int $freelancerId, bool $lock = false): bool
    {
        $sql = "SELECT u.id FROM users u JOIN freelancer_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'freelancer' AND u.status = 'active' AND u.email_verified_at IS NOT NULL" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$freelancerId]);
        return $q->fetchColumn() !== false;
    }

    public function eligibleClient(int $clientId, bool $lock = false): bool
    {
        $sql = "SELECT u.id FROM users u JOIN client_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'client' AND u.status = 'active' AND u.email_verified_at IS NOT NULL" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$clientId]);
        return $q->fetchColumn() !== false;
    }

    public function hasLockedEscrow(int $jobId): bool
    {
        $q = $this->pdo->prepare("SELECT id FROM escrows WHERE job_id = ? AND status = 'locked'");
        $q->execute([$jobId]);
        return $q->fetchColumn() !== false;
    }

    public function createMilestoneSubmission(int $jobId, int $milestoneId, int $freelancerId, string $notes, ?string $deliverableUrl): int
    {
        $sql = "INSERT INTO work_submissions (job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at)"
            . " VALUES (?, ?, ?, 'milestone', ?, ?, 'submitted', UTC_TIMESTAMP(), NULL, NULL, NULL, UTC_TIMESTAMP())";
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $milestoneId, $freelancerId, $notes, $deliverableUrl]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateMilestoneStatus(int $jobId, int $milestoneId, string $status): void
    {
        $sql = 'UPDATE milestones SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND job_id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$status, $milestoneId, $jobId]);
    }

    public function submissionsForJob(int $jobId): array
    {
        $sql = 'SELECT id, job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at'
            . ' FROM work_submissions WHERE job_id = ? ORDER BY id DESC';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function submissionsForMilestone(int $jobId, int $milestoneId): array
    {
        $sql = 'SELECT id, job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at'
            . ' FROM work_submissions WHERE job_id = ? AND milestone_id = ? ORDER BY id DESC';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $milestoneId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function latestSubmissionForMilestone(int $jobId, int $milestoneId): array|false
    {
        $sql = 'SELECT id, job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at'
            . ' FROM work_submissions WHERE job_id = ? AND milestone_id = ? ORDER BY id DESC LIMIT 1';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $milestoneId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }
}
