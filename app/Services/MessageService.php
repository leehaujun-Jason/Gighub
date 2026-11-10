<?php
declare(strict_types=1);

namespace GigHub\Services;

use DateTimeImmutable;
use DateTimeZone;
use GigHub\Repositories\MessageRepository;
use Throwable;

class MessageService
{
    /**
     * Check if a user is an authorized participant in the conversation.
     */
    public static function canAccessConversation(array $context, int $userId, string $role): bool
    {
        if ($role === 'client') {
            return (int)($context['client_id'] ?? 0) === $userId;
        }

        if ($role === 'freelancer') {
            return (int)($context['freelancer_id'] ?? 0) === $userId;
        }

        return false;
    }

    /**
     * Determine whether the conversation is currently writable or read-only,
     * along with user-facing explanation and status label.
     */
    public static function getConversationStatus(array $context): array
    {
        $jobStatus = (string)($context['job_status'] ?? '');
        $bidStatus = (string)($context['bid_status'] ?? '');
        $awardedBidId = isset($context['awarded_bid_id']) ? (int)$context['awarded_bid_id'] : null;
        $bidId = (int)($context['bid_id'] ?? 0);

        // Terminal job states are always read-only
        if ($jobStatus === 'completed') {
            return [
                'is_writable' => false,
                'reason' => 'This job is completed. Conversation history is preserved as read-only.',
                'status_label' => 'Completed',
            ];
        }

        if ($jobStatus === 'cancelled') {
            return [
                'is_writable' => false,
                'reason' => 'This job was cancelled. Conversation history is preserved as read-only.',
                'status_label' => 'Cancelled',
            ];
        }

        if ($jobStatus === 'closed_disputed') {
            return [
                'is_writable' => false,
                'reason' => 'This job was closed following a dispute. Conversation history is preserved as read-only.',
                'status_label' => 'Closed (Disputed)',
            ];
        }

        // Withdrawn or rejected bids are read-only
        if ($bidStatus === 'withdrawn') {
            return [
                'is_writable' => false,
                'reason' => 'This proposal was withdrawn. Conversation history is preserved as read-only.',
                'status_label' => 'Withdrawn',
            ];
        }

        if ($bidStatus === 'rejected') {
            return [
                'is_writable' => false,
                'reason' => 'This proposal was not selected. Conversation history is preserved as read-only.',
                'status_label' => 'Not Selected',
            ];
        }

        // Open job with pending proposal is active/writable
        if ($jobStatus === 'open' && $bidStatus === 'pending') {
            return [
                'is_writable' => true,
                'reason' => null,
                'status_label' => 'Open Proposal',
            ];
        }

        // Awarded / In-progress / Under-review / Disputed: writable only for the winning awarded bid
        if (in_array($jobStatus, ['awarded', 'in_progress', 'under_review', 'disputed'], true)) {
            if ($awardedBidId !== null && $awardedBidId === $bidId) {
                return [
                    'is_writable' => true,
                    'reason' => null,
                    'status_label' => ucwords(str_replace('_', ' ', $jobStatus)),
                ];
            }

            return [
                'is_writable' => false,
                'reason' => 'Another proposal was selected for this job. This conversation is preserved as read-only.',
                'status_label' => 'Proposal Pending',
            ];
        }

        return [
            'is_writable' => false,
            'reason' => 'This conversation is currently read-only.',
            'status_label' => 'Read-Only',
        ];
    }

    /**
     * Validate message text content.
     */
    public static function validateBody(string $rawBody): array
    {
        $body = trim($rawBody);

        if ($body === '') {
            return [
                'valid' => false,
                'body' => '',
                'error' => 'Message cannot be empty.',
            ];
        }

        if (!mb_check_encoding($body, 'UTF-8')) {
            return [
                'valid' => false,
                'body' => '',
                'error' => 'Message contains invalid character encoding.',
            ];
        }

        if (mb_strlen($body, 'UTF-8') > 5000) {
            return [
                'valid' => false,
                'body' => '',
                'error' => 'Message exceeds the maximum limit of 5,000 characters.',
            ];
        }

        return [
            'valid' => true,
            'body' => $body,
            'error' => null,
        ];
    }

    /**
     * Check rate limiting and duplicate submission rules.
     * Enforces:
     * - 2-second cooldown between messages per sender/bid
     * - 60-second duplicate message prevention
     * - 10 messages per minute maximum per sender/bid
     */
    public static function checkRateLimit(int $bidId, int $senderId, string $body, MessageRepository $repo): ?string
    {
        $last = $repo->getLastMessageBySender($bidId, $senderId);
        if ($last !== null) {
            $lastTime = strtotime($last['created_at'] . ' UTC');
            $nowTime = time();
            $diff = $nowTime - $lastTime;

            if ($diff < 2) {
                return 'Please wait a moment before sending another message.';
            }

            if ($diff < 60 && $last['body'] === $body) {
                return 'Duplicate message detected. Please do not submit the same message repeatedly.';
            }
        }

        $recentCount = $repo->getRecentMessageCountBySender($bidId, $senderId, 60);
        if ($recentCount >= 10) {
            return 'You have reached the maximum rate limit of 10 messages per minute. Please try again shortly.';
        }

        return null;
    }

    /**
     * Format a stored UTC timestamp for Malaysia display (Asia/Kuala_Lumpur, UTC+8).
     */
    public static function formatMalaysiaTime(string $utcDatetime): string
    {
        try {
            $utc = new DateTimeZone('UTC');
            $kl = new DateTimeZone('Asia/Kuala_Lumpur');
            $dt = new DateTimeImmutable($utcDatetime, $utc);
            $dtKl = $dt->setTimezone($kl);

            $nowKl = (new DateTimeImmutable('now', $utc))->setTimezone($kl);

            if ($dtKl->format('Y-m-d') === $nowKl->format('Y-m-d')) {
                return 'Today at ' . $dtKl->format('g:i A');
            }

            $yesterday = $nowKl->modify('-1 day');
            if ($dtKl->format('Y-m-d') === $yesterday->format('Y-m-d')) {
                return 'Yesterday at ' . $dtKl->format('g:i A');
            }

            if ($dtKl->format('Y') === $nowKl->format('Y')) {
                return $dtKl->format('d M, g:i A');
            }

            return $dtKl->format('d M Y, g:i A');
        } catch (Throwable) {
            return $utcDatetime;
        }
    }

    /**
     * Format a stored UTC timestamp as relative time for inbox cards.
     */
    public static function formatRelativeTime(string $utcDatetime): string
    {
        try {
            $time = strtotime($utcDatetime . ' UTC');
            if ($time === false) {
                return $utcDatetime;
            }
            $diff = time() - $time;

            if ($diff < 60) {
                return 'Just now';
            }
            if ($diff < 3600) {
                $mins = max(1, (int)floor($diff / 60));
                return $mins . 'm ago';
            }
            if ($diff < 86400) {
                $hours = max(1, (int)floor($diff / 3600));
                return $hours . 'h ago';
            }
            if ($diff < 172800) {
                return 'Yesterday';
            }
            $days = (int)floor($diff / 86400);
            if ($days < 7) {
                return $days . 'd ago';
            }

            $utc = new DateTimeZone('UTC');
            $kl = new DateTimeZone('Asia/Kuala_Lumpur');
            $dt = (new DateTimeImmutable($utcDatetime, $utc))->setTimezone($kl);
            return $dt->format('d M Y');
        } catch (Throwable) {
            return $utcDatetime;
        }
    }
}
