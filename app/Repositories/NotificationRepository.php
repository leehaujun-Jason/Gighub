<?php
declare(strict_types=1);

namespace GigHub\Repositories;

use PDO;

class NotificationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Create a new notification record.
     */
    public function create(int $recipientId, ?int $jobId, string $type, string $message): int
    {
        $q = $this->pdo->prepare('INSERT INTO notifications (recipient_id, job_id, type, message, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
        $q->execute([$recipientId, $jobId, $type, $message]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Fetch paginated notifications for a recipient.
     * When $filter is 'unread', only unread notifications (read_at IS NULL) are returned.
     */
    public function getForUser(int $userId, ?string $filter = null, int $limit = 20, int $offset = 0): array
    {
        $sql = 'SELECT n.id, n.recipient_id, n.job_id, n.type, n.message, n.read_at, n.created_at, j.title AS job_title '
            . 'FROM notifications n '
            . 'LEFT JOIN jobs j ON j.id = n.job_id '
            . 'WHERE n.recipient_id = ? ';

        if ($filter === 'unread') {
            $sql .= 'AND n.read_at IS NULL ';
        }

        $sql .= 'ORDER BY n.id DESC LIMIT ? OFFSET ?';

        $q = $this->pdo->prepare($sql);
        $q->bindValue(1, $userId, PDO::PARAM_INT);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->bindValue(3, $offset, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count unread notifications for a user.
     */
    public function countUnread(int $userId): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND read_at IS NULL');
        $q->execute([$userId]);
        return (int)$q->fetchColumn();
    }

    /**
     * Count total notifications for a user based on the active filter.
     */
    public function countTotal(int $userId, ?string $filter = null): int
    {
        $sql = 'SELECT COUNT(*) FROM notifications WHERE recipient_id = ?';
        if ($filter === 'unread') {
            $sql .= ' AND read_at IS NULL';
        }
        $q = $this->pdo->prepare($sql);
        $q->execute([$userId]);
        return (int)$q->fetchColumn();
    }

    /**
     * Mark a single notification as read, strictly scoped to the recipient user.
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        $q = $this->pdo->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE id = ? AND recipient_id = ? AND read_at IS NULL');
        $q->execute([$notificationId, $userId]);
        return $q->rowCount() > 0;
    }

    /**
     * Mark all unread notifications as read for a user.
     */
    public function markAllAsRead(int $userId): int
    {
        $q = $this->pdo->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE recipient_id = ? AND read_at IS NULL');
        $q->execute([$userId]);
        return $q->rowCount();
    }

    /**
     * Retrieve a specific notification record owned by the user.
     */
    public function findForUser(int $notificationId, int $userId): ?array
    {
        $q = $this->pdo->prepare('SELECT id, recipient_id, job_id, type, message, read_at, created_at FROM notifications WHERE id = ? AND recipient_id = ?');
        $q->execute([$notificationId, $userId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
