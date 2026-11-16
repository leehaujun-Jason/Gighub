<?php
declare(strict_types=1);

namespace GigHub\Services;

use DateTimeImmutable;
use DateTimeZone;
use GigHub\Repositories\NotificationRepository;
use InvalidArgumentException;

class NotificationService
{
    public const ALLOWED_TYPES = [
        'bid_received',
        'award_selected',
        'award_cancelled',
        'escrow_funded',
        'submission_received',
        'revision_requested',
        'milestone_approved',
        'payment_released',
        'payment_refunded',
        'dispute_opened',
        'dispute_resolved',
        'cancellation_requested',
        'cancellation_responded',
        'job_cancelled',
        'review_received',
        'bid_not_selected',
    ];

    public function __construct(private readonly NotificationRepository $repo) {}

    /**
     * Dispatch a validated notification.
     */
    public function dispatch(int $recipientId, ?int $jobId, string $type, string $message): int
    {
        if ($recipientId <= 0) {
            throw new InvalidArgumentException('Recipient ID must be a positive integer.');
        }

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported notification type: ' . $type);
        }

        $trimmed = trim($message);
        if ($trimmed === '' || mb_strlen($trimmed, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Notification message must be between 1 and 500 characters.');
        }

        return $this->repo->create($recipientId, $jobId, $type, $trimmed);
    }

    /**
     * Generate a verified, server-side destination route for a notification.
     */
    public static function getDestinationUrl(array $notification, string $userRole): ?string
    {
        $type = (string)($notification['type'] ?? '');
        $jobId = isset($notification['job_id']) && $notification['job_id'] !== null ? (int)$notification['job_id'] : null;

        return match ($type) {
            'investigation_message' => !empty($notification['investigation_dispute_id']) && !empty($notification['investigation_participant_id'])
                ? '?route=/investigation-chat/thread&dispute_id=' . (int)$notification['investigation_dispute_id']
                    . '&participant_id=' . (int)$notification['investigation_participant_id'] : null,
            'bid_not_selected' => $userRole === 'freelancer' ? '?route=/freelancer/bids' : null,
            'bid_received' => $jobId !== null && $userRole === 'client'
                ? '?route=/client/jobs/bids&id=' . $jobId
                : ($userRole === 'client' ? '?route=/client/jobs' : '?route=/jobs'),

            'award_selected', 'escrow_funded' => $jobId !== null && $userRole === 'freelancer'
                ? '?route=/freelancer/jobs/milestones&job_id=' . $jobId
                : ($jobId !== null && $userRole === 'client' ? '?route=/client/jobs/milestones&job_id=' . $jobId : null),

            'submission_received' => $jobId !== null && $userRole === 'client'
                ? '?route=/client/jobs/milestones&job_id=' . $jobId
                : ($jobId !== null && $userRole === 'freelancer' ? '?route=/freelancer/jobs/milestones&job_id=' . $jobId : null),

            'revision_requested', 'milestone_approved' => $jobId !== null && $userRole === 'freelancer'
                ? '?route=/freelancer/jobs/milestones&job_id=' . $jobId
                : ($jobId !== null && $userRole === 'client' ? '?route=/client/jobs/milestones&job_id=' . $jobId : null),

            'payment_released', 'payment_refunded' => $userRole === 'freelancer'
                ? '?route=/freelancer/wallet'
                : '?route=/client/wallet',

            'review_received' => $userRole === 'freelancer'
                ? '?route=/freelancer/profile'
                : ($userRole === 'client' ? '?route=/client/jobs' : null),

            'cancellation_requested', 'cancellation_responded', 'job_cancelled' => $jobId !== null && $userRole === 'freelancer'
                ? '?route=/freelancer/jobs/milestones&job_id=' . $jobId
                : ($jobId !== null && $userRole === 'client'
                    ? '?route=/client/jobs/milestones&job_id=' . $jobId
                    : ($userRole === 'freelancer' ? '?route=/freelancer' : '?route=/client/jobs')),

            'dispute_opened', 'dispute_resolved' => $jobId !== null && $userRole === 'freelancer'
                ? '?route=/freelancer/jobs/milestones&job_id=' . $jobId
                : ($jobId !== null && $userRole === 'client'
                    ? '?route=/client/jobs/milestones&job_id=' . $jobId
                    : ($userRole === 'freelancer' ? '?route=/freelancer/wallet' : '?route=/client/wallet')),

            default => null,
        };
    }

    /**
     * Get an intuitive action link label for the notification.
     */
    public static function getActionLabel(string $type): string
    {
        return match ($type) {
            'bid_received' => 'Review Bids',
            'award_selected' => 'View Project',
            'escrow_funded' => 'View Milestones',
            'submission_received' => 'Review Submission',
            'revision_requested' => 'View Revisions',
            'investigation_message' => 'Open Private Conversation',
            'milestone_approved' => 'View Milestones',
            'payment_released', 'payment_refunded' => 'View Wallet',
            'review_received' => 'View Feedback',
            'bid_not_selected' => 'View My Bids',
            'cancellation_requested' => 'Review Request',
            'cancellation_responded', 'job_cancelled' => 'View Contract',
            'dispute_opened' => 'View Dispute',
            'dispute_resolved' => 'View Resolution',
            default => 'View Details',
        };
    }

    /**
     * Compute a humanized relative time from a UTC datetime string.
     */
    public static function formatRelativeTime(string $utcDateTimeString): string
    {
        try {
            $created = new DateTimeImmutable($utcDateTimeString, new DateTimeZone('UTC'));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $diff = $now->getTimestamp() - $created->getTimestamp();

            if ($diff < 60) {
                return 'Just now';
            }
            if ($diff < 3600) {
                $mins = max(1, (int)floor($diff / 60));
                return $mins === 1 ? '1 minute ago' : $mins . ' minutes ago';
            }
            if ($diff < 86400) {
                $hours = max(1, (int)floor($diff / 3600));
                return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
            }
            if ($diff < 172800) {
                return 'Yesterday';
            }
            if ($diff < 604800) {
                $days = max(1, (int)floor($diff / 86400));
                return $days === 1 ? '1 day ago' : $days . ' days ago';
            }

            return $created->format('M j, Y');
        } catch (\Throwable) {
            return $utcDateTimeString;
        }
    }
}
