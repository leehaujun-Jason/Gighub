<?php
declare(strict_types=1);

/**
 * GigHub - Browse Jobs UI/UX Redesign Offline Verification Suite
 * Tests sort options, count queries, budget validation, client-name projection,
 * and role-based card rendering (Guest, Client, Freelancer with/without bids).
 * Completely offline: uses PDO doubles and mock factories, zero database writes.
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

ini_set('display_errors', '1');
ini_set('log_errors', '0');

set_exception_handler(static function (Throwable $e): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo 'FAIL: Browse redesign offline assertion: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    exit(1);
});

$root = dirname(__DIR__);
$passCount = 0;

$assert = static function (bool $condition, string $label) use (&$passCount): void {
    if (!$condition) {
        throw new RuntimeException("Assertion failed: [{$label}]");
    }
    ++$passCount;
};

require_once $root . '/app/Services/JobBrowseValidator.php';
require_once $root . '/app/Repositories/PublicJobRepository.php';
require_once $root . '/app/Repositories/SkillRepository.php';
require_once $root . '/app/Controllers/PublicJobController.php';
require_once $root . '/app/Services/MatchingService.php';
require_once $root . '/app/Repositories/JobMatchingRepository.php';
require_once $root . '/app/Core/AuthSession.php';
require_once $root . '/app/Core/PublicIdentity.php';
require_once $root . '/app/Core/Csrf.php';

use GigHub\Services\JobBrowseValidator as V;
use GigHub\Repositories\PublicJobRepository;
use GigHub\Repositories\SkillRepository;
use GigHub\Controllers\PublicJobController;
use GigHub\Services\MatchingService;
use GigHub\Repositories\JobMatchingRepository;
use GigHub\Core\AuthSession;
use GigHub\Core\PublicIdentity;

echo "Starting Browse Jobs UI/UX Redesign Offline Test Suite...\n\n";

// =========================================================================
// 1. Validator Tests (Sort Options, Budget Bounds, Search Limits)
// =========================================================================
echo "1. Testing JobBrowseValidator...\n";

// All 4 sort options allowed
foreach (['newest', 'oldest', 'budget_high', 'budget_low'] as $sort) {
    $res = V::validate(['sort' => $sort]);
    $assert($res['errors'] === [] && $res['data']['sort'] === $sort, "Sort option allowed: {$sort}");
}

// Invalid sort options rejected
foreach (['latest', 'random', 'id_asc', ''] as $badSort) {
    $res = V::validate(['sort' => $badSort]);
    $assert(isset($res['errors']['sort']), "Invalid sort rejected: {$badSort}");
}

// Budget validation: optional fields, numerical-only, up to 2 decimal places, RM100 min, RM100,000,000 max, range bounds

// 1. Non-numeric input rejected
$assert(V::validate(['min_budget' => 'abc'])['errors']['min_budget'] === 'Minimum budget must be at least RM100.', "Non-numeric rejected for min_budget");
$assert(V::validate(['max_budget' => 'xyz'])['errors']['max_budget'] === 'Maximum budget cannot exceed RM100,000,000.', "Non-numeric rejected for max_budget");
$assert(V::validate(['min_budget' => '$100'])['errors']['min_budget'] === 'Minimum budget must be at least RM100.', "Currency symbols rejected for min_budget");

// 2. Negative input rejected
$assert(V::validate(['min_budget' => '-100'])['errors']['min_budget'] === 'Minimum budget must be at least RM100.', "Negative rejected for min_budget");
$assert(V::validate(['max_budget' => '-50'])['errors']['max_budget'] === 'Maximum budget cannot exceed RM100,000,000.', "Negative rejected for max_budget");

// 3. Minimum below RM100 rejected
$assert(V::validate(['min_budget' => '0'])['errors']['min_budget'] === 'Minimum budget must be at least RM100.', "Zero rejected for min_budget");
$assert(V::validate(['min_budget' => '0.00'])['errors']['min_budget'] === 'Minimum budget must be at least RM100.', "0.00 rejected for min_budget");
$assert(V::validate(['min_budget' => '99.99'])['errors']['min_budget'] === 'Minimum budget must be at least RM100.', "RM99.99 rejected for min_budget");

// 4. Minimum exactly RM100 accepted
$resMin100 = V::validate(['min_budget' => '100']);
$assert($resMin100['errors'] === [] && $resMin100['data']['min_budget'] === '100.00', "Integer 100 accepted as min_budget");
$resMin100Dec = V::validate(['min_budget' => '100.00']);
$assert($resMin100Dec['errors'] === [] && $resMin100Dec['data']['min_budget'] === '100.00', "100.00 accepted as min_budget");

// 5. Maximum exactly RM100,000,000 accepted
$resMax100M = V::validate(['max_budget' => '100000000']);
$assert($resMax100M['errors'] === [] && $resMax100M['data']['max_budget'] === '100000000.00', "Integer 100,000,000 accepted as max_budget");
$resMax100MDec = V::validate(['max_budget' => '100000000.00']);
$assert($resMax100MDec['errors'] === [] && $resMax100MDec['data']['max_budget'] === '100000000.00', "100,000,000.00 accepted as max_budget");

// 6. Maximum above RM100,000,000 rejected
$assert(V::validate(['max_budget' => '100000000.01'])['errors']['max_budget'] === 'Maximum budget cannot exceed RM100,000,000.', "100,000,000.01 rejected for max_budget");
$assert(V::validate(['max_budget' => '100000001'])['errors']['max_budget'] === 'Maximum budget cannot exceed RM100,000,000.', "100,000,001 rejected for max_budget");

// 7. Two-decimal-place validation enforced
$assert(V::validate(['min_budget' => '100.555'])['errors']['min_budget'] === 'Minimum budget must be at least RM100.', "Three decimals rejected for min_budget");
$assert(V::validate(['max_budget' => '500.999'])['errors']['max_budget'] === 'Maximum budget cannot exceed RM100,000,000.', "Three decimals rejected for max_budget");
$assert(V::validate(['min_budget' => '100.5'])['errors'] === [] && V::validate(['min_budget' => '100.5'])['data']['min_budget'] === '100.50', "One decimal normalized to two");

// 8. Minimum greater than maximum rejected
$resRev = V::validate(['min_budget' => '500.01', 'max_budget' => '500.00']);
$assert($resRev['errors']['max_budget'] === 'Minimum budget cannot exceed maximum budget.', "Reversed range produces exact error: 'Minimum budget cannot exceed maximum budget.'");
$assert(V::validate(['min_budget' => '1000', 'max_budget' => '500'])['errors']['max_budget'] === 'Minimum budget cannot exceed maximum budget.', "Min > Max produces exact error");

// 9. Empty optional fields accepted
$resEmpty = V::validate(['min_budget' => '', 'max_budget' => '']);
$assert($resEmpty['errors'] === [] && $resEmpty['data']['min_budget'] === '' && $resEmpty['data']['max_budget'] === '', "Empty optional budget fields accepted");

// 10. Valid budget filtering accepted
$resValid = V::validate(['min_budget' => '100.00', 'max_budget' => '100000000.00']);
$assert($resValid['errors'] === [] && $resValid['data']['min_budget'] === '100.00' && $resValid['data']['max_budget'] === '100000000.00', "Full valid range 100 to 100M accepted");
$resEqual = V::validate(['min_budget' => '250.00', 'max_budget' => '250.00']);
$assert($resEqual['errors'] === [] && $resEqual['data']['min_budget'] === '250.00' && $resEqual['data']['max_budget'] === '250.00', "Equal min and max budget accepted");

// Search query: max 100 characters enforced
$assert(V::validate(['q' => str_repeat('a', 100)])['errors'] === [], "100-char query accepted");
$assert(isset(V::validate(['q' => str_repeat('a', 101)])['errors']['q']), "101-char query rejected");

echo "PASS: Validator tests passed.\n\n";

// =========================================================================
// 2. Repository SQL Projections & Ordering Tests
// =========================================================================
echo "2. Testing PublicJobRepository Projections & Ordering...\n";

class TestStatementDouble extends PDOStatement {
    public array $bound = [];
    public function __construct(public array $rows = []) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        $this->bound[$param] = [$value, $type];
        return true;
    }
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed {
        return array_shift($this->rows) ?? false;
    }
    public function fetchColumn(int $column = 0): mixed {
        $r = array_shift($this->rows);
        return is_array($r) ? reset($r) : $r;
    }
}

class TestPdoDouble extends PDO {
    public array $queries = [];
    public array $queue = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $stmt = new TestStatementDouble(array_shift($this->queue) ?? []);
        $this->queries[] = [$query, $stmt];
        return $stmt;
    }
}

$pdo = new TestPdoDouble();
$repo = new PublicJobRepository($pdo);

// Test all 4 sort ordering clauses
$expectedSortOrders = [
    'newest'      => 'j.created_at DESC,j.id DESC',
    'oldest'      => 'j.created_at ASC,j.id ASC',
    'budget_high' => 'j.budget DESC,j.id DESC',
    'budget_low'  => 'j.budget ASC,j.id ASC',
];

foreach ($expectedSortOrders as $sortKey => $expectedSqlOrder) {
    $pdo->queries = [];
    $pdo->queue = [[], []];
    $repo->browse(V::validate(['sort' => $sortKey])['data']);
    $sql = $pdo->queries[0][0];
    $assert(str_contains($sql, $expectedSqlOrder), "Repository order clause for '{$sortKey}' matches {$expectedSqlOrder}");
}

// Test client name projection in SELECT
$pdo->queries = [];
$pdo->queue = [[], []];
$repo->browse(V::validate([])['data']);
$selectSql = $pdo->queries[0][0];
$assert(str_contains($selectSql, 'u.display_name AS client_name'), "Public projection includes client_name");
$assert(!str_contains($selectSql, 'password_hash'), "Private fields excluded");
$assert(!str_contains($selectSql, 'email,'), "User emails excluded");

// Test count() method
$pdo->queries = [];
$pdo->queue = [[[13]]];
$countResult = $repo->count(V::validate(['q' => 'portal', 'min_budget' => '1000'])['data']);
$countSql = $pdo->queries[0][0];
$assert(str_starts_with($countSql, 'SELECT COUNT(j.id) FROM jobs j'), "Count query uses COUNT(j.id)");
$assert(str_contains($countSql, "j.status='open' AND u.role='client'"), "Count query uses identical VISIBLE scope");
$assert(str_contains($countSql, 'j.budget >= ?'), "Count query uses identical budget clause");
$assert($countResult === 13, "Count method returns integer total");

// Test Relevance-Aware ORDER BY and parameter binding order
$pdo->queries = [];
$pdo->queue = [[], []];
$repo->browse(V::validate(['q' => 'portal', 'sort' => 'newest'])['data']);
$qSql = $pdo->queries[0][0];
$qStmt = $pdo->queries[0][1];
$assert(str_contains($qSql, "(CASE WHEN j.title LIKE ? ESCAPE '!' THEN 1 WHEN j.title LIKE ? ESCAPE '!' THEN 2 WHEN j.description LIKE ? ESCAPE '!' THEN 3 ELSE 4 END) ASC, j.created_at DESC,j.id DESC"), "Relevance CASE clause generated for non-empty search");

// Check parameter binding order: WHERE (1, 2), ORDER BY (3, 4, 5), OFFSET (6)
$assert($qStmt->bound[1][0] === '%portal%', "Param 1 is WHERE title LIKE '%portal%'");
$assert($qStmt->bound[2][0] === '%portal%', "Param 2 is WHERE description LIKE '%portal%'");
$assert($qStmt->bound[3][0] === 'portal%', "Param 3 is ORDER BY prefix 'portal%'");
$assert($qStmt->bound[4][0] === '%portal%', "Param 4 is ORDER BY title substring '%portal%'");
$assert($qStmt->bound[5][0] === '%portal%', "Param 5 is ORDER BY description substring '%portal%'");
$assert($qStmt->bound[6][0] === 0 && $qStmt->bound[6][1] === PDO::PARAM_INT, "Param 6 is integer OFFSET 0");

// Test with budget and skills to verify WHERE parameters come strictly before ORDER BY parameters
$pdo->queries = [];
$pdo->queue = [[], []];
$repo->browse(V::validate(['q' => 'portal', 'min_budget' => '100.00', 'skills' => [1, 2]])['data']);
$complexStmt = $pdo->queries[0][1];
$assert($complexStmt->bound[1][0] === '%portal%', "Complex param 1 is WHERE q");
$assert($complexStmt->bound[2][0] === '%portal%', "Complex param 2 is WHERE q");
$assert($complexStmt->bound[3][0] === '100.00', "Complex param 3 is WHERE min_budget");
$assert($complexStmt->bound[4][0] === 1, "Complex param 4 is WHERE skill 1");
$assert($complexStmt->bound[5][0] === 2, "Complex param 5 is WHERE skill 2");
$assert($complexStmt->bound[6][0] === 'portal%', "Complex param 6 is ORDER BY relevance tier 1");
$assert($complexStmt->bound[7][0] === '%portal%', "Complex param 7 is ORDER BY relevance tier 2");
$assert($complexStmt->bound[8][0] === '%portal%', "Complex param 8 is ORDER BY relevance tier 3");
$assert($complexStmt->bound[9][0] === 0, "Complex param 9 is OFFSET");

echo "PASS: Repository SQL projection and ordering tests passed.\n\n";

// =========================================================================
// 3. Controller & View Role-Based Rendering Tests
// =========================================================================
echo "3. Testing PublicJobController Role-Based Rendering...\n";

class TestPublicBrowseRepo extends PublicJobRepository {
    public function __construct(public array $testJobs = []) {}
    public function count(array $filters): int { return count($this->testJobs); }
    public function browse(array $filters): array { return $this->testJobs; }
    public function find(int $id): array|false { return $this->testJobs[0] ?? false; }
}

class TestSkillsCatalogue extends SkillRepository {
    public function __construct(public array $catalogueSkills = []) {}
    public function all(): array { return $this->catalogueSkills; }
    public function existingIds(array $ids): array {
        $valid = array_column($this->catalogueSkills, 'id');
        return array_values(array_intersect($ids, $valid));
    }
}

class TestMatchingRepo extends JobMatchingRepository {
    public function __construct(
        public array $freelancerSkills = [],
        public array $bidMap = []
    ) {}
    public function isEligibleFreelancer(int $userId): bool { return true; }
    public function getFreelancerSkills(int $freelancerId): array { return $this->freelancerSkills; }
    public function getFreelancerBidStatusMap(int $freelancerId): array { return $this->bidMap; }
}

$sampleJob = [
    'id' => 3,
    'title' => 'Full-Stack Web Portal & UI System',
    'description' => 'Build a high-performance web portal with clean components.',
    'budget' => '6500.00',
    'deadline' => '2027-02-28',
    'created_at' => '2026-11-06 10:00:00',
    'client_name' => 'Sarah Tan',
    'skills' => [
        ['id' => 1, 'name' => 'HTML'],
        ['id' => 2, 'name' => 'CSS'],
        ['id' => 4, 'name' => 'PHP'],
    ],
];

$allCatalogueSkills = [
    ['id' => 1, 'name' => 'HTML'],
    ['id' => 2, 'name' => 'CSS'],
    ['id' => 3, 'name' => 'JavaScript'],
    ['id' => 4, 'name' => 'PHP'],
    ['id' => 5, 'name' => 'MySQL'],
];

// 3A. Guest User Rendering
echo "  3A. Testing Guest view...\n";
$_SESSION = [];
$now = time();
$authSession = new AuthSession(static fn() => $now);
$guestRepo = new TestPublicBrowseRepo([$sampleJob]);
$guestSkills = new TestSkillsCatalogue($allCatalogueSkills);

$guestCtrl = new PublicJobController(
    static fn() => [$guestRepo, $guestSkills],
    static fn() => new DateTimeImmutable('2026-11-06', new DateTimeZone('Asia/Kuala_Lumpur')),
    null
);

$guestRes = $guestCtrl->index([]);
$guestHtml = $guestRes['body'];

$assert($guestRes['status'] === 200, "Guest index returns HTTP 200");
$assert(str_contains($guestHtml, 'Posted by Sarah Tan'), "Guest view shows client name");
$assert(str_contains($guestHtml, 'gh-guest-bid-btn'), "Guest view renders guest bid button");
$assert(str_contains($guestHtml, 'id="gh-guest-login-modal"'), "Guest login modal dialog exists in DOM");
$assert(str_contains($guestHtml, 'Please sign in as a freelancer to view bids or submit a bid.'), "Guest modal contains exact approved prompt message");
$assert(!str_contains($guestHtml, 'gh-match-pill'), "Guest view omits freelancer match pill");
$assert(!str_contains($guestHtml, 'gh-skill-tag--matched'), "Guest view omits freelancer skill match highlights");
$assert(str_contains($guestHtml, 'id="gh-filter-modal"'), "Filter modal dialog exists in DOM");
$assert(str_contains($guestHtml, 'id="gh-sort-menu"'), "Sort menu exists in DOM");
$assert(str_contains($guestHtml, '1 job found'), "Header stats bar shows '1 job found'");

// 3B. Authenticated Client User Rendering
echo "  3B. Testing Client view...\n";
$_SESSION['auth'] = ['user_id' => 6, 'issued_at' => $now, 'last_activity' => $now];
$clientIdentity = new PublicIdentity($authSession, static fn(int $id) => [
    'id' => 6, 'email' => 'client@demo.test', 'role' => 'client', 'display_name' => 'Client Jason'
]);

$clientCtrl = new PublicJobController(
    static fn() => [$guestRepo, $guestSkills],
    static fn() => new DateTimeImmutable('2026-11-06', new DateTimeZone('Asia/Kuala_Lumpur')),
    $clientIdentity
);

$clientRes = $clientCtrl->index([]);
$clientHtml = $clientRes['body'];

$assert(str_contains($clientHtml, 'View Job Details'), "Client view renders 'View Job Details' button");
$assert(!str_contains($clientHtml, 'gh-guest-bid-btn'), "Client view does NOT render guest prompt button");
$assert(!str_contains($clientHtml, 'gh-match-pill'), "Client view omits freelancer match pill");
$assert(!str_contains($clientHtml, 'gh-skill-tag--matched'), "Client view omits skill match highlights");

// 3C. Authenticated Freelancer User Rendering (No Bid)
echo "  3C. Testing Freelancer view (no bid placed)...\n";
$_SESSION['auth'] = ['user_id' => 116, 'issued_at' => $now, 'last_activity' => $now];
$freelancerIdentity = new PublicIdentity($authSession, static fn(int $id) => [
    'id' => 116, 'email' => 'jason01@demo.test', 'role' => 'freelancer', 'display_name' => 'Jason01'
]);

$matchingRepo = new TestMatchingRepo(
    [['id' => 1, 'name' => 'HTML'], ['id' => 2, 'name' => 'CSS'], ['id' => 4, 'name' => 'PHP']], // 100% match
    [] // No bids
);
$matchingService = new MatchingService();

$freelancerCtrl = new PublicJobController(
    static fn() => [$guestRepo, $guestSkills],
    static fn() => new DateTimeImmutable('2026-11-06', new DateTimeZone('Asia/Kuala_Lumpur')),
    $freelancerIdentity,
    static fn() => [$matchingRepo, $matchingService]
);

$freeRes = $freelancerCtrl->index([]);
$freeHtml = $freeRes['body'];

$assert(str_contains($freeHtml, '100.0% Match'), "Freelancer view displays 100.0% Match pill");
$assert(str_contains($freeHtml, 'gh-match-pill--high'), "High match tier class applied");
$assert(str_contains($freeHtml, 'gh-skill-tag--matched'), "Matched skills are highlighted");
$assert(str_contains($freeHtml, '✓ HTML'), "Checkmark rendered on matched skill");
$assert(str_contains($freeHtml, 'View Job &amp; Submit Bid'), "Freelancer sees active 'View Job & Submit Bid' button");

// 3D. Authenticated Freelancer User Rendering (With Bid Placed)
echo "  3D. Testing Freelancer view (bid placed)...\n";
$matchingRepoWithBid = new TestMatchingRepo(
    [['id' => 1, 'name' => 'HTML'], ['id' => 2, 'name' => 'CSS'], ['id' => 4, 'name' => 'PHP']],
    [3 => 'pending'] // Bid on Job 3
);

$freelancerBidCtrl = new PublicJobController(
    static fn() => [$guestRepo, $guestSkills],
    static fn() => new DateTimeImmutable('2026-11-06', new DateTimeZone('Asia/Kuala_Lumpur')),
    $freelancerIdentity,
    static fn() => [$matchingRepoWithBid, $matchingService]
);

$freeBidRes = $freelancerBidCtrl->index([]);
$freeBidHtml = $freeBidRes['body'];

$assert(str_contains($freeBidHtml, '[Bid Placed: Pending]'), "Freelancer sees '[Bid Placed: Pending]' badge");
$assert(str_contains($freeBidHtml, '>View Job</a>'), "Freelancer sees secondary 'View Job' button");
$assert(!str_contains($freeBidHtml, 'View Job &amp; Submit Bid'), "'Submit Bid' omitted when bid already exists");

echo "PASS: Controller and view role-based rendering tests passed.\n\n";

// =========================================================================
// 4. Modal and Toolbar Markup Checks
// =========================================================================
echo "4. Testing Modal and Toolbar Markup Integrity...\n";
$assert(str_contains($guestHtml, 'id="modal-min-budget"'), "Filter modal contains min_budget input");
$assert(str_contains($guestHtml, 'id="modal-max-budget"'), "Filter modal contains max_budget input");
$assert(str_contains($guestHtml, 'name="modal_skills[]"'), "Filter modal contains skill checkboxes");
$assert(str_contains($guestHtml, 'id="gh-filter-clear-all-btn"'), "Filter modal contains Clear all button");
$assert(str_contains($guestHtml, 'id="gh-filter-apply-btn"'), "Filter modal contains Apply Filters button");
$assert(str_contains($guestHtml, 'id="gh-filter-cancel-btn"'), "Filter modal contains Cancel button");
$assert(str_contains($guestHtml, 'id="gh-sort-btn"'), "Toolbar contains Sort button");
$assert(str_contains($guestHtml, 'id="gh-sort-menu"'), "Toolbar contains Sort dropdown menu");
$assert(str_contains($guestHtml, 'value="newest"'), "Sort menu has 'newest'");
$assert(str_contains($guestHtml, 'value="oldest"'), "Sort menu has 'oldest'");
$assert(str_contains($guestHtml, 'value="budget_high"'), "Sort menu has 'budget_high'");
$assert(str_contains($guestHtml, 'value="budget_low"'), "Sort menu has 'budget_low'");

echo "PASS: Modal and toolbar markup tests passed.\n\n";

// =========================================================================
// 5. Shared UI Consistency & Dropdown Markup Checks
// =========================================================================
echo "5. Testing Shared UI Consistency & Dropdown Markup...\n";

// Verify registration.css is removed to avoid button style pollution
$assert(!str_contains($guestHtml, 'registration.css'), "registration.css is excluded from Browse Jobs view");

// Verify visible budget hint text is removed
$assert(!str_contains($guestHtml, 'id="gh-modal-budget-hint"'), "Visible budget hint text #gh-modal-budget-hint is removed");

// Verify global navigation.js is included in header
$assert(str_contains($guestHtml, 'assets/navigation.js'), "navigation.js is included via header.php");

// Verify Client Account Dropdown structure
$assert(str_contains($clientHtml, 'gh-account-name-header'), "Account panel includes gh-account-name-header");
$assert(str_contains($clientHtml, 'Client Jason'), "Account panel displays Client user display name");
$assert(str_contains($clientHtml, 'gh-account-role-badge'), "Account panel includes role badge");
$assert(str_contains($clientHtml, 'Client'), "Account panel displays Client role");
$assert(str_contains($clientHtml, 'href="?route=/client/jobs"'), "Client menu includes My Jobs link");
$assert(str_contains($clientHtml, 'href="?route=/client/wallet"'), "Client menu includes My Wallet link");
$assert(str_contains($clientHtml, 'class="gh-account-item gh-account-signout"'), "Account menu includes Sign out item");

// Verify Freelancer Account Dropdown structure
$assert(str_contains($freeHtml, 'Jason01'), "Account panel displays Freelancer user display name");
$assert(str_contains($freeHtml, 'Freelancer'), "Account panel displays Freelancer role");
$assert(str_contains($freeHtml, 'href="?route=/freelancer/profile"'), "Freelancer menu includes My Profile link");
$assert(str_contains($freeHtml, 'href="?route=/freelancer/wallet"'), "Freelancer menu includes My Wallet link");
$assert(str_contains($freeHtml, 'class="gh-account-item gh-account-signout"'), "Freelancer menu includes Sign out item");

echo "PASS: Shared UI consistency and dropdown markup tests passed.\n\n";

echo "=========================================================================\n";
echo "All {$passCount} Browse Jobs UI/UX Redesign Offline Assertions PASSED!\n";
echo "=========================================================================\n";
