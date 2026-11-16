<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;

class SubmissionRepository
{
    public function addLinks(int $submissionId, array $links): void
    {
        if ($links === []) return;
        $query = $this->pdo->prepare('INSERT INTO work_submission_links (submission_id, label, url, position) VALUES (?, ?, ?, ?)');
        foreach ($links as $index => $link) {
            $query->execute([$submissionId, $link['label'], $link['url'], $index + 1]);
        }
    }

    public function withLinks(array $attempts, int $jobId): array
    {
        if ($attempts === []) return [];
        $query = $this->pdo->prepare('SELECT l.submission_id, l.label, l.url FROM work_submission_links l
            JOIN work_submissions s ON s.id = l.submission_id WHERE s.job_id = ? ORDER BY l.submission_id, l.position');
        $query->execute([$jobId]);
        $byAttempt = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $byAttempt[$link['submission_id']][] = $link;
        }
        foreach ($attempts as &$attempt) {
            $attempt['links'] = $byAttempt[$attempt['id']] ?? [];
        }
        return $attempts;
    }

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

    public function escrow(int $jobId): array|false
    {
        $q = $this->pdo->prepare('SELECT id, job_id, bid_id, amount, status, funded_at, settled_at FROM escrows WHERE job_id = ?');
        $q->execute([$jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
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

    public function latestSubmissionForMilestone(int $jobId, int $milestoneId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at'
            . ' FROM work_submissions WHERE job_id = ? AND milestone_id = ? ORDER BY id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $milestoneId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function submissionById(int $submissionId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at'
            . ' FROM work_submissions WHERE id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$submissionId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function approveSubmission(int $submissionId, int $reviewerId, ?string $feedback): int
    {
        $sql = "UPDATE work_submissions SET status = 'approved', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), feedback = ? WHERE id = ? AND status = 'submitted'";
        $q = $this->pdo->prepare($sql);
        $q->execute([$reviewerId, $feedback, $submissionId]);
        return $q->rowCount();
    }

    public function requestSubmissionRevision(int $submissionId, int $reviewerId, string $feedback): int
    {
        $sql = "UPDATE work_submissions SET status = 'revisions_requested', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), feedback = ? WHERE id = ? AND status = 'submitted'";
        $q = $this->pdo->prepare($sql);
        $q->execute([$reviewerId, $feedback, $submissionId]);
        return $q->rowCount();
    }

    public function approveMilestoneStatus(int $jobId, int $milestoneId): int
    {
        $sql = "UPDATE milestones SET status = 'approved', approved_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ? AND job_id = ? AND status = 'submitted'";
        $q = $this->pdo->prepare($sql);
        $q->execute([$milestoneId, $jobId]);
        return $q->rowCount();
    }

    public function requestMilestoneRevisionStatus(int $jobId, int $milestoneId): int
    {
        $sql = "UPDATE milestones SET status = 'revisions_requested', approved_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND job_id = ? AND status = 'submitted'";
        $q = $this->pdo->prepare($sql);
        $q->execute([$milestoneId, $jobId]);
        return $q->rowCount();
    }

    public function createFinalSubmission(int $jobId, int $freelancerId, string $notes, ?string $deliverableUrl): int
    {
        $sql = "INSERT INTO work_submissions (job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at)"
            . " VALUES (?, NULL, ?, 'final', ?, ?, 'submitted', UTC_TIMESTAMP(), NULL, NULL, NULL, UTC_TIMESTAMP())";
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $freelancerId, $notes, $deliverableUrl]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateJobStatus(int $jobId, string $status, ?string $expectedCurrentStatus = null): int
    {
        if ($expectedCurrentStatus !== null) {
            $sql = 'UPDATE jobs SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = ?';
            $q = $this->pdo->prepare($sql);
            $q->execute([$status, $jobId, $expectedCurrentStatus]);
        } else {
            $sql = 'UPDATE jobs SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?';
            $q = $this->pdo->prepare($sql);
            $q->execute([$status, $jobId]);
        }
        return $q->rowCount();
    }

    public function hasUnapprovedPredecessor(int $jobId, int $position, bool $lock = false): bool
    {
        $sql = "SELECT id FROM milestones WHERE job_id = ? AND position < ? AND status <> 'approved' LIMIT 1"
            . ($lock ? ' FOR UPDATE' : '');
        $query = $this->pdo->prepare($sql);
        $query->execute([$jobId, $position]);
        return $query->fetchColumn() !== false;
    }

    public function allMilestonesApproved(int $jobId, bool $lock = false): bool
    {
        $sql = 'SELECT status FROM milestones WHERE job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        $rows = $q->fetchAll(PDO::FETCH_COLUMN);
        if (empty($rows)) {
            return false;
        }
        foreach ($rows as $status) {
            if ($status !== 'approved') {
                return false;
            }
        }
        return true;
    }

    public function milestonesForJob(int $jobId): array
    {
        $sql = 'SELECT id, job_id, title, description, due_date, position, status, approved_at, created_at, updated_at'
            . ' FROM milestones WHERE job_id = ? ORDER BY position ASC, id ASC';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function finalSubmissionsForJob(int $jobId): array
    {
        $sql = 'SELECT id, job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at'
            . " FROM work_submissions WHERE job_id = ? AND submission_type = 'final' ORDER BY id DESC";
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function latestFinalSubmissionForJob(int $jobId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, job_id, milestone_id, submitted_by, submission_type, notes, deliverable_url, status, submitted_at, reviewed_by, reviewed_at, feedback, created_at'
            . " FROM work_submissions WHERE job_id = ? AND submission_type = 'final' ORDER BY id DESC LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function hasApprovedFinalSubmission(int $jobId, bool $lock = false): bool
    {
        $sql = "SELECT id FROM work_submissions WHERE job_id = ? AND submission_type = 'final' AND status = 'approved' LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetchColumn() !== false;
    }

    public function hasPendingFinalSubmission(int $jobId, bool $lock = false): bool
    {
        $sql = "SELECT id FROM work_submissions WHERE job_id = ? AND submission_type = 'final' AND status = 'submitted' LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetchColumn() !== false;
    }
}
