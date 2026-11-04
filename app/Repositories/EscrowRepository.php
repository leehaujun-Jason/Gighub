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

    public function getEscrow(int $jobId): array|false
    {
        $q = $this->pdo->prepare('SELECT id, job_id, bid_id, amount, status, funded_at, settled_at FROM escrows WHERE job_id = ?');
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
}
