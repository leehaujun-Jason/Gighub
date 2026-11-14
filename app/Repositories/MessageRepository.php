<?php
declare(strict_types=1);

namespace GigHub\Repositories;

use PDO;

class MessageRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Retrieve conversation context for a given bid.
     * Returns job and bid details along with both participant user profiles.
     */
    public function getConversationContext(int $bidId): ?array
    {
        $sql = 'SELECT 
                    b.id AS bid_id,
                    b.job_id,
                    b.freelancer_id,
                    b.amount AS bid_amount,
                    b.status AS bid_status,
                    j.client_id,
                    j.title AS job_title,
                    j.status AS job_status,
                    j.awarded_bid_id,
                    uc.display_name AS client_name,
                    uf.display_name AS freelancer_name
                FROM bids b
                JOIN jobs j ON j.id = b.job_id
                JOIN users uc ON uc.id = j.client_id
                JOIN users uf ON uf.id = b.freelancer_id
                WHERE b.id = ?';

        $q = $this->pdo->prepare($sql);
        $q->execute([$bidId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Retrieve all messages in a conversation in chronological order.
     */
    public function getMessages(int $bidId, int $limit = 100): array
    {
        $sql = 'SELECT 
                    cm.id,
                    cm.bid_id,
                    cm.sender_id,
                    cm.body,
                    cm.attachment_name,
                    cm.attachment_image,
                    cm.created_at,
                    u.display_name AS sender_name
                FROM chat_messages cm
                JOIN users u ON u.id = cm.sender_id
                WHERE cm.bid_id = ?
                ORDER BY cm.id ASC
                LIMIT ?';

        $q = $this->pdo->prepare($sql);
        $q->bindValue(1, $bidId, PDO::PARAM_INT);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->execute();
        return $this->withAttachments($q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Retrieve messages posted after $sinceId for lightweight polling.
     */
    public function getMessagesSince(int $bidId, int $sinceId, int $limit = 50): array
    {
        $sql = 'SELECT 
                    cm.id,
                    cm.bid_id,
                    cm.sender_id,
                    cm.body,
                    cm.attachment_name,
                    cm.attachment_image,
                    cm.created_at,
                    u.display_name AS sender_name
                FROM chat_messages cm
                JOIN users u ON u.id = cm.sender_id
                WHERE cm.bid_id = ? AND cm.id > ?
                ORDER BY cm.id ASC
                LIMIT ?';

        $q = $this->pdo->prepare($sql);
        $q->bindValue(1, $bidId, PDO::PARAM_INT);
        $q->bindValue(2, $sinceId, PDO::PARAM_INT);
        $q->bindValue(3, $limit, PDO::PARAM_INT);
        $q->execute();
        return $this->withAttachments($q->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Insert a new message into chat_messages.
     */
    public function createMessage(int $bidId, int $senderId, string $body): int
    {
        $sql = 'INSERT INTO chat_messages (bid_id, sender_id, body, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())';
        $q = $this->pdo->prepare($sql);
        $q->execute([$bidId, $senderId, $body]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createWithAttachment(int $bidId, int $senderId, string $body, array $attachment): int
    {
        $query = $this->pdo->prepare('INSERT INTO chat_messages
            (bid_id, sender_id, body, created_at, attachment_key, attachment_name, attachment_image)
            VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?)');
        $query->execute([$bidId, $senderId, $body, $attachment['key'], $attachment['name'], (int)$attachment['image']]);
        return (int)$this->pdo->lastInsertId();
    }

    private function withAttachments(array $messages): array
    {
        if (!$messages) return [];
        $ids = array_column($messages, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $this->pdo->prepare("SELECT id,message_id,attachment_name,attachment_image FROM chat_message_attachments WHERE message_id IN ($marks) ORDER BY id");
        $q->execute($ids);
        $extras = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $extras[$row['message_id']][] = ['id'=>(int)$row['id'], 'name'=>$row['attachment_name'], 'image'=>(bool)$row['attachment_image']];
        }
        foreach ($messages as &$message) {
            $first = $message['attachment_name'] === null ? [] : [['id'=>null, 'name'=>$message['attachment_name'], 'image'=>(bool)$message['attachment_image']]];
            $message['attachments'] = array_merge($first, $extras[$message['id']] ?? []);
        }
        return $messages;
    }

    public function createWithAttachments(int $bidId, int $senderId, string $body, array $files): array
    {
        $this->pdo->beginTransaction();
        try {
            $first = array_shift($files);
            $id = $this->createWithAttachment($bidId, $senderId, $body, $first);
            $items = [['id'=>null, 'name'=>$first['name'], 'image'=>$first['image']]];
            $q = $this->pdo->prepare('INSERT INTO chat_message_attachments (message_id,attachment_key,attachment_name,attachment_image) VALUES (?,?,?,?)');
            foreach ($files as $file) {
                $q->execute([$id,$file['key'],$file['name'],(int)$file['image']]);
                $items[] = ['id'=>(int)$this->pdo->lastInsertId(), 'name'=>$file['name'], 'image'=>$file['image']];
            }
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        // A failed commit acknowledgement can leave the outcome unknown.
        try {
            if (!$this->pdo->commit()) throw new \RuntimeException();
        } catch (\Throwable $error) {
            throw new \RuntimeException('Message commit unconfirmed.', 0, $error);
        }
        return [$id, $items];
    }

    public function findAttachment(int $bidId, int $messageId, ?int $attachmentId = null): array|false
    {
        if ($attachmentId !== null) {
            $query = $this->pdo->prepare('SELECT a.attachment_key,a.attachment_name,a.attachment_image
                FROM chat_message_attachments a JOIN chat_messages m ON m.id=a.message_id
                WHERE m.bid_id=? AND m.id=? AND a.id=?');
            $query->execute([$bidId,$messageId,$attachmentId]);
            return $query->fetch(PDO::FETCH_ASSOC);
        }
        $query = $this->pdo->prepare('SELECT attachment_key, attachment_name, attachment_image
            FROM chat_messages WHERE bid_id = ? AND id = ? AND attachment_key IS NOT NULL');
        $query->execute([$bidId, $messageId]);
        return $query->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch the most recent message sent by this user in this conversation.
     * Used for rate-limiting and duplicate-submission detection.
     */
    public function getLastMessageBySender(int $bidId, int $senderId): ?array
    {
        $sql = 'SELECT id, body, created_at FROM chat_messages WHERE bid_id = ? AND sender_id = ? ORDER BY id DESC LIMIT 1';
        $q = $this->pdo->prepare($sql);
        $q->execute([$bidId, $senderId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Count messages sent by this user in this conversation within the last $seconds.
     */
    public function getRecentMessageCountBySender(int $bidId, int $senderId, int $seconds = 60): int
    {
        $sql = 'SELECT COUNT(*) FROM chat_messages WHERE bid_id = ? AND sender_id = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL ? SECOND)';
        $q = $this->pdo->prepare($sql);
        $q->bindValue(1, $bidId, PDO::PARAM_INT);
        $q->bindValue(2, $senderId, PDO::PARAM_INT);
        $q->bindValue(3, $seconds, PDO::PARAM_INT);
        $q->execute();
        return (int)$q->fetchColumn();
    }

    /**
     * Fetch the user's read watermark for a conversation.
     */
    public function getWatermark(int $bidId, int $userId): ?array
    {
        $sql = 'SELECT bid_id, user_id, last_read_message_id, read_at FROM chat_read_watermarks WHERE bid_id = ? AND user_id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$bidId, $userId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Update the user's read watermark monotonically.
     */
    public function updateWatermark(int $bidId, int $userId, int $lastReadMessageId): void
    {
        $sql = 'INSERT INTO chat_read_watermarks (bid_id, user_id, last_read_message_id, read_at)
                VALUES (?, ?, ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE
                    last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
                    read_at = UTC_TIMESTAMP()';
        $q = $this->pdo->prepare($sql);
        $q->execute([$bidId, $userId, $lastReadMessageId]);
    }

    /**
     * Count unread messages in a conversation for a specific user.
     * Excludes messages sent by the user themselves.
     */
    public function getUnreadCount(int $bidId, int $userId): int
    {
        $sql = 'SELECT COUNT(*)
                FROM chat_messages cm
                LEFT JOIN chat_read_watermarks w ON w.bid_id = cm.bid_id AND w.user_id = ?
                WHERE cm.bid_id = ?
                  AND cm.sender_id != ?
                  AND cm.id > COALESCE(w.last_read_message_id, 0)';
        $q = $this->pdo->prepare($sql);
        $q->execute([$userId, $bidId, $userId]);
        return (int)$q->fetchColumn();
    }

    /**
     * Count total unread messages across all active conversations for a user.
     * Excludes messages sent by the user themselves.
     */
    public function getTotalUnreadCount(int $userId, string $role): int
    {
        if ($role === 'client') {
            $sql = 'SELECT COUNT(*)
                    FROM chat_messages cm
                    JOIN bids b ON b.id = cm.bid_id
                    JOIN jobs j ON j.id = b.job_id
                    LEFT JOIN chat_read_watermarks w ON w.bid_id = cm.bid_id AND w.user_id = ?
                    WHERE j.client_id = ?
                      AND cm.sender_id != ?
                      AND cm.id > COALESCE(w.last_read_message_id, 0)';
            $q = $this->pdo->prepare($sql);
            $q->execute([$userId, $userId, $userId]);
            return (int)$q->fetchColumn();
        }

        if ($role === 'freelancer') {
            $sql = 'SELECT COUNT(*)
                    FROM chat_messages cm
                    JOIN bids b ON b.id = cm.bid_id
                    LEFT JOIN chat_read_watermarks w ON w.bid_id = cm.bid_id AND w.user_id = ?
                    WHERE b.freelancer_id = ?
                      AND cm.sender_id != ?
                      AND cm.id > COALESCE(w.last_read_message_id, 0)';
            $q = $this->pdo->prepare($sql);
            $q->execute([$userId, $userId, $userId]);
            return (int)$q->fetchColumn();
        }

        return 0;
    }

    /**
     * Fetch all active conversations for the inbox.
     * Only conversations with at least 1 message are returned.
     */
    public function getInboxConversations(int $userId, string $role): array
    {
        if ($role === 'client') {
            $sql = 'SELECT 
                        b.id AS bid_id,
                        b.job_id,
                        j.title AS job_title,
                        j.status AS job_status,
                        j.awarded_bid_id,
                        b.status AS bid_status,
                        uf.id AS counterpart_id,
                        uf.display_name AS counterpart_name,
                        \'freelancer\' AS counterpart_role,
                        latest.id AS latest_message_id,
                        latest.body AS latest_message_body,
                        latest.created_at AS latest_message_time,
                        latest.sender_id AS latest_sender_id,
                        (
                            SELECT COUNT(*)
                            FROM chat_messages cm
                            LEFT JOIN chat_read_watermarks w ON w.bid_id = cm.bid_id AND w.user_id = ?
                            WHERE cm.bid_id = b.id
                              AND cm.sender_id != ?
                              AND cm.id > COALESCE(w.last_read_message_id, 0)
                        ) AS unread_count
                    FROM bids b
                    JOIN jobs j ON j.id = b.job_id
                    JOIN users uf ON uf.id = b.freelancer_id
                    JOIN (
                        SELECT bid_id, MAX(id) AS max_id
                        FROM chat_messages
                        GROUP BY bid_id
                    ) m ON m.bid_id = b.id
                    JOIN chat_messages latest ON latest.id = m.max_id
                    WHERE j.client_id = ?
                    ORDER BY latest.id DESC';
            $q = $this->pdo->prepare($sql);
            $q->execute([$userId, $userId, $userId]);
            return $q->fetchAll(PDO::FETCH_ASSOC);
        }

        // Freelancer role
        $sql = 'SELECT 
                    b.id AS bid_id,
                    b.job_id,
                    j.title AS job_title,
                    j.status AS job_status,
                    j.awarded_bid_id,
                    b.status AS bid_status,
                    uc.id AS counterpart_id,
                    uc.display_name AS counterpart_name,
                    \'client\' AS counterpart_role,
                    latest.id AS latest_message_id,
                    latest.body AS latest_message_body,
                    latest.created_at AS latest_message_time,
                    latest.sender_id AS latest_sender_id,
                    (
                        SELECT COUNT(*)
                        FROM chat_messages cm
                        LEFT JOIN chat_read_watermarks w ON w.bid_id = cm.bid_id AND w.user_id = ?
                        WHERE cm.bid_id = b.id
                          AND cm.sender_id != ?
                          AND cm.id > COALESCE(w.last_read_message_id, 0)
                    ) AS unread_count
                FROM bids b
                JOIN jobs j ON j.id = b.job_id
                JOIN users uc ON uc.id = j.client_id
                JOIN (
                    SELECT bid_id, MAX(id) AS max_id
                    FROM chat_messages
                    GROUP BY bid_id
                ) m ON m.bid_id = b.id
                JOIN chat_messages latest ON latest.id = m.max_id
                WHERE b.freelancer_id = ?
                ORDER BY latest.id DESC';
        $q = $this->pdo->prepare($sql);
        $q->execute([$userId, $userId, $userId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get maximum message id in a bid conversation.
     */
    public function getMaxMessageId(int $bidId): int
    {
        $sql = 'SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE bid_id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$bidId]);
        return (int)$q->fetchColumn();
    }

    /**
     * Check if a message belongs to a specific bid.
     */
    public function messageBelongsToBid(int $messageId, int $bidId): bool
    {
        $sql = 'SELECT COUNT(*) FROM chat_messages WHERE id = ? AND bid_id = ?';
        $q = $this->pdo->prepare($sql);
        $q->execute([$messageId, $bidId]);
        return (int)$q->fetchColumn() > 0;
    }
}
