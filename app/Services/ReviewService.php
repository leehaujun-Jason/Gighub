<?php
declare(strict_types=1);

namespace GigHub\Services;

use GigHub\Repositories\ReviewRepository;
use PDO;
use PDOException;
use Throwable;

class ReviewService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ReviewRepository $repo
    ) {}

    public function getReviewContext(int $jobId, int $authUserId, string $authRole): array
    {
        $job = $this->repo->getJobContext($jobId, false);
        if ($job === false) {
            throw new ReviewException(404, 'Job not found.');
        }

        if (($job['job_status'] ?? '') !== 'completed') {
            throw new ReviewException(409, 'Reviews can only be submitted for completed jobs.');
        }

        if (empty($job['awarded_bid_id']) || ($job['bid_status'] ?? '') !== 'accepted') {
            throw new ReviewException(409, 'Job does not have an accepted awarded bid.');
        }

        $clientId = (int)$job['client_id'];
        $freelancerId = (int)$job['freelancer_id'];

        if ($authRole === 'client' && $authUserId === $clientId) {
            $isClient = true;
            $reviewerId = $clientId;
            $revieweeId = $freelancerId;
            $counterpartName = $job['freelancer_name'] ?? 'Freelancer';
        } elseif ($authRole === 'freelancer' && $authUserId === $freelancerId) {
            $isClient = false;
            $reviewerId = $freelancerId;
            $revieweeId = $clientId;
            $counterpartName = $job['client_name'] ?? 'Client';
        } else {
            throw new ReviewException(403, 'You are not authorized to review this job.');
        }

        $existingReview = $this->repo->getReview($jobId, $reviewerId, false);

        return [
            'job' => $job,
            'reviewer_id' => $reviewerId,
            'reviewee_id' => $revieweeId,
            'counterpart_name' => $counterpartName,
            'is_client' => $isClient,
            'existing_review' => $existingReview ?: null,
            'has_reviewed' => ($existingReview !== false),
        ];
    }

    public function submitReview(int $jobId, int $authUserId, string $authRole, array $input): array
    {
        if ($this->pdo->inTransaction()) {
            throw new ReviewException(500, 'Database transaction already active.');
        }

        $this->pdo->beginTransaction();

        try {
            $job = $this->repo->getJobContext($jobId, true);
            if ($job === false) {
                $this->pdo->rollBack();
                throw new ReviewException(404, 'Job not found.');
            }

            if (($job['job_status'] ?? '') !== 'completed') {
                $this->pdo->rollBack();
                throw new ReviewException(409, 'Reviews can only be submitted for completed jobs.');
            }

            if (empty($job['awarded_bid_id']) || ($job['bid_status'] ?? '') !== 'accepted') {
                $this->pdo->rollBack();
                throw new ReviewException(409, 'Job does not have an accepted awarded bid.');
            }

            $clientId = (int)$job['client_id'];
            $freelancerId = (int)$job['freelancer_id'];

            if ($authRole === 'client' && $authUserId === $clientId) {
                if (($job['client_status'] ?? '') !== 'active' || empty($job['client_verified_at'])) {
                    $this->pdo->rollBack();
                    throw new ReviewException(403, 'Account is not eligible to submit reviews.');
                }
                $reviewerId = $clientId;
                $revieweeId = $freelancerId;
                $counterpartName = $job['freelancer_name'] ?? 'Freelancer';
                $reviewerName = $job['client_name'] ?? 'Client';
            } elseif ($authRole === 'freelancer' && $authUserId === $freelancerId) {
                if (($job['freelancer_status'] ?? '') !== 'active' || empty($job['freelancer_verified_at'])) {
                    $this->pdo->rollBack();
                    throw new ReviewException(403, 'Account is not eligible to submit reviews.');
                }
                $reviewerId = $freelancerId;
                $revieweeId = $clientId;
                $counterpartName = $job['client_name'] ?? 'Client';
                $reviewerName = $job['freelancer_name'] ?? 'Freelancer';
            } else {
                $this->pdo->rollBack();
                throw new ReviewException(403, 'You are not authorized to review this job.');
            }

            $existing = $this->repo->getReview($jobId, $reviewerId, true);
            if ($existing !== false) {
                $this->pdo->rollBack();
                throw new ReviewException(409, 'You have already submitted a review for this project.');
            }

            $validation = ReviewSubmissionValidator::validate($input);
            if (!$validation['ok']) {
                $this->pdo->rollBack();
                throw new ReviewException(422, 'Validation failed.', $validation['errors']);
            }

            $rating = $validation['data']['rating'];
            $comment = $validation['data']['comment'];

            try {
                $reviewId = $this->repo->insertReview($jobId, $reviewerId, $revieweeId, $rating, $comment);
            } catch (PDOException $e) {
                $this->pdo->rollBack();
                $code = (string)$e->getCode();
                $msg = $e->getMessage();
                $driverCode = (int)($e->errorInfo[1] ?? 0);
                if ($code === '23000' || $driverCode === 1062 || str_contains($msg, 'uq_reviews_job_reviewer') || str_contains($msg, 'Duplicate entry')) {
                    throw new ReviewException(409, 'You have already submitted a review for this project.');
                }
                throw new ReviewException(500, 'Database error during review submission.');
            }

            $notifMsg = sprintf(
                'New review received for "%s" from %s (%d/5 stars).',
                $job['job_title'] ?? 'project',
                $reviewerName,
                $rating
            );
            $this->repo->createNotification($revieweeId, $jobId, $notifMsg);

            $this->pdo->commit();

            return [
                'review_id' => $reviewId,
                'job_id' => $jobId,
                'rating' => $rating,
                'counterpart_name' => $counterpartName,
            ];
        } catch (ReviewException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new ReviewException(500, 'An unexpected error occurred while saving the review.');
        }
    }
}
