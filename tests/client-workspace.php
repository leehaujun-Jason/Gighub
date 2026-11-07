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
    'Repositories/UserRepository',
    'Repositories/ClientWorkspaceRepository',
    'Controllers/ClientWorkspaceController',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Core\Csrf;
use GigHub\Controllers\ClientWorkspaceController;
use GigHub\Repositories\ClientWorkspaceRepository;

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
echo " GigHub Module 19: Client Workspace Offline Tests\n";
echo "=======================================================\n\n";

// In-Memory SQLite PDO for Offline Testing
$sqlite = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$sqlite->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => gmdate('Y-m-d H:i:s'));

// Schema
$sqlite->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        display_name TEXT NOT NULL,
        role TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        email_verified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE client_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL
    );
    CREATE TABLE freelancer_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL
    );
    CREATE TABLE jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open',
        budget NUMERIC NOT NULL,
        deadline TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE bids (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL,
        freelancer_id INTEGER NOT NULL,
        amount NUMERIC NOT NULL,
        delivery_days INTEGER NOT NULL,
        cover_letter TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE work_submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL,
        milestone_id INTEGER DEFAULT NULL,
        submitted_by INTEGER NOT NULL,
        submission_type TEXT NOT NULL,
        notes TEXT NOT NULL,
        deliverable_url TEXT DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'submitted',
        submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INTEGER DEFAULT NULL,
        reviewed_at TEXT DEFAULT NULL,
        feedback TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
");

// Insert Mock Users
$sqlite->exec("
    INSERT INTO users (id, email, display_name, role) VALUES 
        (1, 'client.sarah@demo.test', 'Sarah Tan', 'client'),
        (2, 'freelancer.alex@demo.test', 'Alex Wong', 'freelancer'),
        (3, 'client.david@demo.test', 'David Chen', 'client');
    INSERT INTO client_profiles (user_id) VALUES (1), (3);
    INSERT INTO freelancer_profiles (user_id) VALUES (2);
");

// Insert Sarah's Jobs
// 1: draft, 2: open, 3: awarded, 4: in_progress, 5: completed, 6: cancelled
$sqlite->exec("
    INSERT INTO jobs (id, client_id, title, status, budget, deadline, created_at) VALUES 
        (10, 1, 'Draft Project Portal', 'draft', 2000.00, '2027-01-31', '2026-11-01 10:00:00'),
        (11, 1, 'Open API Development', 'open', 5000.00, '2027-02-28', '2026-11-02 10:00:00'),
        (12, 1, 'Awarded Mobile App', 'awarded', 8000.00, '2027-03-31', '2026-11-03 10:00:00'),
        (13, 1, 'In Progress Microservice', 'in_progress', 6500.00, '2027-04-30', '2026-11-04 10:00:00'),
        (14, 1, 'Completed CRM Integration', 'completed', 4500.00, '2026-10-31', '2026-09-01 10:00:00'),
        (15, 1, 'Cancelled Old Project', 'cancelled', 1000.00, '2026-08-31', '2026-07-01 10:00:00');

    -- David's Job (different client)
    INSERT INTO jobs (id, client_id, title, status, budget, deadline, created_at) VALUES 
        (20, 3, 'David Project', 'open', 3000.00, '2027-05-31', '2026-11-05 10:00:00');

    -- Bids on Sarah's Open Job (11)
    INSERT INTO bids (job_id, freelancer_id, amount, delivery_days, cover_letter) VALUES
        (11, 2, 4800.00, 20, 'Alex proposal on Job 11');

    -- Submissions
    -- Actionable submission on active Job 13
    INSERT INTO work_submissions (job_id, submitted_by, submission_type, notes, status) VALUES
        (13, 2, 'milestone', 'Milestone 1 work', 'submitted');

    -- Reviewed (already approved) submission on active Job 13 (should NOT count as pending)
    INSERT INTO work_submissions (job_id, submitted_by, submission_type, notes, status, reviewed_by) VALUES
        (13, 2, 'milestone', 'Milestone 0 work', 'approved', 1);

    -- Superseded (revisions_requested) submission on active Job 13 (should NOT count as pending)
    INSERT INTO work_submissions (job_id, submitted_by, submission_type, notes, status, reviewed_by) VALUES
        (13, 2, 'milestone', 'Milestone 1 rough draft', 'revisions_requested', 1);

    -- Submission on Completed Job 14 (should NOT count as pending)
    INSERT INTO work_submissions (job_id, submitted_by, submission_type, notes, status) VALUES
        (14, 2, 'final', 'Final work', 'submitted');

    -- Submission on Cancelled Job 15 (should NOT count as pending)
    INSERT INTO work_submissions (job_id, submitted_by, submission_type, notes, status) VALUES
        (15, 2, 'milestone', 'Abandoned work', 'submitted');

    -- Submission on David's Job 20 (should NOT count for Sarah)
    INSERT INTO work_submissions (job_id, submitted_by, submission_type, notes, status) VALUES
        (20, 2, 'milestone', 'David milestone', 'submitted');
");

$repo = new ClientWorkspaceRepository($sqlite);

// -----------------------------------------------------------------------------
// Suite 1: ClientWorkspaceRepository Metrics & Scoping
// -----------------------------------------------------------------------------
echo "Suite 1: ClientWorkspaceRepository Metrics & Scoping\n";

$sarahStats = $repo->getStatistics(1);
$assert($sarahStats['total_jobs'] === 6, 'Total jobs count matches Sarah jobs (6)');
$assert($sarahStats['open_jobs'] === 1, 'Open jobs count matches Sarah open jobs (1)');
$assert($sarahStats['active_projects'] === 2, 'Active projects matches awarded + in_progress (2)');
$assert($sarahStats['pending_reviews'] === 1, 'Pending reviews counts only actionable submissions on active jobs (1)');

// Verify David's statistics are isolated
$davidStats = $repo->getStatistics(3);
$assert($davidStats['total_jobs'] === 1, 'David has exactly 1 job');
$assert($davidStats['open_jobs'] === 1, 'David has 1 open job');
$assert($davidStats['active_projects'] === 0, 'David has 0 active projects');
$assert($davidStats['pending_reviews'] === 0, 'David open job has no pending review (job is open, not active project)');

// Recent Jobs
$recent = $repo->getRecentJobs(1, 5);
$assert(count($recent) === 5, 'Fetches up to 5 recent jobs for client');
$assert((int)$recent[0]['id'] === 15, 'Recent jobs ordered descending by ID');
$assert((int)$recent[4]['id'] === 11, '5th recent job is Job 11');
$assert((int)$recent[4]['bid_count'] === 1, 'Job 11 has accurate bid count (1)');

// -----------------------------------------------------------------------------
// Suite 2: ClientWorkspaceController Authorization & Rendering
// -----------------------------------------------------------------------------
echo "\nSuite 2: ClientWorkspaceController Authorization & Rendering\n";

$usersRepo = new class extends \GigHub\Repositories\UserRepository {
    public array|false $row = false;
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false { return $this->row; }
};

$session = new AuthSession(null, static fn() => true);
$authService = new class($usersRepo) {
    public function __construct(private readonly \GigHub\Repositories\UserRepository $repo) {}
    public function identity(int $id): ?array { 
        $row = $this->repo->findAuthIdentity($id); 
        return $row === false ? null : $row; 
    }
};
$authGuard = new AuthGuard(static fn() => $authService, $session);
$controller = new ClientWorkspaceController($authGuard, static fn() => $repo);

// 1. Unauthenticated Guest Access
$_SESSION = [];
$usersRepo->row = false;
$guestRes = $controller->index();
$assert($guestRes['status'] === 303, 'Guest access is redirected (303)');
$assert(str_contains($guestRes['location'] ?? '', '/login'), 'Guest redirected to login');

// 2. Unauthorized Role Access (Freelancer)
$freelancerRow = ['id' => 2, 'email' => 'alex@demo.test', 'role' => 'freelancer', 'display_name' => 'Alex Wong', 'status' => 'active', 'email_verified_at' => '2026-01-01'];
$usersRepo->row = $freelancerRow;
$session->establish(2);
$freelancerRes = $controller->index();
$assert($freelancerRes['status'] === 403, 'Freelancer role is blocked with 403 Forbidden');

// 3. Authorized Client Access
$clientRow = ['id' => 1, 'email' => 'sarah@demo.test', 'role' => 'client', 'display_name' => 'Sarah Tan', 'status' => 'active', 'email_verified_at' => '2026-01-01'];
$usersRepo->row = $clientRow;
$session->establish(1);
$clientRes = $controller->index();
$assert($clientRes['status'] === 200, 'Authorized client returns 200 OK');
$body = $clientRes['body'];

// Header & Navigation Shell assertions
$assert(str_contains($body, 'CLIENT WORKSPACE'), 'Contains CLIENT WORKSPACE eyebrow');
$assert(str_contains($body, 'Welcome back, Sarah Tan'), 'Contains client display name with greeting');
$assert(str_contains($body, 'Manage your projects and collaborate with freelancers.'), 'Contains subtitle');
$assert(str_contains($body, 'id="gh-bell-btn"'), 'Shared header notification bell is present');
$assert(str_contains($body, 'id="gh-bell-badge"'), 'Shared header notification badge is present');
$assert(str_contains($body, 'class="gh-account-menu"'), 'User account dropdown menu is present');
$assert(str_contains($body, 'client-workspace.css'), 'Links client-workspace.css');

// 4 Dashboard Statistics Cards
$assert(str_contains($body, 'Total Jobs'), 'Renders Total Jobs label');
$assert(str_contains($body, 'Open Jobs'), 'Renders Open Jobs label');
$assert(str_contains($body, 'Active Projects'), 'Renders Active Projects label');
$assert(str_contains($body, 'Pending Reviews'), 'Renders Pending Reviews label');

// Quick Actions
$assert(str_contains($body, 'href="?route=/client/jobs/create"'), 'Quick action links to Post a Job');
$assert(str_contains($body, 'href="?route=/client/jobs"'), 'Quick action links to My Jobs');
$assert(str_contains($body, 'href="?route=/client/wallet"'), 'Quick action links to My Wallet');

// Recent Jobs & Contextual Actions
$assert(str_contains($body, 'class="gh-client-job-card"'), 'Renders compact gh-client-job-card elements');
$assert(str_contains($body, 'class="gh-client-job-meta"'), 'Renders compact metadata row');
$assert(str_contains($body, 'gh-button-compact'), 'Renders compact action buttons');
$assert(str_contains($body, 'class="gh-stat-icon-wrap"'), 'Renders mint icon wrapper on statistics cards');
$assert(str_contains($body, 'Open API Development'), 'Displays Job 11 title');
$assert(str_contains($body, '?route=/client/jobs/bids&amp;id=11'), 'Open job has Review Bids link with correct &id= param');
$assert(str_contains($body, 'Cancelled Old Project'), 'Displays Job 15 title');
$assert(str_contains($body, '?route=/client/jobs/show&amp;id=15'), 'Cancelled job has View Details link');

// 4. Empty State Verification
$emptyRepo = new class($sqlite) extends ClientWorkspaceRepository {
    public function getStatistics(int $clientId): array {
        return ['total_jobs' => 0, 'open_jobs' => 0, 'active_projects' => 0, 'pending_reviews' => 0];
    }
    public function getRecentJobs(int $clientId, int $limit = 5): array {
        return [];
    }
};
$emptyController = new ClientWorkspaceController($authGuard, static fn() => $emptyRepo);
$emptyRes = $emptyController->index();
$assert(str_contains($emptyRes['body'], 'No jobs posted yet'), 'Empty state header rendered when client has 0 jobs');
$assert(str_contains($emptyRes['body'], '+ Post Your First Job'), 'Empty state CTA button rendered');

// 5. Output Escaping Verification
$xssClientRow = ['id' => 1, 'email' => 'sarah@demo.test', 'role' => 'client', 'display_name' => '<script>alert("xss")</script>', 'status' => 'active', 'email_verified_at' => '2026-01-01'];
$usersRepo->row = $xssClientRow;
$xssRes = $controller->index();
$assert(!str_contains($xssRes['body'], '<script>alert("xss")</script>'), 'Display name is safely escaped against XSS');
$assert(str_contains($xssRes['body'], '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'), 'HTML entities properly escaped');

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n=======================================================\n";
echo " Results: {$testCount} run, {$failCount} failed\n";
echo "=======================================================\n";

if ($failCount > 0) {
    exit(1);
}
