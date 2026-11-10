<?php
declare(strict_types=1);

namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Services\MessageService;
use Throwable;

final class MessageController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly Closure $factory
    ) {}

    /**
     * Display the Messages Inbox with all active conversations for the user.
     */
    public function inbox(): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $userId = (int)$user['id'];
        $role = (string)$user['role'];

        if (!in_array($role, ['client', 'freelancer'], true)) {
            Notification::error('Messaging is only accessible to Clients and Freelancers.');
            return self::redirect('/');
        }

        try {
            /** @var \GigHub\Repositories\MessageRepository $repo */
            [$repo] = ($this->factory)();

            $rawConversations = $repo->getInboxConversations($userId, $role);
            $conversations = [];

            foreach ($rawConversations as $conv) {
                $statusInfo = MessageService::getConversationStatus($conv);
                $conv['status_label'] = $statusInfo['status_label'];
                $conv['is_writable'] = $statusInfo['is_writable'];
                $conv['relative_time'] = MessageService::formatRelativeTime($conv['latest_message_time']);
                $conv['malaysia_time'] = MessageService::formatMalaysiaTime($conv['latest_message_time']);
                $conv['thread_url'] = '?route=/messages/thread&bid_id=' . (int)$conv['bid_id'];
                $conv['unread_count'] = (int)($conv['unread_count'] ?? 0);
                $conversations[] = $conv;
            }

            return $this->render('index', [
                'conversations' => $conversations,
                'userRole' => $role,
            ], $user);
        } catch (Throwable $e) {
            error_log('[GigHub] MessageController::inbox error: ' . $e->getMessage());
            return self::text(503, 'Could not load messages inbox.');
        }
    }

    /**
     * Display a single Conversation Thread.
     */
    public function thread(array $query = []): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $userId = (int)$user['id'];
        $role = (string)$user['role'];

        $bidId = isset($query['bid_id']) ? (int)$query['bid_id'] : 0;
        if ($bidId <= 0) {
            Notification::error('Invalid conversation.');
            return self::redirect('/messages');
        }

        try {
            /** @var \GigHub\Repositories\MessageRepository $repo */
            [$repo] = ($this->factory)();

            $context = $repo->getConversationContext($bidId);
            if ($context === null) {
                Notification::error('Conversation not found.');
                return self::redirect('/messages');
            }

            if (!MessageService::canAccessConversation($context, $userId, $role)) {
                Notification::error('You do not have permission to access this conversation.');
                return self::redirect('/messages');
            }

            $rawMessages = $repo->getMessages($bidId, 100);
            $statusInfo = MessageService::getConversationStatus($context);

            $messages = [];
            $maxMessageId = 0;
            foreach ($rawMessages as $m) {
                $mId = (int)$m['id'];
                $maxMessageId = max($maxMessageId, $mId);
                $m['formatted_time'] = MessageService::formatMalaysiaTime($m['created_at']);
                $m['is_me'] = (int)$m['sender_id'] === $userId;
                $messages[] = $m;
            }

            // Note: GET requests are strictly read-only. Read watermarks are updated
            // exclusively via CSRF-protected POST ?route=/messages/read triggered by client-side JS.

            // Determine counterpart name and role
            $counterpartName = $role === 'client' ? $context['freelancer_name'] : $context['client_name'];
            $counterpartRole = $role === 'client' ? 'Freelancer' : 'Client';

            // Determine back URL
            $backUrl = '?route=/messages';
            if ($role === 'client') {
                $backUrl = '?route=/client/jobs/bids&id=' . (int)$context['job_id'];
            } elseif ($role === 'freelancer') {
                $backUrl = '?route=/freelancer/bids';
            }

            return $this->render('thread', [
                'context' => $context,
                'messages' => $messages,
                'statusInfo' => $statusInfo,
                'counterpartName' => $counterpartName,
                'counterpartRole' => $counterpartRole,
                'backUrl' => $backUrl,
                'lastMessageId' => $maxMessageId,
                'userId' => $userId,
            ], $user);
        } catch (Throwable $e) {
            error_log('[GigHub] MessageController::thread error: ' . $e->getMessage());
            return self::text(503, 'Could not load conversation thread.');
        }
    }

    /**
     * Send a new message in a conversation.
     */
    public function send(array $post = []): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $userId = (int)$user['id'];
        $role = (string)$user['role'];
        $isAjax = self::isAjax();

        $bidId = isset($post['bid_id']) ? (int)$post['bid_id'] : 0;
        if ($bidId <= 0) {
            return $isAjax ? self::json(400, ['error' => 'Invalid conversation.']) : self::redirect('/messages');
        }

        // Validate CSRF token
        if (!Csrf::valid((string)($post['csrf_token'] ?? ''), 'message_action')) {
            if ($isAjax) {
                return self::json(403, ['error' => 'Invalid or expired session token. Please refresh the page.']);
            }
            Notification::error('Invalid or expired security token. Please try again.');
            return self::redirect('/messages/thread&bid_id=' . $bidId);
        }

        try {
            /** @var \GigHub\Repositories\MessageRepository $repo */
            [$repo] = ($this->factory)();

            $context = $repo->getConversationContext($bidId);
            if ($context === null) {
                if ($isAjax) {
                    return self::json(404, ['error' => 'Conversation not found.']);
                }
                Notification::error('Conversation not found.');
                return self::redirect('/messages');
            }

            if (!MessageService::canAccessConversation($context, $userId, $role)) {
                if ($isAjax) {
                    return self::json(403, ['error' => 'You do not have permission to post in this conversation.']);
                }
                Notification::error('You do not have permission to post in this conversation.');
                return self::redirect('/messages');
            }

            // Check if conversation is writable
            $statusInfo = MessageService::getConversationStatus($context);
            if (!$statusInfo['is_writable']) {
                $reason = $statusInfo['reason'] ?? 'This conversation is currently read-only.';
                if ($isAjax) {
                    return self::json(403, ['error' => $reason]);
                }
                Notification::error($reason);
                return self::redirect('/messages/thread&bid_id=' . $bidId);
            }

            // Validate message content
            $validation = MessageService::validateBody((string)($post['body'] ?? ''));
            if (!$validation['valid']) {
                if ($isAjax) {
                    return self::json(400, ['error' => $validation['error']]);
                }
                Notification::error($validation['error']);
                return self::redirect('/messages/thread&bid_id=' . $bidId);
            }

            // Check rate limiting and anti-spam
            $rateLimitError = MessageService::checkRateLimit($bidId, $userId, $validation['body'], $repo);
            if ($rateLimitError !== null) {
                if ($isAjax) {
                    return self::json(429, ['error' => $rateLimitError]);
                }
                Notification::error($rateLimitError);
                return self::redirect('/messages/thread&bid_id=' . $bidId);
            }

            // Insert message
            $newId = $repo->createMessage($bidId, $userId, $validation['body']);

            // Update sender's read watermark immediately to include their own message
            $repo->updateWatermark($bidId, $userId, $newId);

            if ($isAjax) {
                $nowUtc = gmdate('Y-m-d H:i:s');
                return self::json(201, [
                    'success' => true,
                    'message' => [
                        'id' => $newId,
                        'bid_id' => $bidId,
                        'sender_id' => $userId,
                        'sender_name' => $user['display_name'],
                        'body' => $validation['body'],
                        'created_at' => $nowUtc,
                        'formatted_time' => MessageService::formatMalaysiaTime($nowUtc),
                        'is_me' => true,
                    ],
                ]);
            }

            return self::redirect('/messages/thread&bid_id=' . $bidId);
        } catch (Throwable $e) {
            error_log('[GigHub] MessageController::send error: ' . $e->getMessage());
            if ($isAjax) {
                return self::json(503, ['error' => 'Could not send message. Please try again.']);
            }
            Notification::error('Could not send message. Please try again.');
            return self::redirect('/messages/thread&bid_id=' . $bidId);
        }
    }

    /**
     * Poll for new messages posted after a given ID.
     */
    public function poll(array $query = []): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return self::json(401, ['error' => 'Unauthorized']);
        }

        $user = $access['user'];
        $userId = (int)$user['id'];
        $role = (string)$user['role'];

        $bidId = isset($query['bid_id']) ? (int)$query['bid_id'] : 0;
        $sinceId = max(0, (int)($query['since_id'] ?? 0));

        if ($bidId <= 0) {
            return self::json(400, ['error' => 'Invalid conversation ID.']);
        }

        try {
            /** @var \GigHub\Repositories\MessageRepository $repo */
            [$repo] = ($this->factory)();

            $context = $repo->getConversationContext($bidId);
            if ($context === null) {
                return self::json(404, ['error' => 'Conversation not found.']);
            }

            if (!MessageService::canAccessConversation($context, $userId, $role)) {
                return self::json(403, ['error' => 'Forbidden']);
            }

            $newMessages = $repo->getMessagesSince($bidId, $sinceId, 50);
            $formatted = [];
            $maxId = $sinceId;

            foreach ($newMessages as $m) {
                $mId = (int)$m['id'];
                $maxId = max($maxId, $mId);
                $formatted[] = [
                    'id' => $mId,
                    'bid_id' => $bidId,
                    'sender_id' => (int)$m['sender_id'],
                    'sender_name' => (string)$m['sender_name'],
                    'body' => (string)$m['body'],
                    'created_at' => (string)$m['created_at'],
                    'formatted_time' => MessageService::formatMalaysiaTime((string)$m['created_at']),
                    'is_me' => (int)$m['sender_id'] === $userId,
                ];
            }

            return self::json(200, [
                'messages' => $formatted,
                'last_id' => $maxId,
            ]);
        } catch (Throwable $e) {
            error_log('[GigHub] MessageController::poll error: ' . $e->getMessage());
            return self::json(503, ['error' => 'Polling failed.']);
        }
    }

    /**
     * Acknowledge reading messages up to $messageId.
     */
    public function read(array $post = []): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return self::json(401, ['error' => 'Unauthorized']);
        }

        $user = $access['user'];
        $userId = (int)$user['id'];
        $role = (string)$user['role'];

        if (!Csrf::valid((string)($post['csrf_token'] ?? ''), 'message_action')) {
            return self::json(403, ['error' => 'Invalid CSRF token.']);
        }

        $bidId = isset($post['bid_id']) ? (int)$post['bid_id'] : 0;
        $messageId = isset($post['message_id']) ? (int)$post['message_id'] : 0;

        if ($bidId <= 0 || $messageId <= 0) {
            return self::json(400, ['error' => 'Invalid parameters.']);
        }

        try {
            /** @var \GigHub\Repositories\MessageRepository $repo */
            [$repo] = ($this->factory)();

            $context = $repo->getConversationContext($bidId);
            if ($context === null) {
                return self::json(404, ['error' => 'Conversation not found.']);
            }

            if (!MessageService::canAccessConversation($context, $userId, $role)) {
                return self::json(403, ['error' => 'Forbidden']);
            }

            // Validate that the acknowledged message belongs to this bid and does not exceed maximum existing ID
            $maxId = $repo->getMaxMessageId($bidId);
            if ($messageId > $maxId || !$repo->messageBelongsToBid($messageId, $bidId)) {
                return self::json(400, ['error' => 'Invalid message ID for this conversation.']);
            }

            // Monotonically advance watermark
            $repo->updateWatermark($bidId, $userId, $messageId);

            return self::json(200, [
                'success' => true,
                'last_read_message_id' => $messageId,
            ]);
        } catch (Throwable $e) {
            error_log('[GigHub] MessageController::read error: ' . $e->getMessage());
            return self::json(503, ['error' => 'Could not update watermark.']);
        }
    }

    /**
     * Endpoint for polling total unread messages count across all conversations.
     * GET ?route=/messages/count
     */
    public function unreadCount(): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return self::json(200, ['count' => 0]);
        }

        $user = $access['user'];
        $userId = (int)$user['id'];
        $role = (string)$user['role'];

        try {
            /** @var \GigHub\Repositories\MessageRepository $repo */
            [$repo] = ($this->factory)();

            $count = $repo->getTotalUnreadCount($userId, $role);

            return self::json(200, [
                'count' => $count,
            ]);
        } catch (Throwable $e) {
            error_log('[GigHub] MessageController::unreadCount error: ' . $e->getMessage());
            return self::json(503, ['error' => 'Could not fetch unread count.', 'count' => 0]);
        }
    }

    private function render(string $view, array $data, array $navigationUser, int $status = 200): array
    {
        extract($data, EXTR_SKIP);
        $csrfToken = Csrf::token('message_action');
        $logoutToken = Csrf::token('logout');
        $escape = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        try {
            require dirname(__DIR__) . '/Views/messages/' . $view . '.php';
            $body = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    private static function isAjax(): bool
    {
        return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'));
    }

    private static function json(int $status, array $data): array
    {
        return [
            'status' => $status,
            'type' => 'application/json; charset=UTF-8',
            'body' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    private static function text(int $status, string $body): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $body];
    }

    private static function redirect(string $route): array
    {
        return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=' . $route];
    }
}
