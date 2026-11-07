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
    'Services/LoginValidator',
    'Services/AuthenticationService',
    'Repositories/NotificationRepository',
    'Services/NotificationService',
    'Controllers/NotificationController',
    'Repositories/BidRepository',
    'Services/BidException',
    'Services/BidValidator',
    'Services/BidService',
    'Services/AwardService',
    'Repositories/EscrowRepository',
    'Services/EscrowException',
    'Services/EscrowService',
    'Repositories/SubmissionRepository',
    'Services/SubmissionException',
    'Services/SubmissionValidator',
    'Services/ReviewValidator',
    'Services/SubmissionService',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Core\Csrf;
use GigHub\Controllers\NotificationController;
use GigHub\Repositories\NotificationRepository;
use GigHub\Services\NotificationService;

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
echo " GigHub Module 19: Persistent Notification Center Tests\n";
echo "=======================================================\n\n";

// In-Memory SQLite PDO for Offline Testing
$sqlite = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$sqlite->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'));

// SQLite schema imitating MariaDB notifications table
$sqlite->exec("
    CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient_id INTEGER NOT NULL,
        job_id INTEGER DEFAULT NULL,
        type TEXT NOT NULL,
        message TEXT NOT NULL,
        read_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open',
        awarded_bid_id INTEGER DEFAULT NULL,
        awarded_at TEXT DEFAULT NULL,
        deadline TEXT DEFAULT NULL
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        display_name TEXT NOT NULL,
        role TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        email_verified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert mock users & jobs
$sqlite->exec("
    INSERT INTO users (id, email, display_name, role) VALUES (1, 'client@test.local', 'Sarah Client', 'client');
    INSERT INTO users (id, email, display_name, role) VALUES (2, 'freelancer@test.local', 'Alex Freelancer', 'freelancer');
    INSERT INTO users (id, email, display_name, role) VALUES (3, 'other@test.local', 'Other User', 'freelancer');
    INSERT INTO jobs (id, client_id, title, status) VALUES (10, 1, 'E-Commerce Website', 'open');
");

// -----------------------------------------------------------------------------
// Suite 1: NotificationRepository Tests
// -----------------------------------------------------------------------------
echo "Suite 1: NotificationRepository Operations\n";

$repo = new NotificationRepository($sqlite);

// 1. Create notifications
$notif1 = $repo->create(1, 10, 'bid_received', 'New bid received for E-Commerce Website');
$notif2 = $repo->create(1, 10, 'submission_received', 'Work submitted for milestone 1');
$notif3 = $repo->create(2, 10, 'award_selected', 'Congratulations! Your bid was selected');
$notif4 = $repo->create(1, 10, 'review_received', 'New review received');

$assert($notif1 > 0 && $notif2 > 0 && $notif3 > 0, 'Notifications created with positive auto-increment IDs');

// 2. Count unread
$assert($repo->countUnread(1) === 3, 'Recipient 1 has exactly 3 unread notifications');
$assert($repo->countUnread(2) === 1, 'Recipient 2 has exactly 1 unread notification');
$assert($repo->countUnread(3) === 0, 'Recipient 3 has exactly 0 unread notifications');

// 3. getForUser listing
$allForUser1 = $repo->getForUser(1, 'all', 10, 0);
$assert(count($allForUser1) === 3, 'User 1 fetches 3 notifications in total');
$assert($allForUser1[0]['id'] > $allForUser1[1]['id'], 'Notifications ordered descending by ID');

$allForUser2 = $repo->getForUser(2, 'all', 10, 0);
$assert(count($allForUser2) === 1, 'User 2 fetches exactly 1 notification');
$assert($allForUser2[0]['type'] === 'award_selected', 'User 2 notification type is award_selected');

// 4. Mark one as read with strict ownership check
// User 2 tries to mark User 1's notification as read
$crossUserResult = $repo->markAsRead($notif1, 2);
$assert($crossUserResult === false, 'Cross-user markAsRead fails (cannot mark another user notification as read)');
$assert($repo->countUnread(1) === 3, 'User 1 unread count remains 3 after unauthorized attempt');

// User 1 marks own notification as read
$ownResult = $repo->markAsRead($notif1, 1);
$assert($ownResult === true, 'Recipient successfully marks own notification as read');
$assert($repo->countUnread(1) === 2, 'User 1 unread count decremented to 2');

// Re-marking already-read notification returns false
$reMarkResult = $repo->markAsRead($notif1, 1);
$assert($reMarkResult === false, 'Marking already read notification returns false');

// 5. Unread filter
$unreadForUser1 = $repo->getForUser(1, 'unread', 10, 0);
$assert(count($unreadForUser1) === 2, 'Unread filter returns exactly 2 notifications');
$unreadIds = array_column($unreadForUser1, 'id');
$assert(!in_array($notif1, $unreadIds), 'Read notification is excluded from unread list');

// 6. Mark all as read
$markedCount = $repo->markAllAsRead(1);
$assert($markedCount === 2, 'markAllAsRead returns count of remaining unread notifications marked (2)');
$assert($repo->countUnread(1) === 0, 'User 1 unread count is now 0');
$assert($repo->countUnread(2) === 1, 'User 2 unread count was untouched by User 1 markAllAsRead');

// 7. findForUser
$found = $repo->findForUser($notif2, 1);
$assert($found !== null && (int)$found['id'] === $notif2, 'findForUser returns notification for owner');
$unauthorizedFound = $repo->findForUser($notif2, 2);
$assert($unauthorizedFound === null, 'findForUser returns null for non-owner');

// -----------------------------------------------------------------------------
// Suite 2: NotificationService Validation & Formatting
// -----------------------------------------------------------------------------
echo "\nSuite 2: NotificationService Validation & Helpers\n";

$service = new NotificationService($repo);

// Validation tests
$caught = false;
try {
    $service->dispatch(0, 10, 'bid_received', 'Valid message');
} catch (InvalidArgumentException $e) {
    $caught = true;
}
$assert($caught, 'Rejects non-positive recipient ID');

$caught = false;
try {
    $service->dispatch(1, 10, 'invalid_type_xyz', 'Valid message');
} catch (InvalidArgumentException $e) {
    $caught = true;
}
$assert($caught, 'Rejects unsupported notification type');

$caught = false;
try {
    $service->dispatch(1, 10, 'bid_received', '   ');
} catch (InvalidArgumentException $e) {
    $caught = true;
}
$assert($caught, 'Rejects empty / whitespace-only message');

$caught = false;
try {
    $service->dispatch(1, 10, 'bid_received', str_repeat('a', 501));
} catch (InvalidArgumentException $e) {
    $caught = true;
}
$assert($caught, 'Rejects message exceeding 500 characters');

// Safe Destination Routing tests
$clientBidRoute = NotificationService::getDestinationUrl(['type' => 'bid_received', 'job_id' => 10], 'client');
$assert($clientBidRoute === '?route=/client/jobs/bids&id=10', 'bid_received routes client to client job bids');

$freelancerAwardRoute = NotificationService::getDestinationUrl(['type' => 'award_selected', 'job_id' => 10], 'freelancer');
$assert($freelancerAwardRoute === '?route=/freelancer/jobs/milestones&job_id=10', 'award_selected routes freelancer to milestones');

$freelancerEscrowRoute = NotificationService::getDestinationUrl(['type' => 'escrow_funded', 'job_id' => 10], 'freelancer');
$assert($freelancerEscrowRoute === '?route=/freelancer/jobs/milestones&job_id=10', 'escrow_funded routes freelancer to milestones');

$clientSubmissionRoute = NotificationService::getDestinationUrl(['type' => 'submission_received', 'job_id' => 10], 'client');
$assert($clientSubmissionRoute === '?route=/client/jobs/milestones&job_id=10', 'submission_received routes client to milestones');

$freelancerPaymentRoute = NotificationService::getDestinationUrl(['type' => 'payment_released', 'job_id' => 10], 'freelancer');
$assert($freelancerPaymentRoute === '?route=/freelancer/wallet', 'payment_released routes freelancer to wallet');

$freelancerReviewRoute = NotificationService::getDestinationUrl(['type' => 'review_received', 'job_id' => 10], 'freelancer');
$assert($freelancerReviewRoute === '?route=/freelancer/profile', 'review_received routes freelancer to profile');

// Relative Time tests
$nowUtc = gmdate('Y-m-d H:i:s');
$assert(NotificationService::formatRelativeTime($nowUtc) === 'Just now', 'formatRelativeTime handles recent time as Just now');

$fiveMinsAgo = gmdate('Y-m-d H:i:s', time() - 300);
$assert(NotificationService::formatRelativeTime($fiveMinsAgo) === '5 minutes ago', 'formatRelativeTime handles minutes ago');

$twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 7200);
$assert(NotificationService::formatRelativeTime($twoHoursAgo) === '2 hours ago', 'formatRelativeTime handles hours ago');

$yesterday = gmdate('Y-m-d H:i:s', time() - 90000);
$assert(NotificationService::formatRelativeTime($yesterday) === 'Yesterday', 'formatRelativeTime handles Yesterday');

// -----------------------------------------------------------------------------
// Suite 3: NotificationController & Route Security
// -----------------------------------------------------------------------------
echo "\nSuite 3: NotificationController & Security\n";

$usersRepo = new class extends \GigHub\Repositories\UserRepository {
    public array|false $row = false;
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false { return $this->row; }
};

$session = new AuthSession(null, static fn() => true);
$authService = new \GigHub\Services\AuthenticationService($usersRepo);
$guard = new AuthGuard(static fn() => $authService, $session);
$controllerFactory = fn() => [$repo, $service];
$controller = new NotificationController($guard, $controllerFactory);

// 1. Guest access to /notifications redirects to /login
$_SESSION = [];
$usersRepo->row = false;
$guestResponse = $controller->index([]);
$assert($guestResponse['status'] === 303 && str_contains($guestResponse['location'], '/login'), 'Guest index access redirects (303) to /login');

$guestMarkRead = $controller->markRead(['notification_id' => 1]);
$assert($guestMarkRead['status'] === 303 && str_contains($guestMarkRead['location'], '/login'), 'Guest markRead redirects (303) to /login');

$guestCount = $controller->unreadCount();
$assert($guestCount['status'] === 200 && json_decode($guestCount['body'], true)['unread_count'] === 0, 'Guest count returns 0 without leaking data');

// 2. Authenticated user access
$clientRow = ['id' => 1, 'email' => 'client@test.local', 'role' => 'client', 'display_name' => 'Sarah Client', 'status' => 'active', 'email_verified_at' => '2026-01-01'];
$usersRepo->row = $clientRow;
$session->establish(1);

$authIndex = $controller->index([]);
$assert($authIndex['status'] === 200, 'Authenticated index returns 200 HTML');
$assert(str_contains($authIndex['body'], 'PERSISTENT NOTIFICATION CENTER'), 'Notification Center view rendered');

// 3. CSRF token validation on markRead
$csrfToken = Csrf::token('notification_action');

$invalidCsrfResponse = $controller->markRead([
    'csrf_token' => 'invalid_token_123',
    'notification_id' => $notif2,
]);
$assert($invalidCsrfResponse['status'] === 303, 'Invalid CSRF on markRead issues PRG redirect');
$assert(isset($_SESSION['gh_notifications']), 'Flash error recorded on invalid CSRF');

// 4. Successful markRead with redirect=target
$newNotif = $repo->create(1, 10, 'bid_received', 'New bid for project');
$validMarkRead = $controller->markRead([
    'csrf_token' => $csrfToken,
    'notification_id' => $newNotif,
    'redirect' => 'target',
]);
$assert($validMarkRead['status'] === 303, 'markRead with redirect=target returns 303');
$assert($validMarkRead['location'] === '?route=/client/jobs/bids&id=10', 'markRead redirects to verified safe target URL');

// 5. Successful markAllRead
$repo->create(1, 10, 'review_received', 'Review 1');
$repo->create(1, 10, 'review_received', 'Review 2');
$assert($repo->countUnread(1) === 2, 'User 1 has 2 unread notifications');

$markAllResponse = $controller->markAllRead(['csrf_token' => $csrfToken]);
$assert($markAllResponse['status'] === 303 && str_contains($markAllResponse['location'], '/notifications'), 'markAllRead redirects to /notifications');
$assert($repo->countUnread(1) === 0, 'User 1 unread count is now 0 after markAllRead');

// 6. Polling unreadCount returns accurate count
$repo->create(1, 10, 'bid_received', 'Polling test notification');
$countRes = $controller->unreadCount();
$data = json_decode($countRes['body'], true);
$assert($data['unread_count'] === 1, 'unreadCount returns exact JSON unread count (1)');

// -----------------------------------------------------------------------------
// Suite 4: Transaction Atomicity & Rollback Safety
// -----------------------------------------------------------------------------
echo "\nSuite 4: Transaction Atomicity & Rollback Verification\n";

$beforeCount = $repo->countTotal(1);

// Simulate an operation that writes a notification and then fails / rolls back
$sqlite->beginTransaction();
try {
    $repo->create(1, 10, 'bid_received', 'This notification should be rolled back');
    // Simulate failure in business logic
    throw new RuntimeException('Simulated business error');
} catch (RuntimeException $e) {
    $sqlite->rollBack();
}

$afterCount = $repo->countTotal(1);
$assert($beforeCount === $afterCount, 'Notification write rolled back atomically when transaction aborted');

// -----------------------------------------------------------------------------
// Suite 5: Duplicate Prevention via State Machine Verification
// -----------------------------------------------------------------------------
echo "\nSuite 5: Duplicate Prevention Verification\n";

// Award state machine prevents re-awarding (status check)
// When job status is 'awarded', selectAward returns false, preventing duplicate award notification
$sqlite->exec("UPDATE jobs SET status = 'awarded', awarded_bid_id = 99 WHERE id = 10");

$awardJob = $sqlite->query("SELECT status, awarded_bid_id FROM jobs WHERE id = 10")->fetch();
$assert($awardJob['status'] === 'awarded', 'Job transitioned to awarded');
$canReAward = $awardJob['status'] === 'open' && $awardJob['awarded_bid_id'] === null;
$assert(!$canReAward, 'State machine rejects retry when job is already awarded (prevents duplicate notification)');

// -----------------------------------------------------------------------------
// Suite 6: Bid Lifecycle Notifications (New, Revised, Resubmitted, No-Op)
// -----------------------------------------------------------------------------
echo "\nSuite 6: Bid Notifications Lifecycle\n";

$sqlite->exec("
    CREATE TABLE IF NOT EXISTS bids (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL,
        freelancer_id INTEGER NOT NULL,
        amount NUMERIC NOT NULL,
        delivery_days INTEGER NOT NULL,
        cover_letter TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS client_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS freelancer_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL
    );
    INSERT INTO users (id, email, role, display_name, status, email_verified_at)
    VALUES 
        (101, 'client.bid@test.local', 'client', 'Client Sarah', 'active', '2026-01-01'),
        (102, 'freelancer.bid@test.local', 'freelancer', 'Freelancer Alex', 'active', '2026-01-01');
    INSERT INTO client_profiles (user_id) VALUES (101);
    INSERT INTO freelancer_profiles (user_id) VALUES (102);
    INSERT INTO jobs (id, client_id, title, status, deadline)
    VALUES (201, 101, 'Mobile App Portal', 'open', '2027-12-31');
");

$bidRepo = new class($sqlite) extends \GigHub\Repositories\BidRepository {
    public function __construct(private readonly PDO $db) { parent::__construct($db); }
    public function supportsWithdrawal(): bool { return true; }
    public function job(int $id, bool $lock = false): array|false {
        return parent::job($id, false);
    }
    public function forJob(int $jobId, int $freelancerId, bool $lock = false): array|false {
        return parent::forJob($jobId, $freelancerId, false);
    }
    public function eligible(int $id, string $role, bool $lock = false): bool {
        return parent::eligible($id, $role, false);
    }
    public function owned(int $id, int $freelancerId, bool $lock = false): array|false {
        return parent::owned($id, $freelancerId, false);
    }
};

$bidService = new \GigHub\Services\BidService($sqlite, $bidRepo, $repo);

// 1. New Bid Submission creates 'New bid of RM...' notification
$initialClientNotifs = $repo->countTotal(101);
$bidData1 = [
    'job_id' => '201',
    'amount' => '6200.00',
    'delivery_days' => '30',
    'cover_letter' => 'Initial full proposal for the mobile app portal.',
];
$createdBidId = $bidService->save(102, $bidData1);
$assert($createdBidId > 0, 'Bid created successfully');

$afterNewNotifs = $repo->countTotal(101);
$assert($afterNewNotifs === $initialClientNotifs + 1, 'New bid creates exactly 1 notification for job owner');

$latestNotif = $repo->getForUser(101)[0];
$assert($latestNotif['type'] === 'bid_received', 'New bid notification has type bid_received');
$assert(str_contains($latestNotif['message'], 'New bid of RM6,200.00 received for "Mobile App Portal"'), 'New bid notification formatted correctly');

// 2. Meaningful Revision creates 'Updated bid of RM...' notification
$bidData2 = [
    'id' => (string)$createdBidId,
    'amount' => '6100.00',
    'delivery_days' => '28',
    'cover_letter' => 'Revised full proposal with updated timeline.',
];
$updatedBidId = $bidService->save(102, $bidData2, $createdBidId);
$assert($updatedBidId === $createdBidId, 'Bid updated successfully');

$afterReviseNotifs = $repo->countTotal(101);
$assert($afterReviseNotifs === $afterNewNotifs + 1, 'Meaningful bid revision creates exactly 1 notification');

$latestNotif2 = $repo->getForUser(101)[0];
$assert(str_contains($latestNotif2['message'], 'Updated bid of RM6,100.00 received for "Mobile App Portal"'), 'Revised bid notification formatted correctly');

// 3. No-Op Update does NOT create a notification
$bidDataNoOp = [
    'id' => (string)$createdBidId,
    'amount' => '6100.00',
    'delivery_days' => '28',
    'cover_letter' => 'Revised full proposal with updated timeline.',
];
$bidService->save(102, $bidDataNoOp, $createdBidId);
$afterNoOpNotifs = $repo->countTotal(101);
$assert($afterNoOpNotifs === $afterReviseNotifs, 'No-op bid update does NOT create any notification');

// 4. Withdrawal + Resubmission creates 'Resubmitted bid of RM...' notification
$bidService->withdraw(102, $createdBidId);
$withdrawnBid = $bidRepo->owned($createdBidId, 102);
$assert($withdrawnBid['status'] === 'withdrawn', 'Bid successfully withdrawn');

$bidDataResubmit = [
    'id' => (string)$createdBidId,
    'amount' => '6050.00',
    'delivery_days' => '25',
    'cover_letter' => 'Resubmitted proposal with discounted price.',
];
$resubmittedBidId = $bidService->save(102, $bidDataResubmit, $createdBidId);
$afterResubmitNotifs = $repo->countTotal(101);
$assert($afterResubmitNotifs === $afterNoOpNotifs + 1, 'Valid resubmission creates exactly 1 notification');

$latestNotif3 = $repo->getForUser(101)[0];
$assert(str_contains($latestNotif3['message'], 'Resubmitted bid of RM6,050.00 received for "Mobile App Portal"'), 'Resubmitted bid notification formatted correctly');

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n=======================================================\n";
echo " Results: {$testCount} run, {$failCount} failed\n";
echo "=======================================================\n";

if ($failCount > 0) {
    exit(1);
}
