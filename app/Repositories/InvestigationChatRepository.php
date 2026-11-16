<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;

final class InvestigationChatRepository
{
    public function __construct(public readonly PDO $pdo) {}

    public function context(int $id): array|false
    {
        $q = $this->pdo->prepare('SELECT d.status, j.id AS job_id, j.title AS job_title, j.client_id, b.freelancer_id
            FROM disputes d JOIN jobs j ON j.id=d.job_id
            JOIN bids b ON b.id=j.awarded_bid_id AND b.job_id=j.id WHERE d.id=?');
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function lockContext(int $id, int $jobId): array|false
    {
        // Match dispute resolution: job, awarded bid, then dispute.
        $job = $this->pdo->prepare('SELECT id AS job_id, title AS job_title, client_id, awarded_bid_id FROM jobs WHERE id=? FOR UPDATE');
        $job->execute([$jobId]);
        $context = $job->fetch(PDO::FETCH_ASSOC);
        if ($context === false || $context['awarded_bid_id'] === null) return false;

        $bid = $this->pdo->prepare('SELECT job_id, freelancer_id FROM bids WHERE id=? FOR UPDATE');
        $bid->execute([$context['awarded_bid_id']]);
        $award = $bid->fetch(PDO::FETCH_ASSOC);
        if ($award === false || (int)$award['job_id'] !== $jobId) return false;

        $dispute = $this->pdo->prepare('SELECT job_id, status FROM disputes WHERE id=? FOR UPDATE');
        $dispute->execute([$id]);
        $current = $dispute->fetch(PDO::FETCH_ASSOC);
        if ($current === false || (int)$current['job_id'] !== $jobId) return false;

        return $context + ['freelancer_id'=>$award['freelancer_id'], 'status'=>$current['status']];
    }

    public function initiated(int $id, int $participant): bool
    {
        // Only an Admin can write a message with a sender other than the participant.
        $q = $this->pdo->prepare('SELECT 1 FROM dispute_investigation_messages
            WHERE dispute_id=? AND participant_id=? AND sender_id<>? LIMIT 1');
        $q->execute([$id,$participant,$participant]);
        return $q->fetchColumn() !== false;
    }

    public function inbox(array $user): array
    {
        $q = $this->pdo->prepare("SELECT d.id AS dispute_id,m.participant_id,j.title AS job_title,d.status,
            MAX(m.created_at) AS latest_message_time,
            SUM(CASE WHEN m.sender_id<>? AND m.id>COALESCE(w.last_read_message_id,0) THEN 1 ELSE 0 END) AS unread_count,
            CASE WHEN m.participant_id=j.client_id THEN 'client' ELSE 'freelancer' END AS participant_role
            FROM dispute_investigation_messages m
            JOIN disputes d ON d.id=m.dispute_id JOIN jobs j ON j.id=d.job_id
            JOIN bids b ON b.id=j.awarded_bid_id AND b.job_id=j.id
            LEFT JOIN dispute_investigation_watermarks w ON w.dispute_id=d.id AND w.participant_id=m.participant_id AND w.user_id=?
            WHERE m.participant_id IN (j.client_id,b.freelancer_id)
            AND (?='admin' OR (m.participant_id=? AND ((?='client' AND m.participant_id=j.client_id)
                OR (?='freelancer' AND m.participant_id=b.freelancer_id))))
            GROUP BY d.id,m.participant_id,j.title,d.status,j.client_id
            HAVING SUM(m.sender_id<>m.participant_id)>0
            ORDER BY latest_message_time DESC,d.id DESC,m.participant_id");
        $id=(int)$user['id'];
        $role=$user['role'];
        $q->execute([$id,$id,$role,$id,$role,$role]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function messages(int $id, int $participant, ?int $since): array
    {
        $sql = 'SELECT m.id, m.sender_id, u.display_name AS sender_name, m.body, m.created_at
            FROM dispute_investigation_messages m JOIN users u ON u.id=m.sender_id
            WHERE m.dispute_id=? AND m.participant_id=?';
        $q = $this->pdo->prepare($sql . ($since === null ? ' ORDER BY m.id DESC LIMIT 100' : ' AND m.id>? ORDER BY m.id LIMIT 50'));
        $q->execute($since === null ? [$id,$participant] : [$id,$participant,$since]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        return $since === null ? array_reverse($rows) : $rows;
    }

    public function attachments(int $message): array
    {
        $q = $this->pdo->prepare('SELECT id, attachment_name AS name, attachment_image AS image FROM dispute_investigation_attachments WHERE message_id=? ORDER BY id');
        $q->execute([$message]);
        return array_map(static fn(array $row) => ['id'=>(int)$row['id'],'name'=>$row['name'],'image'=>(bool)$row['image']], $q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function attachment(int $id, int $participant, int $message, int $attachment): array|false
    {
        $q = $this->pdo->prepare('SELECT a.attachment_key, a.attachment_name, a.attachment_image
            FROM dispute_investigation_attachments a JOIN dispute_investigation_messages m ON m.id=a.message_id
            WHERE m.dispute_id=? AND m.participant_id=? AND m.id=? AND a.id=?');
        $q->execute([$id,$participant,$message,$attachment]);
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    public function read(int $id, int $participant, int $viewer, int $message): bool
    {
        $q = $this->pdo->prepare('SELECT id FROM dispute_investigation_messages WHERE dispute_id=? AND participant_id=? AND id=?');
        $q->execute([$id,$participant,$message]);
        if ($q->fetchColumn() === false) return false;
        $q = $this->pdo->prepare('INSERT INTO dispute_investigation_watermarks (dispute_id,participant_id,user_id,last_read_message_id)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_read_message_id=GREATEST(last_read_message_id,VALUES(last_read_message_id))');
        $q->execute([$id,$participant,$viewer,$message]);
        return true;
    }

    public function unread(int $id, int $participant, int $viewer): int
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM dispute_investigation_messages m
            LEFT JOIN dispute_investigation_watermarks w ON w.dispute_id=m.dispute_id AND w.participant_id=m.participant_id AND w.user_id=?
            WHERE m.dispute_id=? AND m.participant_id=? AND m.sender_id<>? AND m.id>COALESCE(w.last_read_message_id,0)');
        $q->execute([$viewer,$id,$participant,$viewer]);
        return (int)$q->fetchColumn();
    }

    public function save(int $id, int $participant, array $user, string $key, string $body, callable $store): array
    {
        $q = $this->pdo->prepare('SELECT id, sender_id, body, created_at FROM dispute_investigation_messages
            WHERE dispute_id=? AND participant_id=? AND sender_id=? AND request_key=?');
        $scope = [$id,$participant,(int)$user['id'],$key];
        $q->execute($scope);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $recipient = $participant;
            if ($user['role'] !== 'admin') {
                $admin = $this->pdo->prepare("SELECT m.sender_id FROM dispute_investigation_messages m
                    JOIN users u ON u.id=m.sender_id
                    WHERE m.dispute_id=? AND m.participant_id=? AND u.role='admin' AND u.status='active'
                    ORDER BY m.id DESC LIMIT 1");
                $admin->execute([$id,$participant]);
                $recipient = $admin->fetchColumn();
                if ($recipient === false) throw new \DomainException('Wait for an administrator to contact you in this conversation.',409);
            }
            $insert = $this->pdo->prepare('INSERT INTO dispute_investigation_messages
                (dispute_id, participant_id, sender_id, request_key, body) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([...$scope,$body]);
            $message = (int)$this->pdo->lastInsertId();
            $attach = $this->pdo->prepare('INSERT INTO dispute_investigation_attachments
                (message_id,attachment_key,attachment_name,attachment_image) VALUES (?,?,?,?)');
            foreach ($store() as $file) {
                $attach->execute([$message,$file['key'],$file['name'],(int)$file['image']]);
            }
            if ((int)$recipient !== (int)$user['id']) {
                $notify = $this->pdo->prepare("INSERT INTO notifications
                    (recipient_id,type,message,investigation_dispute_id,investigation_participant_id,created_at)
                    VALUES (?,'investigation_message','You have a new private investigation message.',?,?,UTC_TIMESTAMP())");
                $notify->execute([$recipient,$id,$participant]);
            }
            $q->execute($scope);
            $row = $q->fetch(PDO::FETCH_ASSOC);
        }
        return $row + ['sender_name' => $user['display_name'] ?? 'You'];
    }
}
