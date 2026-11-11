<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE && PHP_SAPI === 'cli') {
    session_start();
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

// Load Core dependencies
foreach ([
    'Core/Config',
    'Core/Database',
    'Core/Csrf',
    'Core/Notification',
    'Core/AuthSession',
    'Core/AuthGuard',
    'Core/Router',
    'Repositories/UserRepository',
    'Repositories/MessageRepository',
    'Services/MessageService',
    'Controllers/MessageController',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Core\Csrf;
use GigHub\Controllers\MessageController;
use GigHub\Repositories\MessageRepository;
use GigHub\Services\MessageService;

// Test Runner Infrastructure
$testCount = 0;
$failCount = 0;

$assert = function (bool $condition, string $message) use (&$testCount, &$failCount): void {
    $testCount++;
    if ($condition) {
        echo "  [PASS] {$message}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$message}\n";
    }
};

echo "\n=======================================================\n";
echo " GigHub Module 20: Client–Freelancer Messaging Tests\n";
echo "=======================================================\n\n";

// -------------------------------------------------------------------------
// Section 1: Authorization and Access Rules
// -------------------------------------------------------------------------
echo "--- Section 1: Authorization & IDOR Prevention ---\n";

$context = [
    'bid_id' => 10,
    'job_id' => 5,
    'client_id' => 100,
    'freelancer_id' => 200,
    'job_title' => 'Web App UI Refactoring',
    'job_status' => 'open',
    'awarded_bid_id' => null,
    'bid_status' => 'pending',
    'bid_amount' => '3500.00',
    'client_name' => 'Sarah Tan',
    'freelancer_name' => 'Alex Wong',
];

$assert(MessageService::canAccessConversation($context, 100, 'client'), 'Client owner can access conversation');
$assert(!MessageService::canAccessConversation($context, 101, 'client'), 'Unauthorized client is blocked from conversation');
$assert(MessageService::canAccessConversation($context, 200, 'freelancer'), 'Freelancer owner can access conversation');
$assert(!MessageService::canAccessConversation($context, 201, 'freelancer'), 'Unauthorized freelancer is blocked from conversation');
$assert(!MessageService::canAccessConversation($context, 100, 'freelancer'), 'Role mismatch blocked (client ID as freelancer role)');
$assert(!MessageService::canAccessConversation($context, 300, 'admin'), 'Admin role blocked from private participant conversation');

// -------------------------------------------------------------------------
// Section 2: Lifecycle and Writable vs Read-Only Rules
// -------------------------------------------------------------------------
echo "\n--- Section 2: Lifecycle & Writable / Read-Only Rules ---\n";

// Open job + pending bid: writable
$ctxOpen = array_replace($context, ['job_status' => 'open', 'bid_status' => 'pending', 'awarded_bid_id' => null]);
$resOpen = MessageService::getConversationStatus($ctxOpen);
$assert($resOpen['is_writable'] === true && $resOpen['status_label'] === 'Open Proposal', 'Open job with pending bid is writable');

// Awarded winning bid: writable
$ctxAwardedWin = array_replace($context, ['job_status' => 'awarded', 'awarded_bid_id' => 10]);
$resAwardedWin = MessageService::getConversationStatus($ctxAwardedWin);
$assert($resAwardedWin['is_writable'] === true && $resAwardedWin['status_label'] === 'Awarded', 'Awarded winning bid is writable');

// Awarded losing bid: read-only
$ctxAwardedLose = array_replace($context, ['job_status' => 'awarded', 'awarded_bid_id' => 99]);
$resAwardedLose = MessageService::getConversationStatus($ctxAwardedLose);
$assert($resAwardedLose['is_writable'] === false && str_contains($resAwardedLose['reason'], 'Another proposal was selected'), 'Awarded losing bid is read-only');

// In-progress winning bid: writable
$ctxInProg = array_replace($context, ['job_status' => 'in_progress', 'awarded_bid_id' => 10, 'bid_status' => 'accepted']);
$resInProg = MessageService::getConversationStatus($ctxInProg);
$assert($resInProg['is_writable'] === true && $resInProg['status_label'] === 'In Progress', 'In-progress winning bid is writable');

// Completed job: read-only
$ctxCompleted = array_replace($context, ['job_status' => 'completed', 'awarded_bid_id' => 10, 'bid_status' => 'accepted']);
$resCompleted = MessageService::getConversationStatus($ctxCompleted);
$assert($resCompleted['is_writable'] === false && str_contains($resCompleted['reason'], 'completed'), 'Completed job conversation is read-only');

// Cancelled job: read-only
$ctxCancelled = array_replace($context, ['job_status' => 'cancelled']);
$resCancelled = MessageService::getConversationStatus($ctxCancelled);
$assert($resCancelled['is_writable'] === false && str_contains($resCancelled['reason'], 'cancelled'), 'Cancelled job conversation is read-only');

// Closed-disputed job: read-only
$ctxClosedDisp = array_replace($context, ['job_status' => 'closed_disputed']);
$resClosedDisp = MessageService::getConversationStatus($ctxClosedDisp);
$assert($resClosedDisp['is_writable'] === false && str_contains($resClosedDisp['reason'], 'dispute'), 'Closed disputed job is read-only');

// Withdrawn bid: read-only
$ctxWithdrawn = array_replace($context, ['job_status' => 'open', 'bid_status' => 'withdrawn']);
$resWithdrawn = MessageService::getConversationStatus($ctxWithdrawn);
$assert($resWithdrawn['is_writable'] === false && str_contains($resWithdrawn['reason'], 'withdrawn'), 'Withdrawn bid is read-only');

// Rejected bid: read-only
$ctxRejected = array_replace($context, ['job_status' => 'in_progress', 'bid_status' => 'rejected', 'awarded_bid_id' => 99]);
$resRejected = MessageService::getConversationStatus($ctxRejected);
$assert($resRejected['is_writable'] === false && str_contains($resRejected['reason'], 'not selected'), 'Rejected bid is read-only');

// -------------------------------------------------------------------------
// Section 3: Input Validation and Unicode Limits
// -------------------------------------------------------------------------
echo "\n--- Section 3: Input Validation & Unicode Character Bounds ---\n";

$vEmpty = MessageService::validateBody('');
$assert(!$vEmpty['valid'] && $vEmpty['error'] === 'Message cannot be empty.', 'Empty message rejected');

$vWhitespace = MessageService::validateBody("   \t\n  \r\n ");
$assert(!$vWhitespace['valid'] && $vWhitespace['error'] === 'Message cannot be empty.', 'Whitespace-only message rejected');

$vValid = MessageService::validateBody('  Hello world! This is a test proposal discussion.  ');
$assert($vValid['valid'] && $vValid['body'] === 'Hello world! This is a test proposal discussion.', 'Valid message accepted and trimmed');

$unicodeText = "Halo! Projek ini sangat bagus. 🚀 Ready to collaborate!";
$vUnicode = MessageService::validateBody($unicodeText);
$assert($vUnicode['valid'] && $vUnicode['body'] === $unicodeText, 'Unicode and emoji text accepted');

$longText5000 = str_repeat('A', 5000);
$v5000 = MessageService::validateBody($longText5000);
$assert($v5000['valid'] && mb_strlen($v5000['body'], 'UTF-8') === 5000, '5,000 character boundary accepted');

$longText5001 = str_repeat('A', 5001);
$v5001 = MessageService::validateBody($longText5001);
$assert(!$v5001['valid'] && str_contains($v5001['error'], 'exceeds the maximum limit of 5,000 characters'), '5,001 character message rejected');

// -------------------------------------------------------------------------
// Section 4: Rate Limiting & Anti-Spam Detection
// -------------------------------------------------------------------------
echo "\n--- Section 4: Rate Limiting & Anti-Spam ---\n";

// Mock Repository Double for Rate Limiting and Storage Tests
class MockMessageRepository extends MessageRepository
{
    public array $messages = [];
    public array $watermarks = [];
    public array $contexts = [];

    public function __construct() {}

    public function getConversationContext(int $bidId): ?array
    {
        return $this->contexts[$bidId] ?? null;
    }

    public function getMessages(int $bidId, int $limit = 100): array
    {
        $filtered = array_filter($this->messages, fn($m) => $m['bid_id'] === $bidId);
        usort($filtered, fn($a, $b) => $a['id'] <=> $b['id']);
        return array_slice($filtered, 0, $limit);
    }

    public function getMessagesSince(int $bidId, int $sinceId, int $limit = 50): array
    {
        $filtered = array_filter($this->messages, fn($m) => $m['bid_id'] === $bidId && $m['id'] > $sinceId);
        usort($filtered, fn($a, $b) => $a['id'] <=> $b['id']);
        return array_slice($filtered, 0, $limit);
    }

    public function createMessage(int $bidId, int $senderId, string $body): int
    {
        $id = count($this->messages) + 1;
        $this->messages[] = [
            'id' => $id,
            'bid_id' => $bidId,
            'sender_id' => $senderId,
            'sender_name' => 'User ' . $senderId,
            'body' => $body,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        return $id;
    }

    public function getLastMessageBySender(int $bidId, int $senderId): ?array
    {
        $filtered = array_filter($this->messages, fn($m) => $m['bid_id'] === $bidId && $m['sender_id'] === $senderId);
        if ($filtered === []) return null;
        usort($filtered, fn($a, $b) => $b['id'] <=> $a['id']);
        return reset($filtered);
    }

    public function getRecentMessageCountBySender(int $bidId, int $senderId, int $seconds = 60): int
    {
        $now = time();
        $count = 0;
        foreach ($this->messages as $m) {
            if ($m['bid_id'] === $bidId && $m['sender_id'] === $senderId) {
                $created = strtotime($m['created_at'] . ' UTC');
                if (($now - $created) <= $seconds) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public function updateWatermark(int $bidId, int $userId, int $lastReadMessageId): void
    {
        $key = "{$bidId}:{$userId}";
        $current = $this->watermarks[$key]['last_read_message_id'] ?? 0;
        $this->watermarks[$key] = [
            'bid_id' => $bidId,
            'user_id' => $userId,
            'last_read_message_id' => max($current, $lastReadMessageId),
            'read_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function getWatermark(int $bidId, int $userId): ?array
    {
        return $this->watermarks["{$bidId}:{$userId}"] ?? null;
    }

    public function getUnreadCount(int $bidId, int $userId): int
    {
        $lastRead = $this->watermarks["{$bidId}:{$userId}"]['last_read_message_id'] ?? 0;
        $count = 0;
        foreach ($this->messages as $m) {
            if ($m['bid_id'] === $bidId && $m['sender_id'] !== $userId && $m['id'] > $lastRead) {
                $count++;
            }
        }
        return $count;
    }

    public function getTotalUnreadCount(int $userId, string $role): int
    {
        $total = 0;
        foreach ($this->contexts as $bId => $ctx) {
            if ($role === 'client' && (int)$ctx['client_id'] !== $userId) continue;
            if ($role === 'freelancer' && (int)$ctx['freelancer_id'] !== $userId) continue;
            $total += $this->getUnreadCount((int)$bId, $userId);
        }
        return $total;
    }

    public function getMaxMessageId(int $bidId): int
    {
        $max = 0;
        foreach ($this->messages as $m) {
            if ($m['bid_id'] === $bidId) {
                $max = max($max, $m['id']);
            }
        }
        return $max;
    }

    public function messageBelongsToBid(int $messageId, int $bidId): bool
    {
        foreach ($this->messages as $m) {
            if ($m['id'] === $messageId && $m['bid_id'] === $bidId) {
                return true;
            }
        }
        return false;
    }

    public function getInboxConversations(int $userId, string $role): array
    {
        $bidsWithMessages = [];
        foreach ($this->messages as $m) {
            $bidsWithMessages[$m['bid_id']] = true;
        }

        $res = [];
        foreach (array_keys($bidsWithMessages) as $bId) {
            $ctx = $this->contexts[$bId] ?? null;
            if (!$ctx) continue;
            if ($role === 'client' && (int)$ctx['client_id'] !== $userId) continue;
            if ($role === 'freelancer' && (int)$ctx['freelancer_id'] !== $userId) continue;

            $msgs = array_filter($this->messages, fn($m) => $m['bid_id'] === $bId);
            usort($msgs, fn($a, $b) => $b['id'] <=> $a['id']);
            $latest = reset($msgs);

            $res[] = [
                'bid_id' => $bId,
                'job_id' => $ctx['job_id'],
                'job_title' => $ctx['job_title'],
                'job_status' => $ctx['job_status'],
                'awarded_bid_id' => $ctx['awarded_bid_id'],
                'bid_status' => $ctx['bid_status'],
                'counterpart_id' => $role === 'client' ? $ctx['freelancer_id'] : $ctx['client_id'],
                'counterpart_name' => $role === 'client' ? $ctx['freelancer_name'] : $ctx['client_name'],
                'counterpart_role' => $role === 'client' ? 'freelancer' : 'client',
                'latest_message_id' => $latest['id'],
                'latest_message_body' => $latest['body'],
                'latest_message_time' => $latest['created_at'],
                'latest_sender_id' => $latest['sender_id'],
                'unread_count' => $this->getUnreadCount($bId, $userId),
            ];
        }
        return $res;
    }
}

$mockRepo = new MockMessageRepository();
$mockRepo->contexts[10] = $context;

// First message should pass rate limit
$rlFirst = MessageService::checkRateLimit(10, 100, 'Initial greeting', $mockRepo);
$assert($rlFirst === null, 'First message passes rate limit');

// Simulate message insertion with current timestamp
$mockRepo->messages[] = [
    'id' => 1,
    'bid_id' => 10,
    'sender_id' => 100,
    'sender_name' => 'Sarah Tan',
    'body' => 'Initial greeting',
    'created_at' => gmdate('Y-m-d H:i:s'),
];

// Immediate next message within 2 seconds should trigger cooldown
$rlImmediate = MessageService::checkRateLimit(10, 100, 'Second message immediately', $mockRepo);
$assert($rlImmediate !== null && str_contains($rlImmediate, 'wait a moment'), 'Sub-2-second rapid message blocked by cooldown');

// Duplicate message detection
$mockRepo->messages[0]['created_at'] = gmdate('Y-m-d H:i:s', time() - 10); // 10s ago
$rlDuplicate = MessageService::checkRateLimit(10, 100, 'Initial greeting', $mockRepo);
$assert($rlDuplicate !== null && str_contains($rlDuplicate, 'Duplicate message detected'), 'Duplicate message within 60s blocked');

// Exceeding 10 messages per minute
for ($i = 2; $i <= 11; $i++) {
    $mockRepo->messages[] = [
        'id' => $i,
        'bid_id' => 10,
        'sender_id' => 100,
        'sender_name' => 'Sarah Tan',
        'body' => "Message {$i}",
        'created_at' => gmdate('Y-m-d H:i:s', time() - 30),
    ];
}
$rlSpam = MessageService::checkRateLimit(10, 100, 'Brand new unique text', $mockRepo);
$assert($rlSpam !== null && str_contains($rlSpam, 'maximum rate limit of 10 messages per minute'), 'Burst of 10+ messages per minute blocked');

// -------------------------------------------------------------------------
// Section 5: Timestamp Handling & Malaysia Time Display
// -------------------------------------------------------------------------
echo "\n--- Section 5: Timestamp Storage & Malaysia Display Formatting ---\n";

$utcNow = gmdate('Y-m-d H:i:s');
$formattedMy = MessageService::formatMalaysiaTime($utcNow);
$assert(str_contains($formattedMy, 'Today at') || str_contains($formattedMy, 'Yesterday at'), 'Malaysia time formatting correctly handles current date');

$utcFixed = '2026-11-10 03:30:00'; // UTC 3:30 AM is 11:30 AM Malaysia
$formattedFixed = MessageService::formatMalaysiaTime($utcFixed);
$assert(str_contains($formattedFixed, '11:30 AM'), 'UTC 03:30 converts to Malaysia 11:30 AM (+8 hours)');

$relJustNow = MessageService::formatRelativeTime(gmdate('Y-m-d H:i:s', time() - 10));
$assert($relJustNow === 'Just now', 'Recent timestamp formats as "Just now"');

$rel10m = MessageService::formatRelativeTime(gmdate('Y-m-d H:i:s', time() - 600));
$assert($rel10m === '10m ago', '10 minute timestamp formats as "10m ago"');

// -------------------------------------------------------------------------
// Section 6: Persistent Watermark & Unread Tracking
// -------------------------------------------------------------------------
echo "\n--- Section 6: Persistent Read Watermark & Monotonicity ---\n";

$wmRepo = new MockMessageRepository();
$wmRepo->contexts[10] = $context;

// Add 3 messages from Freelancer (User 200)
$wmRepo->messages[] = ['id' => 1, 'bid_id' => 10, 'sender_id' => 200, 'sender_name' => 'Alex', 'body' => 'Msg 1', 'created_at' => gmdate('Y-m-d H:i:s', time() - 30)];
$wmRepo->messages[] = ['id' => 2, 'bid_id' => 10, 'sender_id' => 200, 'sender_name' => 'Alex', 'body' => 'Msg 2', 'created_at' => gmdate('Y-m-d H:i:s', time() - 20)];
$wmRepo->messages[] = ['id' => 3, 'bid_id' => 10, 'sender_id' => 200, 'sender_name' => 'Alex', 'body' => 'Msg 3', 'created_at' => gmdate('Y-m-d H:i:s', time() - 10)];

// Add 1 message from Client (User 100)
$wmRepo->messages[] = ['id' => 4, 'bid_id' => 10, 'sender_id' => 100, 'sender_name' => 'Sarah', 'body' => 'Client reply', 'created_at' => gmdate('Y-m-d H:i:s')];

// Unread count for Sarah (User 100) should be 3 (messages 1, 2, 3; message 4 is her own!)
$unreadSarah = $wmRepo->getUnreadCount(10, 100);
$assert($unreadSarah === 3, 'Client has 3 unread messages (sender own message strictly excluded)');

// Unread count for Alex (User 200) should be 1 (message 4; messages 1, 2, 3 are his own!)
$unreadAlex = $wmRepo->getUnreadCount(10, 200);
$assert($unreadAlex === 1, 'Freelancer has 1 unread message (sender own messages strictly excluded)');

// Advance Sarah's watermark to message 2
$wmRepo->updateWatermark(10, 100, 2);
$assert($wmRepo->getUnreadCount(10, 100) === 1, 'After marking up to message 2, client has 1 unread remaining');

// Monotonicity check: attempting to rewind watermark to 1 should be ignored (GREATEST)
$wmRepo->updateWatermark(10, 100, 1);
$wmSarah = $wmRepo->getWatermark(10, 100);
$assert($wmSarah['last_read_message_id'] === 2, 'Watermark monotonicity preserved: rewind attempt ignored');

// Advance Sarah's watermark to message 4
$wmRepo->updateWatermark(10, 100, 4);
$assert($wmRepo->getUnreadCount(10, 100) === 0, 'After marking latest message, client unread count is 0');

// -------------------------------------------------------------------------
// Section 7: MessageController Endpoints & Security
// -------------------------------------------------------------------------
echo "\n--- Section 7: MessageController HTTP Endpoints & Security ---\n";

$currentUser = null;
$fakeAuthService = new class($currentUser) {
    public function __construct(private mixed &$user) {}
    public function identity(int $id): ?array {
        return $this->user;
    }
};

$session = new AuthSession(static fn() => time(), static fn() => true);
$authGuard = new AuthGuard(static fn() => $fakeAuthService, $session);
$ctrlRepo = new MockMessageRepository();
$ctrlRepo->contexts[10] = $context;

$controller = new MessageController($authGuard, static fn() => [$ctrlRepo, new MessageService()]);

// Helper to set active user in test
$setUser = function(?array $u) use (&$currentUser, $session): void {
    $currentUser = $u;
    if ($u !== null) {
        $_SESSION['auth'] = [
            'user_id' => $u['id'],
            'issued_at' => time(),
            'last_activity' => time()
        ];
    } else {
        unset($_SESSION['auth']);
    }
};

// Test 1: Guest access to inbox
$setUser(null);
$guestInbox = $controller->inbox();
$assert($guestInbox['status'] === 303 && str_contains($guestInbox['location'], 'route=/login'), 'Guest inbox request redirects to login');

// Test 2: Guest access to polling returns 401 JSON
$guestPoll = $controller->poll(['bid_id' => 10]);
$assert($guestPoll['status'] === 401 && str_contains($guestPoll['body'], 'Unauthorized'), 'Guest poll returns 401 JSON');

// Test 3: Guest read acknowledgement returns 401 JSON
$guestRead = $controller->read(['bid_id' => 10, 'message_id' => 1]);
$assert($guestRead['status'] === 401 && str_contains($guestRead['body'], 'Unauthorized'), 'Guest read returns 401 JSON');

// Test 4: Authenticated Sarah (Client) accesses thread
$sarah = ['id' => 100, 'role' => 'client', 'display_name' => 'Sarah Tan'];
$setUser($sarah);

$sarahThread = $controller->thread(['bid_id' => 10]);
$assert($sarahThread['status'] === 200, 'Authorized client can view conversation thread');
$assert(str_contains($sarahThread['body'], 'Conversation with Alex Wong'), 'Thread header displays counterpart name');
$assert(str_contains($sarahThread['body'], 'Web App UI Refactoring'), 'Thread header displays job title');

// Test 5: IDOR check - David (Client User 105) attempts to view Sarah's conversation 10
$david = ['id' => 105, 'role' => 'client', 'display_name' => 'David Lee'];
$setUser($david);

$davidThread = $controller->thread(['bid_id' => 10]);
$assert($davidThread['status'] === 303 && str_contains($davidThread['location'], 'route=/messages'), 'Unauthorized client blocked from accessing another user conversation');

// Test 6: Message sending without CSRF token fails with 403
$setUser($sarah);
$sendNoCsrf = $controller->send(['bid_id' => 10, 'body' => 'Hello without token']);
$assert($sendNoCsrf['status'] === 303, 'HTML send without valid CSRF redirected with error');

// Test 7: Send with valid CSRF token succeeds
$validCsrf = Csrf::token('message_action');
$sendValid = $controller->send([
    'csrf_token' => $validCsrf,
    'bid_id' => 10,
    'body' => 'Hello Alex! I would like to discuss milestones.',
]);
$assert($sendValid['status'] === 303, 'Valid message submission completes with Post/Redirect/Get');
$assert(count($ctrlRepo->messages) === 1, 'Message was successfully persisted to repository');
$assert($ctrlRepo->messages[0]['body'] === 'Hello Alex! I would like to discuss milestones.', 'Message body matches input');

// Test 8: Polling endpoint returns new message
$alex = ['id' => 200, 'role' => 'freelancer', 'display_name' => 'Alex Wong'];
$setUser($alex);

$alexPoll = $controller->poll(['bid_id' => 10, 'since_id' => 0]);
$pollData = json_decode($alexPoll['body'], true);
$assert($alexPoll['status'] === 200 && count($pollData['messages']) === 1, 'Freelancer receives 1 new message via polling');
$assert($pollData['messages'][0]['is_me'] === false, 'Sender of polled message identified correctly as counterpart');

// Test 8b: GET thread is strictly read-only and renders UI refinements
$alexThread = $controller->thread(['bid_id' => 10]);
$assert($alexThread['status'] === 200, 'Freelancer can view thread via GET');
$assert($ctrlRepo->getWatermark(10, 200) === null, 'GET thread does NOT update read watermark (strictly read-only)');
$assert(str_contains($alexThread['body'], '<div class="gh-message-bubble">Hello Alex! I would like to discuss milestones.</div>'), 'Rendered message bubble has compact single-line HTML without leading/trailing whitespace');
$assert(str_contains($alexThread['body'], 'class="gh-message-copy-btn"'), 'Thread view renders copy message button');
$assert(str_contains($alexThread['body'], 'class="gh-quick-emojis"'), 'Thread view renders quick emojis bar');

// Test 8c: Unread count endpoint /messages/count
$alexCount = $controller->unreadCount();
$alexCountData = json_decode($alexCount['body'], true);
$assert($alexCount['status'] === 200 && $alexCountData['count'] === 1, 'Freelancer with 1 unread message gets count 1 from /messages/count');

$setUser(null);
$guestCount = $controller->unreadCount();
$guestCountData = json_decode($guestCount['body'], true);
$assert($guestCount['status'] === 200 && $guestCountData['count'] === 0, 'Guest unreadCount returns count 0');
$setUser($alex);

// Test 9: Read acknowledgement updates watermark
$readRes = $controller->read([
    'csrf_token' => Csrf::token('message_action'),
    'bid_id' => 10,
    'message_id' => 1,
]);
$readData = json_decode($readRes['body'], true);
$assert($readRes['status'] === 200 && ($readData['success'] ?? false) === true, 'Read acknowledgement succeeds');
$assert($ctrlRepo->getWatermark(10, 200)['last_read_message_id'] === 1, 'Watermark updated in repository for freelancer');

$alexCountAfter = $controller->unreadCount();
$alexCountAfterData = json_decode($alexCountAfter['body'], true);
$assert($alexCountAfterData['count'] === 0, 'Unread count drops to 0 after read acknowledgement');

// Test 10: Forged message ID rejection
$forgedRead = $controller->read([
    'csrf_token' => Csrf::token('message_action'),
    'bid_id' => 10,
    'message_id' => 99999, // Non-existent future message
]);
$assert($forgedRead['status'] === 400 && str_contains($forgedRead['body'], 'Invalid message ID'), 'Forged future message ID rejected with 400');

// Test 11: Cross-conversation message ID rejection
$ctrlRepo->contexts[20] = [
    'bid_id' => 20,
    'job_id' => 8,
    'client_id' => 100,
    'freelancer_id' => 200,
    'client_name' => 'Sarah Tan',
    'freelancer_name' => 'Alex Wong',
    'job_title' => 'Other Job',
    'job_status' => 'open',
    'awarded_bid_id' => null,
    'bid_status' => 'pending',
];
$ctrlRepo->messages[] = [
    'id' => 99,
    'bid_id' => 20, // Different bid!
    'sender_id' => 100,
    'sender_name' => 'Sarah',
    'body' => 'Msg in other bid',
    'created_at' => gmdate('Y-m-d H:i:s'),
];
$crossRead = $controller->read([
    'csrf_token' => Csrf::token('message_action'),
    'bid_id' => 10,
    'message_id' => 99, // Message 99 belongs to bid 20, not 10!
]);
$assert($crossRead['status'] === 400 && str_contains($crossRead['body'], 'Invalid message ID'), 'Cross-conversation message ID rejected with 400');

// Test 12: Inbox rendering with conversation card
$inboxSarah = $controller->inbox();
$assert($inboxSarah['status'] === 200, 'Inbox renders successfully with active conversations');
$assert(str_contains($inboxSarah['body'], 'Alex Wong'), 'Inbox card displays counterpart display name');
$assert(str_contains($inboxSarah['body'], 'Web App UI Refactoring'), 'Inbox card displays associated job title');
$assert(str_contains($inboxSarah['body'], 'Open Chat'), 'Inbox card contains Open Chat button');
$assert(preg_match('/<link rel="stylesheet" href="assets\/messages\.css\?v=[a-f0-9]{12}">/', $inboxSarah['body']) === 1, 'Inbox view includes messages.css with sha256 hash cache-buster');
$assert(preg_match('/<link rel="stylesheet" href="assets\/messages\.css\?v=[a-f0-9]{12}">/', $sarahThread['body']) === 1, 'Thread view includes messages.css with sha256 hash cache-buster');
$assert(str_contains(file_get_contents(dirname(__DIR__) . '/public/assets/messages.css'), 'width: 100%;'), 'messages.css enforces width: 100% on layout containers to prevent narrow fit-content collapse');

// -------------------------------------------------------------------------
// Summary
// -------------------------------------------------------------------------
echo "\n=======================================================\n";
echo " Test Results: {$testCount} run, {$failCount} failed\n";
echo "=======================================================\n";

if ($failCount > 0) {
    exit(1);
}
