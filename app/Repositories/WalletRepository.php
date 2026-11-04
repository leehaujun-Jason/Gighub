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
        $q = $this->pdo->prepare('SELECT id, wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key, created_at FROM wallet_entries WHERE wallet_user_id = ? ORDER BY created_at DESC, id DESC LIMIT 21 OFFSET ?');
        $q->bindValue(1, $userId, PDO::PARAM_INT);
        $q->bindValue(2, ($page - 1) * 20, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
