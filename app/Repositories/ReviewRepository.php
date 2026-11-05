<?php
declare(strict_types=1);

namespace GigHub\Repositories;

use PDO;

class ReviewRepository
{
    public function __construct(protected readonly PDO $pdo) {}

    public function getJobContext(int $jobId, bool $lock = false): array|false
    {
        $sql = 'SELECT
                    j.id AS job_id,
                    j.title AS job_title,
                    j.status AS job_status,
                    j.client_id,
                    cu.display_name AS client_name,
                    cu.status AS client_status,
                    cu.email_verified_at AS client_verified_at,
                    j.awarded_bid_id,
                    b.status AS bid_status,
                    b.freelancer_id,
                    fu.display_name AS freelancer_name,
                    fu.status AS freelancer_status,
                    fu.email_verified_at AS freelancer_verified_at
                FROM jobs j
                JOIN users cu ON cu.id = j.client_id
                LEFT JOIN bids b ON b.id = j.awarded_bid_id
                LEFT JOIN users fu ON fu.id = b.freelancer_id
                WHERE j.id = ?';

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$jobId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getReview(int $jobId, int $reviewerId, bool $lock = false): array|false
    {
        $sql = 'SELECT id, job_id, reviewer_id, reviewee_id, rating, comment, created_at' . "\n" .
               'FROM reviews' . "\n" .
               'WHERE job_id = ? AND reviewer_id = ?';

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$jobId, $reviewerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getReviewsForJob(int $jobId): array
    {
        $sql = 'SELECT
                    r.id,
                    r.job_id,
                    r.reviewer_id,
                    r.reviewee_id,
                    r.rating,
                    r.comment,
                    r.created_at,
                    ru.display_name AS reviewer_name,
                    ru.role AS reviewer_role,
                    ee.display_name AS reviewee_name,
                    ee.role AS reviewee_role
                FROM reviews r
                JOIN users ru ON ru.id = r.reviewer_id
                JOIN users ee ON ee.id = r.reviewee_id
                WHERE r.job_id = ?
                ORDER BY r.created_at ASC, r.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getFreelancerReputation(int $freelancerId): array
    {
        $sql = 'SELECT
                    COUNT(r.id) AS total_count,
                    ROUND(AVG(r.rating), 1) AS average_rating
                FROM reviews r
                JOIN jobs j ON j.id = r.job_id AND j.status = \'completed\' AND j.client_id = r.reviewer_id
                JOIN bids b ON b.id = j.awarded_bid_id AND b.freelancer_id = r.reviewee_id
                WHERE r.reviewee_id = ?';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$freelancerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['count' => 0, 'average' => null];
        }

        $count = (int)($row['total_count'] ?? 0);
        $avg = ($count > 0 && isset($row['average_rating']) && $row['average_rating'] !== null)
            ? (float)$row['average_rating']
            : null;

        return [
            'count' => $count,
            'average' => $avg,
        ];
    }

    public function getFreelancerReviews(int $freelancerId, int $limit = 20): array
    {
        $sql = 'SELECT
                    r.id,
                    r.job_id,
                    r.rating,
                    r.comment,
                    r.created_at,
                    cu.display_name AS client_name,
                    j.title AS job_title
                FROM reviews r
                JOIN jobs j ON j.id = r.job_id AND j.status = \'completed\' AND j.client_id = r.reviewer_id
                JOIN bids b ON b.id = j.awarded_bid_id AND b.freelancer_id = r.reviewee_id
                JOIN users cu ON cu.id = r.reviewer_id
                WHERE r.reviewee_id = ?
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT ?';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $freelancerId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertReview(int $jobId, int $reviewerId, int $revieweeId, int $rating, ?string $comment): int
    {
        $sql = 'INSERT INTO reviews (job_id, reviewer_id, reviewee_id, rating, comment, created_at)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$jobId, $reviewerId, $revieweeId, $rating, $comment]);
        return (int)$this->pdo->lastInsertId();
    }

    public function createNotification(int $recipientId, int $jobId, string $message): void
    {
        $sql = 'INSERT INTO notifications (recipient_id, job_id, type, message) VALUES (?, ?, \'review_received\', ?)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$recipientId, $jobId, $message]);
    }
}
