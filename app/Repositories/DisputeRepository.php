<?php
declare(strict_types=1);

namespace GigHub\Repositories;

use PDO;

class DisputeRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Fetch job row with optional FOR UPDATE lock.
     */
    public function getJob(int $jobId, bool $lock = false): ?array
    {
        $sql = 'SELECT id, client_id, awarded_bid_id, title, description, budget, deadline, status, awarded_at, completed_at, closed_at, created_at FROM jobs WHERE id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch awarded bid row with optional FOR UPDATE lock.
     */
    public function getBid(int $bidId, bool $lock = false): ?array
    {
        $sql = 'SELECT id, job_id, freelancer_id, amount, delivery_days, cover_letter, status, created_at FROM bids WHERE id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$bidId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch user profile summary.
     */
    public function getUser(int $userId): ?array
    {
        $sql = 'SELECT id, email, role, status, display_name FROM users WHERE id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$userId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch cancellation request for a job.
     */
    public function getCancellationRequest(int $jobId, bool $lock = false): ?array
    {
        $sql = 'SELECT cr.id, cr.job_id, cr.requested_by, cr.reason, cr.status, cr.responded_by, cr.created_at, cr.responded_at,
                       ur.display_name AS requester_name,
                       resp.display_name AS responder_name
                FROM cancellation_requests cr
                JOIN users ur ON ur.id = cr.requested_by
                LEFT JOIN users resp ON resp.id = cr.responded_by
                WHERE cr.job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Insert a new cancellation request.
     */
    public function createCancellationRequest(int $jobId, int $requestedBy, string $reason): int
    {
        $sql = 'INSERT INTO cancellation_requests (job_id, requested_by, reason, status, created_at) VALUES (?, ?, ?, \'pending\', UTC_TIMESTAMP())';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $requestedBy, $reason]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Update cancellation request status (accepted or rejected).
     */
    public function respondCancellationRequest(int $requestId, string $status, int $respondedBy): bool
    {
        $sql = 'UPDATE cancellation_requests SET status = ?, responded_by = ?, responded_at = UTC_TIMESTAMP() WHERE id = ? AND status = \'pending\'';
        $q = $this->pdo->prepare($sql);
        $q->execute([$status, $respondedBy, $requestId]);
        return $q->rowCount() === 1;
    }

    /**
     * Mark a pending cancellation request as superseded.
     */
    public function supersedeCancellationRequest(int $jobId): bool
    {
        $sql = 'UPDATE cancellation_requests SET status = \'superseded\' WHERE job_id = ? AND status = \'pending\'';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->rowCount() > 0;
    }

    /**
     * Fetch dispute record for a job.
     */
    public function getDisputeByJobId(int $jobId, bool $lock = false): ?array
    {
        $sql = 'SELECT d.id, d.job_id, d.raised_by, d.reason, d.response, d.admin_id, d.status, d.decision, d.decision_note, d.created_at, d.resolved_at,
                       ur.display_name AS raiser_name,
                       ua.display_name AS admin_name
                FROM disputes d
                JOIN users ur ON ur.id = d.raised_by
                LEFT JOIN users ua ON ua.id = d.admin_id
                WHERE d.job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch dispute record by dispute ID.
     */
    public function getDisputeById(int $disputeId, bool $lock = false): ?array
    {
        $sql = 'SELECT d.id, d.job_id, d.raised_by, d.reason, d.response, d.admin_id, d.status, d.decision, d.decision_note, d.created_at, d.resolved_at,
                       ur.display_name AS raiser_name,
                       ua.display_name AS admin_name
                FROM disputes d
                JOIN users ur ON ur.id = d.raised_by
                LEFT JOIN users ua ON ua.id = d.admin_id
                WHERE d.id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$disputeId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Insert a new dispute.
     */
    public function createDispute(int $jobId, int $raisedBy, string $reason): int
    {
        $sql = 'INSERT INTO disputes (job_id, raised_by, reason, status, created_at) VALUES (?, ?, ?, \'open\', UTC_TIMESTAMP())';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId, $raisedBy, $reason]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Submit counterpart written response to a dispute.
     */
    public function submitDisputeResponse(int $disputeId, string $response): bool
    {
        $sql = 'UPDATE disputes SET response = ? WHERE id = ? AND status = \'open\' AND response IS NULL';
        $q = $this->pdo->prepare($sql);
        $q->execute([$response, $disputeId]);
        return $q->rowCount() === 1;
    }

    /**
     * Resolve a dispute with Admin decision.
     */
    public function resolveDispute(int $disputeId, string $decision, int $adminId, string $decisionNote): bool
    {
        $sql = 'UPDATE disputes SET status = \'resolved\', decision = ?, admin_id = ?, decision_note = ?, resolved_at = UTC_TIMESTAMP() WHERE id = ? AND status = \'open\'';
        $q = $this->pdo->prepare($sql);
        $q->execute([$decision, $adminId, $decisionNote, $disputeId]);
        return $q->rowCount() === 1;
    }

    /**
     * Fetch escrow row for a job.
     */
    public function getEscrow(int $jobId, bool $lock = false): ?array
    {
        $sql = 'SELECT id, job_id, bid_id, amount, status, funded_at, settled_at FROM escrows WHERE job_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Update escrow status to settled (refunded or released).
     */
    public function settleEscrow(int $escrowId, string $status): bool
    {
        $sql = 'UPDATE escrows SET status = ?, settled_at = UTC_TIMESTAMP() WHERE id = ? AND status = \'locked\' AND settled_at IS NULL';
        $q = $this->pdo->prepare($sql);
        $q->execute([$status, $escrowId]);
        return $q->rowCount() === 1;
    }

    /**
     * Ensure a wallet row exists for user.
     */
    public function ensureWallet(int $userId): void
    {
        $sql = 'INSERT INTO wallets (user_id, balance, updated_at) VALUES (?, 0.00, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE user_id = user_id';
        $q = $this->pdo->prepare($sql);
        $q->execute([$userId]);
    }

    /**
     * Fetch wallet row with optional FOR UPDATE lock.
     */
    public function getWallet(int $userId, bool $lock = false): ?array
    {
        $sql = 'SELECT user_id, balance, updated_at FROM wallets WHERE user_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $q = $this->pdo->prepare($sql);
        $q->execute([$userId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Credit a wallet balance.
     */
    public function creditWallet(int $userId, string $amount): bool
    {
        $sql = 'UPDATE wallets SET balance = balance + ?, updated_at = UTC_TIMESTAMP() WHERE user_id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$amount, $userId]);
        return $q->rowCount() === 1;
    }

    /**
     * Insert an immutable ledger entry.
     */
    public function insertLedgerEntry(int $walletUserId, ?int $escrowId, string $entryType, string $amountDelta, string $source, int $actorId, string $operationKey): int
    {
        $sql = 'INSERT INTO wallet_entries (wallet_user_id, escrow_id, entry_type, amount_delta, source, actor_id, operation_key, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())';
        $q = $this->pdo->prepare($sql);
        $q->execute([$walletUserId, $escrowId, $entryType, $amountDelta, $source, $actorId, $operationKey]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Check if a settlement ledger entry already exists for escrow.
     */
    public function hasSettlementLedger(int $escrowId): bool
    {
        $sql = "SELECT 1 FROM wallet_entries WHERE escrow_id = ? AND entry_type IN ('escrow_release', 'escrow_refund') LIMIT 1";
        $q = $this->pdo->prepare($sql);
        $q->execute([$escrowId]);
        return $q->fetchColumn() !== false;
    }

    /**
     * Check if an operation key already exists in ledger.
     */
    public function hasOperationKey(string $key): bool
    {
        $sql = 'SELECT 1 FROM wallet_entries WHERE operation_key = ? LIMIT 1';
        $q = $this->pdo->prepare($sql);
        $q->execute([$key]);
        return $q->fetchColumn() !== false;
    }

    /**
     * Update job status and closing timestamp.
     */
    public function updateJobStatus(int $jobId, string $status, bool $closed = false): bool
    {
        if ($closed) {
            $sql = 'UPDATE jobs SET status = ?, closed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?';
        } else {
            $sql = 'UPDATE jobs SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?';
        }
        $q = $this->pdo->prepare($sql);
        $q->execute([$status, $jobId]);
        return $q->rowCount() === 1;
    }

    /**
     * Create an in-app notification.
     */
    public function createNotification(int $recipientId, ?int $jobId, string $type, string $message): int
    {
        $sql = 'INSERT INTO notifications (recipient_id, job_id, type, message, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())';
        $q = $this->pdo->prepare($sql);
        $q->execute([$recipientId, $jobId, $type, $message]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Fetch dispute queue for Admin dashboard.
     */
    public function getAdminDisputes(string $status = 'open'): array
    {
        $sql = 'SELECT d.id, d.job_id, d.raised_by, d.reason, d.response, d.status, d.decision, d.decision_note, d.created_at, d.resolved_at,
                       j.title AS job_title, j.budget AS job_budget, j.client_id,
                       uc.display_name AS client_name,
                       uf.display_name AS freelancer_name,
                       e.amount AS escrow_amount,
                       ur.display_name AS raiser_name
                FROM disputes d
                JOIN jobs j ON j.id = d.job_id
                JOIN users uc ON uc.id = j.client_id
                LEFT JOIN bids b ON b.id = j.awarded_bid_id
                LEFT JOIN users uf ON uf.id = b.freelancer_id
                LEFT JOIN escrows e ON e.job_id = j.id
                JOIN users ur ON ur.id = d.raised_by
                WHERE d.status = ?
                ORDER BY d.id DESC';
        $q = $this->pdo->prepare($sql);
        $q->execute([$status]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count open disputes for Admin badge/summary.
     */
    public function countOpenDisputes(): int
    {
        $sql = 'SELECT COUNT(*) FROM disputes WHERE status = \'open\'';
        $q = $this->pdo->query($sql);
        return (int)$q->fetchColumn();
    }

    /**
     * Count total chat messages for a bid (dispute evidence pagination).
     */
    public function countDisputeChatMessages(int $bidId): int
    {
        $sql = 'SELECT COUNT(*) FROM chat_messages WHERE bid_id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$bidId]);
        return (int)$q->fetchColumn();
    }

    /**
     * Retrieve chronologically ordered bid chat messages for dispute evidence.
     * Read-only. Does NOT alter chat_read_watermarks.
     */
    public function getDisputeChatMessages(int $bidId, int $limit = 500, int $offset = 0): array
    {
        $sql = 'SELECT cm.id, cm.bid_id, cm.sender_id, cm.body, cm.created_at, u.display_name AS sender_name
                FROM chat_messages cm
                JOIN users u ON u.id = cm.sender_id
                WHERE cm.bid_id = ?
                ORDER BY cm.id ASC
                LIMIT ? OFFSET ?';
        $q = $this->pdo->prepare($sql);
        $q->bindValue(1, $bidId, PDO::PARAM_INT);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->bindValue(3, $offset, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieve milestone and submission evidence for a disputed job.
     */
    public function getJobMilestonesAndSubmissions(int $jobId): array
    {
        $sql = 'SELECT m.id AS milestone_id, m.title AS milestone_title, m.status AS milestone_status, m.approved_at,
                       ws.id AS submission_id, ws.notes, ws.deliverable_url, ws.status AS submission_status, ws.feedback AS review_feedback, ws.submitted_at
                FROM milestones m
                LEFT JOIN work_submissions ws ON ws.milestone_id = m.id
                WHERE m.job_id = ?
                ORDER BY m.id ASC, ws.id ASC';
        $q = $this->pdo->prepare($sql);
        $q->execute([$jobId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
