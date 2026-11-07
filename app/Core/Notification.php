<?php
declare(strict_types=1);
namespace GigHub\Core;

final class Notification
{
    public static function flash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            session_start();
        }
        if (!isset($_SESSION['gh_notifications']) || !is_array($_SESSION['gh_notifications'])) {
            $_SESSION['gh_notifications'] = [];
        }
        $_SESSION['gh_notifications'][] = [
            'type' => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
            'message' => $message,
        ];
    }

    public static function success(string $message): void
    {
        self::flash('success', $message);
    }

    public static function error(string $message): void
    {
        self::flash('error', $message);
    }

    public static function warning(string $message): void
    {
        self::flash('warning', $message);
    }

    public static function info(string $message): void
    {
        self::flash('info', $message);
    }

    public static function consume(): array
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            session_start();
        }

        $notifications = $_SESSION['gh_notifications'] ?? [];
        unset($_SESSION['gh_notifications']);

        // Bridge legacy/ad-hoc session notices so they automatically render through the unified UI without duplication
        $addLegacy = static function(string $type, string $message) use (&$notifications): void {
            foreach ($notifications as $n) {
                if ($n['message'] === $message) {
                    return;
                }
            }
            $notifications[] = ['type' => $type, 'message' => $message];
        };

        if (isset($_SESSION['wallet_notice'])) {
            $addLegacy('success', (string)$_SESSION['wallet_notice']);
            unset($_SESSION['wallet_notice']);
        }
        if (isset($_SESSION['milestone_notice'])) {
            $addLegacy('success', (string)$_SESSION['milestone_notice']);
            unset($_SESSION['milestone_notice']);
        }
        if (isset($_SESSION['job_posting_notice']) && in_array($_SESSION['job_posting_notice'], ['Your job has been saved.', 'Your job has been published.'], true)) {
            $addLegacy('success', (string)$_SESSION['job_posting_notice']);
            unset($_SESSION['job_posting_notice']);
        }
        if (isset($_SESSION['bid_notice'])) {
            $addLegacy('success', (string)$_SESSION['bid_notice']);
            unset($_SESSION['bid_notice']);
        }
        if (isset($_SESSION['escrow_fund_error'])) {
            $addLegacy('error', (string)$_SESSION['escrow_fund_error']);
            unset($_SESSION['escrow_fund_error']);
        }

        return $notifications;
    }
}
