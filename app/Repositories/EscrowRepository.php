<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;

class EscrowRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    public function job(int $jobId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, client_id, title, status, budget, deadline, awarded_bid_id, awarded_at FROM jobs WHERE id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function eligibleClient(int $id, bool $lock = false): bool
    {
        $sql = "SELECT u.id FROM users u JOIN client_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'client' AND u.status = 'active' AND u.email_verified_at IS NOT NULL" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$id]);
        return $q->fetchColumn() !== false;
    }

    public function awardedBid(int $jobId, int $bidId, bool $lock = false): array|false
    {
        $sql = 'SELECT b.id, b.job_id, b.freelancer_id, b.amount, b.delivery_days, b.status, b.created_at, u.display_name AS freelancer_name'
            . ' FROM bids b'
            . ' JOIN users u ON u.id = b.freelancer_id'
            . ' WHERE b.id = ? AND b.job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$bidId, $jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function milestones(int $jobId): array
    {
        $q = $this->pdo->prepare('SELECT id, job_id, title, description, due_date, position, status FROM milestones WHERE job_id = ? ORDER BY position ASC, id ASC');
        $q->execute([$jobId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function milestoneCount(int $jobId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM milestones WHERE job_id = ?');
        $q->execute([$jobId]);
        return (int)$q->fetchColumn();
    }

    public function hasEscrow(int $jobId, bool $lock = false): bool
    {
        $sql = 'SELECT id FROM escrows WHERE job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetchColumn() !== false;
    }

    public function getEscrow(int $jobId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, job_id, bid_id, amount, status, funded_at, settled_at FROM escrows WHERE job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function getWallet(int $userId, bool $lock = false): array|false
    {
        $sql = 'SELECT user_id, balance, updated_at FROM wallets WHERE user_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$userId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function ensureWallet(int $userId): void
    {
        $q = $this->pdo->prepare('INSERT INTO wallets (user_id, balance) VALUES (?, 0.00) ON DUPLICATE KEY UPDATE user_id = user_id');
        $q->execute([$userId]);
    }

    public function debitWallet(int $userId, string $amount): bool
    {
        $q = $this->pdo->prepare('UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND balance >= ?');
        $q->execute([$amount, $userId, $amount]);
        return $q->rowCount() === 1;
    }

    public function createEscrow(int $jobId, int $bidId, string $amount): int
    {
        $q = $this->pdo->prepare("INSERT INTO escrows (job_id, bid_id, amount, status, funded_at) VALUES (?, ?, ?, 'locked', UTC_TIMESTAMP())");
        $q->execute([$jobId, $bidId, $amount]);
        return (int)$this->pdo->lastInsertId();
    }

    public function insertLedgerDebit(int $userId, int $escrowId, string $amountDelta, int $actorId, string $operationKey): int
    {
        $q = $this->pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key) VALUES (?, ?, 'escrow_fund', ?, 'client_funding', ?, ?)");
        $q->execute([$userId, $escrowId, $amountDelta, $actorId, $operationKey]);
        return (int)$this->pdo->lastInsertId();
    }

    public function transitionJobToInProgress(int $jobId, int $bidId): bool
    {
        $q = $this->pdo->prepare("UPDATE jobs SET status = 'in_progress' WHERE id = ? AND status = 'awarded' AND awarded_bid_id = ?");
        $q->execute([$jobId, $bidId]);
        return $q->rowCount() === 1;
    }

    public function acceptWinningBid(int $jobId, int $bidId): bool
    {
        $q = $this->pdo->prepare("UPDATE bids SET status = 'accepted' WHERE id = ? AND job_id = ? AND status = 'pending'");
        $q->execute([$bidId, $jobId]);
        return $q->rowCount() === 1;
    }

    public function rejectLosingBids(int $jobId, int $winningBidId): int
    {
        $q = $this->pdo->prepare("UPDATE bids SET status = 'rejected' WHERE job_id = ? AND id != ? AND status = 'pending'");
        $q->execute([$jobId, $winningBidId]);
        return $q->rowCount();
    }

    public function eligibleFreelancer(int $id, bool $lock = false): bool
    {
        $sql = "SELECT u.id FROM users u JOIN freelancer_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'freelancer' AND u.status = 'active' AND u.email_verified_at IS NOT NULL" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$id]);
        return $q->fetchColumn() !== false;
    }

    public function awardedFreelancerUser(int $freelancerId, bool $lock = false): array|false
    {
        $sql = "SELECT u.id, u.display_name, u.role, u.status, u.email_verified_at FROM users u JOIN freelancer_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'freelancer'" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$freelancerId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function lockMilestones(int $jobId): array
    {
        $q = $this->pdo->prepare('SELECT id, status FROM milestones WHERE job_id = ? ORDER BY id ASC FOR UPDATE');
        $q->execute([$jobId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function lockLatestFinalSubmission(int $jobId): array|false
    {
        $q = $this->pdo->prepare("SELECT id, status, submission_type FROM work_submissions WHERE job_id = ? AND submission_type = 'final' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $q->execute([$jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function hasSettlementLedger(int $escrowId): bool
    {
        $q = $this->pdo->prepare("SELECT id FROM wallet_entries WHERE escrow_id = ? AND entry_type IN ('escrow_release', 'escrow_refund')");
        $q->execute([$escrowId]);
        return $q->fetchColumn() !== false;
    }

    public function hasOperationKey(string $key): bool
    {
        $q = $this->pdo->prepare('SELECT id FROM wallet_entries WHERE operation_key = ?');
        $q->execute([$key]);
        return $q->fetchColumn() !== false;
    }

    public function creditWallet(int $userId, string $amount): bool
    {
        $q = $this->pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE user_id = ?');
        $q->execute([$amount, $userId]);
        return $q->rowCount() === 1;
    }

    public function releaseEscrow(int $escrowId): bool
    {
        $q = $this->pdo->prepare("UPDATE escrows SET status = 'released', settled_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'locked' AND settled_at IS NULL");
        $q->execute([$escrowId]);
        return $q->rowCount() === 1;
    }

    public function insertLedgerRelease(int $freelancerId, int $escrowId, string $amount, int $actorId, string $operationKey): int
    {
        $q = $this->pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key) VALUES (?, ?, 'escrow_release', ?, 'client_approval', ?, ?)");
        $q->execute([$freelancerId, $escrowId, $amount, $actorId, $operationKey]);
        return (int)$this->pdo->lastInsertId();
    }

    public function completeJob(int $jobId): bool
    {
        $q = $this->pdo->prepare("UPDATE jobs SET status = 'completed', completed_at = UTC_TIMESTAMP(), closed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'under_review'");
        $q->execute([$jobId]);
        return $q->rowCount() === 1;
    }

    public function createPaymentNotification(int $recipientId, int $jobId, string $message): void
    {
        $q = $this->pdo->prepare("INSERT INTO notifications (recipient_id, job_id, type, message) VALUES (?, ?, 'payment_released', ?)");
        $q->execute([$recipientId, $jobId, $message]);
    }

    public function getSettlementDetails(int $jobId): array|false
    {
        $sql = 'SELECT j.id AS job_id, j.title AS job_title, j.client_id, j.status AS job_status, j.completed_at, '
            . 'b.id AS bid_id, b.freelancer_id, b.amount AS bid_amount, u.display_name AS freelancer_name, '
            . 'e.id AS escrow_id, e.amount AS escrow_amount, e.status AS escrow_status, e.settled_at '
            . 'FROM jobs j '
            . 'JOIN bids b ON b.id = j.awarded_bid_id '
            . 'JOIN users u ON u.id = b.freelancer_id '
            . 'LEFT JOIN escrows e ON e.job_id = j.id '
            . 'WHERE j.id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }
}
