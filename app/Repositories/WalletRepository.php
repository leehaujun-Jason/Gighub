<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;

class WalletRepository
{
    public function __construct(protected readonly PDO $pdo) {}

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

    public function eligibleUser(int $id, string $role, bool $lock = false): bool
    {
        if ($role === 'client') {
            return $this->eligibleClient($id, $lock);
        }
        if ($role === 'freelancer') {
            return $this->eligibleFreelancer($id, $lock);
        }
        return false;
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

    public function hasDemoCredit(int $userId, bool $lock = false): bool
    {
        $sql = "SELECT id FROM wallet_entries WHERE wallet_user_id = ? AND entry_type = 'demo_credit'" . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$userId]);
        return $q->fetchColumn() !== false;
    }

    public function creditBalance(int $userId, string $amount): int
    {
        $q = $this->pdo->prepare('UPDATE wallets SET balance = balance + ? WHERE user_id = ?');
        $q->execute([$amount, $userId]);
        return $q->rowCount();
    }

    public function insertEntry(int $userId, int $actorId, string $amount, string $operationKey): int
    {
        $q = $this->pdo->prepare("INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key) VALUES (?, NULL, 'demo_credit', ?, 'demo_seed', ?, ?)");
        $q->execute([$userId, $amount, $actorId, $operationKey]);
        return (int)$this->pdo->lastInsertId();
    }

    public function getEntries(int $userId, int $page = 1): array
    {
        $sql = 'SELECT e.id, e.wallet_user_id, e.escrow_id, e.entry_type, e.amount_delta, e.source, e.actor_id, e.operation_key, e.created_at, '
            . 'j.id AS job_id, j.title AS job_title, j.client_id AS job_client_id, b.freelancer_id AS job_freelancer_id '
            . 'FROM wallet_entries e '
            . 'LEFT JOIN escrows esc ON esc.id = e.escrow_id '
            . 'LEFT JOIN jobs j ON j.id = esc.job_id '
            . 'LEFT JOIN bids b ON b.id = j.awarded_bid_id '
            . 'WHERE e.wallet_user_id = ? '
            . 'ORDER BY e.created_at DESC, e.id DESC '
            . 'LIMIT 21 OFFSET ?';
        $q = $this->pdo->prepare($sql);
        $q->bindValue(1, $userId, PDO::PARAM_INT);
        $q->bindValue(2, ($page - 1) * 20, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isClientJobOwner(int $clientId, int $jobId): bool
    {
        $q = $this->pdo->prepare('SELECT id FROM jobs WHERE id = ? AND client_id = ?');
        $q->execute([$jobId, $clientId]);
        return $q->fetchColumn() !== false;
    }

    public function isFreelancerAwardedJob(int $freelancerId, int $jobId): bool
    {
        $sql = 'SELECT j.id FROM jobs j JOIN bids b ON b.id = j.awarded_bid_id WHERE j.id = ? AND b.freelancer_id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $freelancerId]);
        return $q->fetchColumn() !== false;
    }
}
