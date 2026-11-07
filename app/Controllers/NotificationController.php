<?php
declare(strict_types=1);

namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Services\NotificationService;
use Throwable;

final class NotificationController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly Closure $factory
    ) {}

    /**
     * Display the Notification Center page with tabs for All and Unread.
     */
    public function index(array $query = []): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $userId = (int)$user['id'];

        $filter = ($query['filter'] ?? '') === 'unread' ? 'unread' : 'all';
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            /** @var \GigHub\Repositories\NotificationRepository $repo */
            [$repo, $service] = ($this->factory)();

            $rawNotifications = $repo->getForUser($userId, $filter, $limit, $offset);
            $totalCount = $repo->countTotal($userId, $filter);
            $unreadCount = $repo->countUnread($userId);
            $allCount = $filter === 'unread' ? $repo->countTotal($userId, 'all') : $totalCount;
            $totalPages = max(1, (int)ceil($totalCount / $limit));
            $hasNext = ($offset + count($rawNotifications)) < $totalCount;

            $notifications = [];
            foreach ($rawNotifications as $n) {
                $n['is_unread'] = $n['read_at'] === null;
                $n['relative_time'] = NotificationService::formatRelativeTime($n['created_at']);
                $n['destination_url'] = NotificationService::getDestinationUrl($n, $user['role']);
                $n['action_label'] = NotificationService::getActionLabel($n['type']);
                $notifications[] = $n;
            }

            return $this->render('index', [
                'notifications' => $notifications,
                'filter' => $filter,
                'page' => $page,
                'totalPages' => $totalPages,
                'hasNext' => $hasNext,
                'totalCount' => $totalCount,
                'unreadCount' => $unreadCount,
                'allCount' => $allCount,
                'navigationUser' => $user,
                'unreadNotificationCount' => $unreadCount,
            ], $user);
        } catch (Throwable $e) {
            error_log('[GigHub] NotificationController error: ' . $e->getMessage());
            return self::text(503, 'Notification Center is temporarily unavailable.');
        }
    }

    /**
     * Mark a single notification as read, with optional redirection to target entity.
     */
    public function markRead(array $input): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $userId = (int)$user['id'];

        if (!Csrf::valid($input['csrf_token'] ?? null, 'notification_action')) {
            Notification::error('Your session token expired. Please try again.');
            return self::redirect('/notifications');
        }

        $id = filter_var($input['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            Notification::error('Invalid notification selected.');
            return self::redirect('/notifications');
        }

        try {
            /** @var \GigHub\Repositories\NotificationRepository $repo */
            [$repo] = ($this->factory)();
            $notif = $repo->findForUser($id, $userId);
            if ($notif === null) {
                Notification::error('Notification not found or access denied.');
                return self::redirect('/notifications');
            }

            $repo->markAsRead($id, $userId);

            if (($input['redirect'] ?? '') === 'target') {
                $dest = NotificationService::getDestinationUrl($notif, $user['role']);
                if ($dest !== null) {
                    return self::rawRedirect($dest);
                }
            }

            $filter = ($input['current_filter'] ?? '') === 'unread' ? '&filter=unread' : '';
            return self::redirect('/notifications' . $filter);
        } catch (Throwable $e) {
            error_log('[GigHub] markRead error: ' . $e->getMessage());
            return self::text(503, 'Could not update notification.');
        }
    }

    /**
     * Mark all unread notifications as read for the authenticated user.
     */
    public function markAllRead(array $input): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $userId = (int)$user['id'];

        if (!Csrf::valid($input['csrf_token'] ?? null, 'notification_action')) {
            Notification::error('Your session token expired. Please try again.');
            return self::redirect('/notifications');
        }

        try {
            /** @var \GigHub\Repositories\NotificationRepository $repo */
            [$repo] = ($this->factory)();
            $count = $repo->markAllAsRead($userId);
            if ($count > 0) {
                Notification::success('All notifications marked as read.');
            } else {
                Notification::info('No unread notifications to mark.');
            }
            return self::redirect('/notifications');
        } catch (Throwable $e) {
            error_log('[GigHub] markAllRead error: ' . $e->getMessage());
            return self::text(503, 'Could not update notifications.');
        }
    }

    /**
     * Lightweight unread count endpoint for background polling.
     */
    public function unreadCount(): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return [
                'status' => 200,
                'type' => 'application/json; charset=UTF-8',
                'body' => json_encode(['unread_count' => 0]),
            ];
        }

        try {
            /** @var \GigHub\Repositories\NotificationRepository $repo */
            [$repo] = ($this->factory)();
            $count = $repo->countUnread((int)$access['user']['id']);
            return [
                'status' => 200,
                'type' => 'application/json; charset=UTF-8',
                'body' => json_encode(['unread_count' => $count]),
            ];
        } catch (Throwable) {
            return [
                'status' => 200,
                'type' => 'application/json; charset=UTF-8',
                'body' => json_encode(['unread_count' => 0]),
            ];
        }
    }

    private function render(string $view, array $data, array $navigationUser, int $status = 200): array
    {
        extract($data, EXTR_SKIP);
        $csrfToken = Csrf::token('notification_action');
        $logoutToken = Csrf::token('logout');
        $escape = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        try {
            require dirname(__DIR__) . '/Views/notifications/' . $view . '.php';
            $body = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    private static function text(int $status, string $body): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $body];
    }

    private static function redirect(string $route): array
    {
        return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=' . $route];
    }

    private static function rawRedirect(string $url): array
    {
        return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => $url];
    }
}
