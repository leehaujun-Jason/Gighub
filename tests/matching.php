<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
foreach ([
    'Core/Csrf',
    'Core/Notification',
    'Core/AuthSession',
    'Core/AuthGuard',
    'Core/PublicIdentity',
    'Core/Router',
    'Repositories/UserRepository',
    'Services/LoginValidator',
    'Services/AuthenticationService',
    'Services/MatchingService',
    'Repositories/JobMatchingRepository',
    'Controllers/FreelancerWorkspaceController',
    'Repositories/PublicJobRepository',
    'Repositories/SkillRepository',
    'Services/JobBrowseValidator',
    'Controllers/PublicJobController',
    'Repositories/FreelancerProfileRepository',
    'Services/FreelancerProfileException',
    'Services/FreelancerProfileValidator',
    'Services/FreelancerProfileService',
    'Controllers/FreelancerProfileController',
] as $class) {
    require_once $root . '/app/' . $class . '.php';
}

use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Core\PublicIdentity;
use GigHub\Repositories\UserRepository;
use GigHub\Services\AuthenticationService;
use GigHub\Services\MatchingService;
use GigHub\Repositories\JobMatchingRepository;
use GigHub\Controllers\FreelancerWorkspaceController;
use GigHub\Repositories\PublicJobRepository;
use GigHub\Repositories\SkillRepository;
use GigHub\Controllers\PublicJobController;
use GigHub\Repositories\FreelancerProfileRepository;
use GigHub\Services\FreelancerProfileException;
use GigHub\Services\FreelancerProfileValidator;
use GigHub\Services\FreelancerProfileService;
use GigHub\Controllers\FreelancerProfileController;

$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    ++$count;
}

echo "Starting Module 18 Skill-Based Matching Engine Offline Test Suite...\n";

// =====================================================================
// 1. Pure Jaccard Similarity Calculations
// =====================================================================
$matchingService = new MatchingService();

// Exact match: 2/2 = 100.0%
$res100 = $matchingService->computeJaccard([1, 2], [1, 2]);
check($res100['score'] === 1.0, 'Exact match score is 1.0');
check($res100['percentage'] === '100.0%', 'Exact match percentage is 100.0%');
check($res100['percentage_value'] === 100.0, 'Exact match percentage_value is 100.0');
check($res100['intersection_count'] === 2, 'Exact match intersection count is 2');
check($res100['union_count'] === 2, 'Exact match union count is 2');
check($res100['meets_threshold'] === true, 'Exact match meets 20% threshold');
check($res100['intersection_ids'] === [1, 2], 'Exact match intersection IDs');

// Partial match: 2/3 = 66.7%
$res66 = $matchingService->computeJaccard([1, 2], [1, 2, 3]);
check(abs($res66['score'] - (2/3)) < 1e-9, '2/3 score calculation');
check($res66['percentage'] === '66.7%', '2/3 formatted to 1 decimal place: 66.7%');
check($res66['percentage_value'] === 66.7, '2/3 percentage value is 66.7');
check($res66['intersection_count'] === 2, '2/3 intersection count is 2');
check($res66['union_count'] === 3, '2/3 union count is 3');
check($res66['meets_threshold'] === true, '2/3 meets threshold');

// Partial match: 1/3 = 33.3%
$res33 = $matchingService->computeJaccard([1], [1, 2, 3]);
check(abs($res33['score'] - (1/3)) < 1e-9, '1/3 score calculation');
check($res33['percentage'] === '33.3%', '1/3 formatted to 1 decimal place: 33.3%');
check($res33['meets_threshold'] === true, '1/3 meets threshold');

// Partial match: 2/4 = 50.0%
$res50 = $matchingService->computeJaccard([1, 2], [1, 2, 3, 4]);
check($res50['score'] === 0.5, '2/4 score is 0.5');
check($res50['percentage'] === '50.0%', '2/4 percentage is 50.0%');
check($res50['meets_threshold'] === true, '2/4 meets threshold');

// Exact 20% boundary: 1/5 = 20.0%
$res20 = $matchingService->computeJaccard([1], [1, 2, 3, 4, 5]);
check($res20['score'] === 0.2, '1/5 score is exactly 0.2');
check($res20['percentage'] === '20.0%', '1/5 percentage is 20.0%');
check($res20['meets_threshold'] === true, '1/5 meets 20% threshold exactly');

// Sub-threshold: 1/6 = 16.7%
$res16 = $matchingService->computeJaccard([1], [1, 2, 3, 4, 5, 6]);
check(abs($res16['score'] - (1/6)) < 1e-9, '1/6 score calculation');
check($res16['percentage'] === '16.7%', '1/6 formatted to 16.7%');
check($res16['meets_threshold'] === false, '1/6 excluded by 20% threshold');

// Zero overlap: 0/4 = 0.0%
$res0 = $matchingService->computeJaccard([1, 2], [3, 4]);
check($res0['score'] === 0.0, 'Disjoint sets score 0.0');
check($res0['percentage'] === '0.0%', 'Disjoint sets percentage 0.0%');
check($res0['intersection_count'] === 0, 'Disjoint sets intersection count 0');
check($res0['union_count'] === 4, 'Disjoint sets union count 4');
check($res0['meets_threshold'] === false, 'Disjoint sets do not meet threshold');

// Empty freelancer skills
$resEmptyF = $matchingService->computeJaccard([], [1, 2]);
check($resEmptyF['score'] === 0.0, 'Empty freelancer skills score 0.0');
check($resEmptyF['meets_threshold'] === false, 'Empty freelancer skills does not meet threshold');

// Empty job skills
$resEmptyJ = $matchingService->computeJaccard([1, 2], []);
check($resEmptyJ['score'] === 0.0, 'Empty job skills score 0.0');
check($resEmptyJ['meets_threshold'] === false, 'Empty job skills does not meet threshold');

// Both empty
$resEmptyBoth = $matchingService->computeJaccard([], []);
check($resEmptyBoth['score'] === 0.0, 'Both empty skills score 0.0');
check($resEmptyBoth['meets_threshold'] === false, 'Both empty does not meet threshold');
check($resEmptyBoth['union_count'] === 0, 'Both empty union count 0');

// Duplicate skill IDs and string inputs
$resDedup = $matchingService->computeJaccard(['1', '1', 2, '2'], [2, 1, 1, '2']);
check($resDedup['score'] === 1.0, 'Duplicates properly deduplicated into sets');
check($resDedup['percentage'] === '100.0%', 'Deduplicated sets match 100.0%');
check($resDedup['union_count'] === 2, 'Union count is 2 distinct IDs');
check($resDedup['intersection_count'] === 2, 'Intersection count is 2 distinct IDs');

// =====================================================================
// 2. Recommendation Ranking & Sorting
// =====================================================================
$freelancerSkillIds = [1, 2]; // e.g. PHP (1), MySQL (2)

$sampleJobs = [
    [
        'id' => 101,
        'title' => 'Tied Score Older Job',
        'created_at' => '2026-03-01 10:00:00',
        'skills' => [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'MySQL'], ['id' => 3, 'name' => 'Docker']], // 2/3 = 66.7%
    ],
    [
        'id' => 102,
        'title' => 'Tied Score Newer Job',
        'created_at' => '2026-03-05 10:00:00',
        'skills' => [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'MySQL'], ['id' => 4, 'name' => 'Redis']], // 2/3 = 66.7%
    ],
    [
        'id' => 103,
        'title' => 'Perfect Match Job',
        'created_at' => '2026-02-01 10:00:00',
        'skills' => [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'MySQL']], // 2/2 = 100.0%
    ],
    [
        'id' => 104,
        'title' => 'Tied Score Same Timestamp Lower ID',
        'created_at' => '2026-03-05 10:00:00',
        'skills' => [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'MySQL'], ['id' => 5, 'name' => 'AWS']], // 2/3 = 66.7%
    ],
    [
        'id' => 105,
        'title' => 'Sub Threshold Job',
        'created_at' => '2026-03-10 10:00:00',
        'skills' => [
            ['id' => 1, 'name' => 'PHP'],
            ['id' => 6, 'name' => 'Java'],
            ['id' => 7, 'name' => 'Spring'],
            ['id' => 8, 'name' => 'Kotlin'],
            ['id' => 9, 'name' => 'Gradle'],
            ['id' => 10, 'name' => 'Android'],
            ['id' => 11, 'name' => 'C++'],
        ], // 1/8 = 12.5% (< 20%)
    ],
    [
        'id' => 106,
        'title' => 'Exact 20% Threshold Job',
        'created_at' => '2026-03-02 10:00:00',
        'skills' => [
            ['id' => 1, 'name' => 'PHP'],
            ['id' => 6, 'name' => 'Java'],
            ['id' => 7, 'name' => 'Spring'],
            ['id' => 8, 'name' => 'Kotlin'],
            ['id' => 9, 'name' => 'Gradle'],
        ], // 1/6 union with [1,2] -> union {1,2,6,7,8,9} = 6 items. Intersect {1}=1 -> 1/6 = 16.7%.
           // Let's make union 5 items: freelancer [1], job [1, 6, 7, 8, 9] -> inter 1, union 5 = 20.0%.
           // But here freelancer is [1, 2]. So if job has [1, 6, 7, 8], union is {1, 2, 6, 7, 8} = 5 items!
           // Intersect is {1} = 1 item. 1/5 = 20.0%!
    ],
];

// Adjust job 106 skills so union with [1, 2] is exactly 5 items: [1, 6, 7, 8]
$sampleJobs[5]['skills'] = [
    ['id' => 1, 'name' => 'PHP'],
    ['id' => 6, 'name' => 'Java'],
    ['id' => 7, 'name' => 'Spring'],
    ['id' => 8, 'name' => 'Kotlin'],
];

$bidMap = [102 => 'pending', 103 => 'accepted'];

$ranked = $matchingService->rankRecommendations($freelancerSkillIds, $sampleJobs, 10, $bidMap);

// Sub-threshold job 105 should be excluded
$rankedIds = array_column($ranked, 'id');
check(!in_array(105, $rankedIds, true), 'Sub-threshold job 105 excluded from recommendations');
check(in_array(106, $rankedIds, true), 'Exact 20% threshold job 106 included');

// Check order:
// 1st: Job 103 (100.0%)
// 2nd: Between 102, 104, 101 (all 66.7%):
//      102 & 104 have created_at '2026-03-05 10:00:00' (newer than 101's '2026-03-01').
//      Between 102 and 104 (same timestamp): 104 has higher ID (104 > 102).
//      So order must be: 104, then 102, then 101.
// 5th: Job 106 (20.0%)
check($ranked[0]['id'] === 103, '1st recommendation is Job 103 (100% match)');
check($ranked[1]['id'] === 104, '2nd recommendation is Job 104 (tied 66.7%, newer date, higher ID 104 > 102)');
check($ranked[2]['id'] === 102, '3rd recommendation is Job 102 (tied 66.7%, newer date, ID 102)');
check($ranked[3]['id'] === 101, '4th recommendation is Job 101 (tied 66.7%, older date)');
check($ranked[4]['id'] === 106, '5th recommendation is Job 106 (20.0% threshold)');
check(count($ranked) === 5, 'Exactly 5 qualified recommendations');

// Check skill breakdown on Job 101
$job101 = $ranked[3];
check(count($job101['matched_skills']) === 2, 'Job 101 has 2 matched skills');
check(count($job101['unmatched_skills']) === 1, 'Job 101 has 1 unmatched skill');
check($job101['matched_skills'][0]['name'] === 'PHP', 'Job 101 matched skill 1 is PHP');
check($job101['matched_skills'][1]['name'] === 'MySQL', 'Job 101 matched skill 2 is MySQL');
check($job101['unmatched_skills'][0]['name'] === 'Docker', 'Job 101 unmatched skill is Docker');

// Check bid status annotation
check($ranked[0]['bid_status'] === 'accepted', 'Job 103 bid status annotated as accepted');
check($ranked[2]['bid_status'] === 'pending', 'Job 102 bid status annotated as pending');
check($ranked[1]['bid_status'] === null, 'Job 104 bid status is null');

// Truncation limit test: limit to 2
$rankedLimit2 = $matchingService->rankRecommendations($freelancerSkillIds, $sampleJobs, 2, $bidMap);
check(count($rankedLimit2) === 2, 'Limit 2 returns exactly 2 jobs');
check($rankedLimit2[0]['id'] === 103, 'Top 1 is Job 103');
check($rankedLimit2[1]['id'] === 104, 'Top 2 is Job 104');

// Empty inputs for rankRecommendations
check($matchingService->rankRecommendations([], $sampleJobs) === [], 'Empty freelancer skills returns empty array');
check($matchingService->rankRecommendations($freelancerSkillIds, []) === [], 'Empty jobs returns empty array');

// =====================================================================
// 3. Browsed Jobs Enrichment
// =====================================================================
$browsed = [
    [
        'id' => 201,
        'title' => 'Browsed Sub-Threshold Job',
        'skills' => [
            ['id' => 1, 'name' => 'PHP'],
            ['id' => 6, 'name' => 'Java'],
            ['id' => 7, 'name' => 'Spring'],
            ['id' => 8, 'name' => 'Kotlin'],
            ['id' => 9, 'name' => 'Gradle'],
            ['id' => 10, 'name' => 'Android'],
            ['id' => 11, 'name' => 'C++'],
        ], // 12.5%
    ],
    [
        'id' => 202,
        'title' => 'Browsed High Match Job',
        'skills' => [
            ['id' => 1, 'name' => 'PHP'],
            ['id' => 2, 'name' => 'MySQL'],
        ], // 100.0%
    ],
];

$enriched = $matchingService->enrichBrowsedJobs($freelancerSkillIds, $browsed);
check(count($enriched) === 2, 'Browsed enrichment keeps all 2 jobs without filtering');
check($enriched[0]['id'] === 201, 'Order preserved: Job 201 remains first');
check($enriched[1]['id'] === 202, 'Order preserved: Job 202 remains second');

// Sub-threshold job 201 still has match data and is_matched flags
check(isset($enriched[0]['match']), 'Job 201 has match data');
check($enriched[0]['match']['meets_threshold'] === false, 'Job 201 meets_threshold is false');
check($enriched[0]['match']['percentage'] === '12.5%', 'Job 201 percentage is 12.5%');
check($enriched[0]['skills'][0]['is_matched'] === true, 'Job 201 PHP is marked is_matched = true');
check($enriched[0]['skills'][1]['is_matched'] === false, 'Job 201 Java is marked is_matched = false');

// High-match job 202
check($enriched[1]['match']['meets_threshold'] === true, 'Job 202 meets_threshold is true');
check($enriched[1]['match']['percentage'] === '100.0%', 'Job 202 percentage is 100.0%');
check($enriched[1]['skills'][0]['is_matched'] === true, 'Job 202 PHP is_matched = true');
check($enriched[1]['skills'][1]['is_matched'] === true, 'Job 202 MySQL is_matched = true');

// =====================================================================
// 4. JobMatchingRepository Unit Tests (Mock PDO & Statements)
// =====================================================================
class MockMatchingStatement {
    public function __construct(private readonly array $rows, private readonly mixed $fetchColumnVal = null) {}
    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool { return true; }
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return $this->fetchColumnVal; }
}

class MockMatchingPDO extends PDO {
    public array $queries = [];
    public array $mockData = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): object {
        $this->queries[] = $query;
        if (str_contains($query, 'isEligibleFreelancer') || str_contains($query, "FROM users u JOIN freelancer_profiles")) {
            return new MockMatchingStatement([], $this->mockData['eligible'] ?? 42);
        }
        if (str_contains($query, 'FROM skills s JOIN user_skills us')) {
            return new MockMatchingStatement($this->mockData['user_skills'] ?? [
                ['id' => 1, 'name' => 'PHP'],
                ['id' => 2, 'name' => 'MySQL'],
            ]);
        }
        if (str_contains($query, 'FROM bids WHERE freelancer_id')) {
            return new MockMatchingStatement($this->mockData['bids'] ?? [
                ['job_id' => 10, 'status' => 'pending'],
            ]);
        }
        if (str_contains($query, 'FROM jobs j JOIN client_profiles cp')) {
            return new MockMatchingStatement($this->mockData['candidates'] ?? [
                [
                    'id' => 10,
                    'title' => 'Web App Development',
                    'description' => 'Build a PHP MySQL web app.',
                    'budget' => '3500.00',
                    'deadline' => '2026-12-31',
                    'created_at' => '2026-03-01 12:00:00',
                    'client_name' => 'Acme Corp',
                ],
            ]);
        }
        if (str_contains($query, 'FROM job_skills js JOIN skills s')) {
            return new MockMatchingStatement($this->mockData['job_skills'] ?? [
                ['job_id' => 10, 'id' => 1, 'name' => 'PHP'],
                ['job_id' => 10, 'id' => 2, 'name' => 'MySQL'],
            ]);
        }
        return new MockMatchingStatement([]);
    }
}

$mockPdo = new MockMatchingPDO();
$matchingRepo = new JobMatchingRepository($mockPdo);

// Test isEligibleFreelancer
check($matchingRepo->isEligibleFreelancer(42) === true, 'Eligible freelancer returns true');
$mockPdo->mockData['eligible'] = false;
check($matchingRepo->isEligibleFreelancer(99) === false, 'Ineligible freelancer returns false');

// Test getFreelancerSkills
$fSkills = $matchingRepo->getFreelancerSkills(42);
check(count($fSkills) === 2, 'getFreelancerSkills returns 2 skills');
check($fSkills[0]['id'] === 1 && $fSkills[0]['name'] === 'PHP', 'Skill 1 parsed');
check($fSkills[1]['id'] === 2 && $fSkills[1]['name'] === 'MySQL', 'Skill 2 parsed');

// Test getFreelancerBidStatusMap
$bidMap = $matchingRepo->getFreelancerBidStatusMap(42);
check($bidMap === [10 => 'pending'], 'getFreelancerBidStatusMap maps job_id to status');

// Test getCandidateJobsForFreelancer
$candidates = $matchingRepo->getCandidateJobsForFreelancer([1, 2]);
check(count($candidates) === 1, 'Candidate jobs retrieved');
check($candidates[0]['id'] === 10, 'Candidate job ID is 10');
check(count($candidates[0]['skills']) === 2, 'Candidate job has skills populated via batch');

// Verify candidate query structure
$lastCandidateSql = '';
foreach ($mockPdo->queries as $q) {
    if (str_contains($q, 'WHERE j.status = \'open\' AND u.role = \'client\'')) {
        $lastCandidateSql = $q;
    }
}
check(str_contains($lastCandidateSql, "j.status = 'open'"), 'SQL filters open jobs');
check(str_contains($lastCandidateSql, "u.role = 'client'"), 'SQL filters client accounts');
check(str_contains($lastCandidateSql, "u.status = 'active'"), 'SQL filters active clients');
check(str_contains($lastCandidateSql, "u.email_verified_at IS NOT NULL"), 'SQL filters verified clients');
check(str_contains($lastCandidateSql, "EXISTS (SELECT 1 FROM job_skills"), 'SQL uses EXISTS for candidate filtering');

// Test populateJobSkillsBatched chunking (>100 jobs)
$manyJobs = [];
for ($i = 1; $i <= 250; $i++) {
    $manyJobs[] = ['id' => $i, 'title' => "Job $i"];
}
$mockPdo->queries = [];
$populated = $matchingRepo->populateJobSkillsBatched($manyJobs);
$chunkQueries = array_filter($mockPdo->queries, static fn($q) => str_contains($q, 'FROM job_skills js JOIN skills s'));
// 250 jobs in chunks of 100 requires ceil(250/100) = 3 queries
check(count($chunkQueries) === 3, 'Batched skills execution uses 3 bounded queries for 250 jobs');
check(count($populated) === 250, 'All 250 jobs populated');

// =====================================================================
// 5. FreelancerWorkspaceController Tests
// =====================================================================
$mockUserRepo = new class extends UserRepository {
    public ?array $row = null;
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false {
        return $this->row ?? false;
    }
};

$_SESSION = [];
$authSession = new AuthSession(null, static fn() => true);
$authService = new AuthenticationService($mockUserRepo);
$authGuard = new AuthGuard(static fn() => $authService, $authSession);

// Unauthenticated user -> 303
$workspaceCtrl = new FreelancerWorkspaceController($authGuard, static fn() => [$matchingRepo, $matchingService]);
$resUnauth = $workspaceCtrl->index();
check($resUnauth['status'] === 303, 'Unauthenticated user redirected to 303');
check($resUnauth['location'] === '?route=/login', 'Redirect target is /login');

// Client user -> 403 Forbidden
$mockUserRepo->row = [
    'id' => 5,
    'role' => 'client',
    'display_name' => 'Alice Client',
    'status' => 'active',
    'email_verified_at' => '2026-03-01 10:00:00',
];
$authSession->establish(5);
$resClient = $workspaceCtrl->index();
check($resClient['status'] === 403, 'Client role receives 403 Forbidden on freelancer workspace');

// Freelancer user with populated recommendations -> 200 OK
$mockUserRepo->row = [
    'id' => 42,
    'role' => 'freelancer',
    'display_name' => 'Bob Freelancer',
    'status' => 'active',
    'email_verified_at' => '2026-03-01 10:00:00',
];
$authSession->establish(42);

$mockRepoPopulated = new class extends JobMatchingRepository {
    public function __construct() {}
    public function getFreelancerSkills(int $freelancerId): array {
        return [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'MySQL']];
    }
    public function getFreelancerBidStatusMap(int $freelancerId): array {
        return [10 => 'pending'];
    }
    public function getCandidateJobsForFreelancer(array $freelancerSkillIds): array {
        return [
            [
                'id' => 10,
                'title' => 'E-Commerce Platform',
                'description' => 'Build an online store using PHP and MySQL.',
                'budget' => '4500.00',
                'deadline' => '2026-11-30',
                'created_at' => '2026-03-01 10:00:00',
                'client_name' => 'Client Corp',
                'skills' => [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'MySQL'], ['id' => 3, 'name' => 'Docker']],
            ],
        ];
    }
};

$workspaceCtrlPopulated = new FreelancerWorkspaceController(
    $authGuard,
    static fn() => [$mockRepoPopulated, $matchingService]
);
$resPopulated = $workspaceCtrlPopulated->index();
check($resPopulated['status'] === 200, 'Freelancer workspace returns 200 OK');
check(str_contains($resPopulated['body'], 'FREELANCER WORKSPACE'), 'Contains eyebrow header');
check(str_contains($resPopulated['body'], 'Welcome, Bob Freelancer'), 'Contains personalized greeting');
check(str_contains($resPopulated['body'], '66.7% Match'), 'Contains Jaccard match badge 66.7%');
check(str_contains($resPopulated['body'], 'E-Commerce Platform'), 'Contains recommended job title');
check(str_contains($resPopulated['body'], 'Matched Skills (2):'), 'Contains matched skills header');
check(str_contains($resPopulated['body'], '✓ PHP'), 'Contains matched skill ✓ PHP');
check(str_contains($resPopulated['body'], '✓ MySQL'), 'Contains matched skill ✓ MySQL');
check(str_contains($resPopulated['body'], 'Other Required Skills (1):'), 'Contains other required skills header');
check(str_contains($resPopulated['body'], 'Docker'), 'Contains unmatched skill Docker');
check(str_contains($resPopulated['body'], '[Bid Submitted: Pending]'), 'Contains bid status badge');
check(str_contains($resPopulated['body'], '?route=/freelancer/profile/edit'), 'Contains edit profile shortcut');
check(str_contains($resPopulated['body'], '?route=/freelancer/wallet'), 'Contains wallet shortcut');

// Empty State A: Freelancer with 0 skills
$mockRepoNoSkills = new class extends JobMatchingRepository {
    public function __construct() {}
    public function getFreelancerSkills(int $freelancerId): array { return []; }
    public function getFreelancerBidStatusMap(int $freelancerId): array { return []; }
    public function getCandidateJobsForFreelancer(array $freelancerSkillIds): array { return []; }
};

$workspaceCtrlNoSkills = new FreelancerWorkspaceController(
    $authGuard,
    static fn() => [$mockRepoNoSkills, $matchingService]
);
$resNoSkills = $workspaceCtrlNoSkills->index();
check($resNoSkills['status'] === 200, '0-skills workspace returns 200 OK');
check(str_contains($resNoSkills['body'], 'Your Registered Skills (0)'), 'Displays 0 registered skills');
check(str_contains($resNoSkills['body'], '+ Add Skills to Profile'), 'Displays CTA to add skills to profile');
check(str_contains($resNoSkills['body'], 'Add skills to unlock recommendations'), 'Displays recommendations prompt for skills');

// Empty State B: Freelancer with skills but 0 matching open jobs
$mockRepoNoMatches = new class extends JobMatchingRepository {
    public function __construct() {}
    public function getFreelancerSkills(int $freelancerId): array {
        return [['id' => 99, 'name' => 'RareLanguage']];
    }
    public function getFreelancerBidStatusMap(int $freelancerId): array { return []; }
    public function getCandidateJobsForFreelancer(array $freelancerSkillIds): array { return []; }
};

$workspaceCtrlNoMatches = new FreelancerWorkspaceController(
    $authGuard,
    static fn() => [$mockRepoNoMatches, $matchingService]
);
$resNoMatches = $workspaceCtrlNoMatches->index();
check($resNoMatches['status'] === 200, 'No-matches workspace returns 200 OK');
check(str_contains($resNoMatches['body'], 'Your Registered Skills (1)'), 'Displays 1 registered skill');
check(str_contains($resNoMatches['body'], 'RareLanguage'), 'Displays RareLanguage skill pill');
check(str_contains($resNoMatches['body'], 'No matching open jobs found'), 'Displays empty state for no matches');
check(str_contains($resNoMatches['body'], 'Browse All Open Jobs'), 'Displays CTA to browse all open jobs');

// =====================================================================
// 6. PublicJobController Integration Tests
// =====================================================================
class MockPublicJobRepo {
    public function browse(array $filters): array {
        return [
            [
                'id' => 50,
                'title' => 'Senior Backend Engineer',
                'description' => 'Join our team building high performance microservices.',
                'budget' => '6000.00',
                'deadline' => '2026-12-31',
                'created_at' => '2026-03-01 12:00:00',
                'skills' => [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'MySQL'], ['id' => 8, 'name' => 'Kotlin']],
            ],
            [
                'id' => 51,
                'title' => 'Mobile App Developer',
                'description' => 'Build Android native apps using Kotlin and Gradle.',
                'budget' => '5000.00',
                'deadline' => '2026-12-31',
                'created_at' => '2026-03-02 12:00:00',
                'skills' => [['id' => 8, 'name' => 'Kotlin'], ['id' => 9, 'name' => 'Gradle']],
            ],
        ];
    }
}

class MockSkillCatalogue {
    public function all(): array { return [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'MySQL']]; }
    public function existingIds(array $ids): array { return $ids; }
}

$repoFactory = static fn() => [new MockPublicJobRepo(), new MockSkillCatalogue()];

// Test 6a: Guest browsing (identity is null)
$guestSession = new AuthSession(null, static fn() => true);
$guestIdentity = new PublicIdentity($guestSession, static fn(int $id) => null);
$publicJobCtrlGuest = new PublicJobController($repoFactory, null, $guestIdentity, static fn() => [$mockRepoPopulated, $matchingService]);
$resGuest = $publicJobCtrlGuest->index();
check($resGuest['status'] === 200, 'Guest browsing returns 200 OK');
check(!str_contains($resGuest['body'], 'gh-match-pill'), 'Guest browsing does NOT render match pills');
check(!str_contains($resGuest['body'], 'gh-skill-tag--matched'), 'Guest browsing does NOT render matched skill highlighting');
check(str_contains($resGuest['body'], 'Senior Backend Engineer'), 'Guest sees job title');

// Test 6b: Client browsing (role = client)
$clientSession = new AuthSession(null, static fn() => true);
$clientSession->establish(5);
$clientIdentity = new PublicIdentity($clientSession, static fn(int $id) => [
    'id' => 5,
    'role' => 'client',
    'display_name' => 'Alice Client',
    'status' => 'active',
    'email_verified_at' => '2026-03-01 10:00:00',
]);
$publicJobCtrlClient = new PublicJobController($repoFactory, null, $clientIdentity, static fn() => [$mockRepoPopulated, $matchingService]);
$resClientBrowse = $publicJobCtrlClient->index();
check($resClientBrowse['status'] === 200, 'Client browsing returns 200 OK');
check(!str_contains($resClientBrowse['body'], 'gh-match-pill'), 'Client browsing does NOT render match pills');
check(!str_contains($resClientBrowse['body'], 'gh-skill-tag--matched'), 'Client browsing does NOT render matched skill highlighting');

// Test 6c: Authenticated Freelancer browsing (role = freelancer)
$freelancerBrowseSession = new AuthSession(null, static fn() => true);
$freelancerBrowseSession->establish(42);
$freelancerIdentity = new PublicIdentity($freelancerBrowseSession, static fn(int $id) => [
    'id' => 42,
    'role' => 'freelancer',
    'display_name' => 'Bob Freelancer',
    'status' => 'active',
    'email_verified_at' => '2026-03-01 10:00:00',
]);
$publicJobCtrlFreelancer = new PublicJobController($repoFactory, null, $freelancerIdentity, static fn() => [$mockRepoPopulated, $matchingService]);
$resFreelancerBrowse = $publicJobCtrlFreelancer->index();
check($resFreelancerBrowse['status'] === 200, 'Freelancer browsing returns 200 OK');
// Job 50: Freelancer has [1, 2], Job has [1, 2, 8] -> 2/3 = 66.7% match
check(str_contains($resFreelancerBrowse['body'], '66.7% Match'), 'Freelancer browsing shows 66.7% match badge on Job 50');
check(str_contains($resFreelancerBrowse['body'], '✓ PHP'), 'Freelancer browsing highlights ✓ PHP');
check(str_contains($resFreelancerBrowse['body'], '✓ MySQL'), 'Freelancer browsing highlights ✓ MySQL');
// Job 51: Freelancer has [1, 2], Job has [8, 9] -> 0/4 = 0.0% match (< 20%, sub-threshold tier)
check(str_contains($resFreelancerBrowse['body'], '0.0% Match'), 'Freelancer browsing shows 0.0% match badge on Job 51');
check(str_contains($resFreelancerBrowse['body'], 'gh-match-pill--sub'), 'Freelancer browsing renders sub-threshold tier for 0.0% match');

// =====================================================================
// 7. XSS Escaping and Security in Views
// =====================================================================
$xssJob = [
    'id' => 999,
    'title' => '<script>alert("xss title")</script>',
    'description' => '<img src=x onerror=alert("xss desc")>',
    'budget' => '1000.00',
    'deadline' => '2026-12-31',
    'created_at' => '2026-03-01 10:00:00',
    'client_name' => '<b>Evil Client</b>',
    'skills' => [['id' => 1, 'name' => '<script>alert("xss skill")</script>']],
];

$xssRepo = new class($xssJob) extends JobMatchingRepository {
    public function __construct(private readonly array $job) {}
    public function getFreelancerSkills(int $freelancerId): array {
        return [['id' => 1, 'name' => '<script>alert("xss skill")</script>']];
    }
    public function getFreelancerBidStatusMap(int $freelancerId): array { return []; }
    public function getCandidateJobsForFreelancer(array $freelancerSkillIds): array {
        return [$this->job];
    }
};

$workspaceCtrlXss = new FreelancerWorkspaceController($authGuard, static fn() => [$xssRepo, $matchingService]);
$resXss = $workspaceCtrlXss->index();
check($resXss['status'] === 200, 'XSS test returns 200 OK');
check(!str_contains($resXss['body'], '<script>alert("xss title")</script>'), 'Title raw script tag not found');
check(str_contains($resXss['body'], '&lt;script&gt;alert(&quot;xss title&quot;)&lt;/script&gt;'), 'Title safely escaped');
check(!str_contains($resXss['body'], '<img src=x'), 'Description raw img tag not found');
check(str_contains($resXss['body'], '&lt;img src=x onerror=alert(&quot;xss desc&quot;)&gt;'), 'Description safely escaped');
check(!str_contains($resXss['body'], '<b>Evil Client</b>'), 'Client name raw bold tag not found');
check(str_contains($resXss['body'], '&lt;b&gt;Evil Client&lt;/b&gt;'), 'Client name safely escaped');
check(!str_contains($resXss['body'], '<script>alert("xss skill")</script>'), 'Skill name raw script tag not found');

// =====================================================================
// 8. Module 18 UI Refinement & Modal Skill Updates Tests
// =====================================================================

// 8a. FreelancerProfileRepository::updateSkills isolated tests
class MockSkillsPDO extends PDO {
    public array $queries = [];
    public array $params = [];
    public array $events = [];
    public bool $inTx = false;
    public function __construct() {}
    public function beginTransaction(): bool { $this->events[] = 'tx:begin'; $this->inTx = true; return true; }
    public function commit(): bool { $this->events[] = 'tx:commit'; $this->inTx = false; return true; }
    public function rollBack(): bool { $this->events[] = 'tx:rollback'; $this->inTx = false; return true; }
    public function inTransaction(): bool { return $this->inTx; }
    public function exec(string $statement): int|false { $this->events[] = 'exec:' . $statement; return 1; }
    public function prepare(string $query, array $options = []): object {
        $this->queries[] = $query;
        return new class($this, $query) {
            public function __construct(private readonly MockSkillsPDO $parent, private readonly string $sql) {}
            public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool { return true; }
            public function execute(?array $params = null): bool {
                $this->parent->params[] = $params ?? [];
                return true;
            }
            public function fetch(int $mode = PDO::FETCH_DEFAULT): mixed {
                if (str_contains($this->sql, 'SELECT u.id,u.display_name,p.headline,p.bio')) {
                    return [
                        'id' => 42,
                        'display_name' => 'Bob Freelancer',
                        'headline' => 'Senior Backend Developer',
                        'bio' => 'Experienced with high performance systems.',
                    ];
                }
                return false;
            }
            public function fetchAll(int $mode = PDO::FETCH_DEFAULT): array { return []; }
            public function fetchColumn(int $column = 0): mixed { return 1; }
        };
    }
}

$mockSkillsPdo = new MockSkillsPDO();
$profileRepo = new FreelancerProfileRepository($mockSkillsPdo);

// Test updateSkills in repository
$mockSkillsPdo->queries = [];
$mockSkillsPdo->params = [];
$profileRepo->updateSkills(42, [1, 2]);

check(count($mockSkillsPdo->queries) === 2, 'updateSkills prepares 2 statements (DELETE, INSERT)');
check(count($mockSkillsPdo->params) === 3, 'updateSkills executes 3 statements (1 delete, 2 inserts)');
check(str_contains($mockSkillsPdo->queries[0], 'DELETE FROM user_skills WHERE freelancer_id = ?'), 'Deletes old user_skills');
check(str_contains($mockSkillsPdo->queries[1], 'INSERT INTO user_skills (freelancer_id, skill_id) VALUES (?, ?)'), 'Inserts skill into user_skills');

// Verify headline and bio were NOT updated
$hasProfileUpdate = false;
foreach ($mockSkillsPdo->queries as $q) {
    if (str_contains($q, 'UPDATE freelancer_profiles')) {
        $hasProfileUpdate = true;
    }
}
check(!$hasProfileUpdate, 'updateSkills does NOT touch freelancer_profiles table');

// 8b. FreelancerProfileService::saveSkills isolated tests
class MockSkillRepoForService extends SkillRepository {
    public function __construct() {}
    public function all(): array {
        return [
            ['id' => 1, 'name' => 'PHP'],
            ['id' => 2, 'name' => 'MySQL'],
            ['id' => 3, 'name' => 'Docker'],
            ['id' => 4, 'name' => 'Redis'],
        ];
    }
    public function existingIds(array $ids): array {
        $valid = [1, 2, 3, 4];
        return array_values(array_intersect($ids, $valid));
    }
}

$skillRepo = new MockSkillRepoForService();
$profileService = new FreelancerProfileService($mockSkillsPdo, $profileRepo, $skillRepo);

// Save valid skills
$mockSkillsPdo->events = [];
$mockSkillsPdo->queries = [];
$profileService->saveSkills(42, ['skills' => ['1', 2]]);
check(in_array('tx:begin', $mockSkillsPdo->events, true), 'saveSkills begins transaction');
check(in_array('tx:commit', $mockSkillsPdo->events, true), 'saveSkills commits transaction');

// Forged/invalid skill IDs -> throws FreelancerProfileException
$caughtForged = false;
try {
    $profileService->saveSkills(42, ['skills' => [1, 999]]);
} catch (FreelancerProfileException $e) {
    $caughtForged = true;
    check(isset($e->errors['skills']), 'Error on non-existent catalogue skill');
}
check($caughtForged, 'saveSkills rejects forged/non-catalogue skill ID');

// Malformed skill array (strings instead of numbers)
$caughtMalformed = false;
try {
    $profileService->saveSkills(42, ['skills' => ['abc']]);
} catch (FreelancerProfileException $e) {
    $caughtMalformed = true;
    check(isset($e->errors['skills']), 'Error on malformed skill ID string');
}
check($caughtMalformed, 'saveSkills rejects malformed skill ID strings');

// Empty skills selection (clearing all skills)
$mockSkillsPdo->events = [];
$profileService->saveSkills(42, ['skills' => []]);
check(in_array('tx:commit', $mockSkillsPdo->events, true), 'Empty skills selection successfully commits');

// 8c. Workspace View Refinements: Navigation Buttons, Modal Markup, and Empty States
$mockUserRepo->row = [
    'id' => 42,
    'role' => 'freelancer',
    'display_name' => 'Bob Freelancer',
    'status' => 'active',
    'email_verified_at' => '2026-03-01 10:00:00',
];
$authSession->establish(42);

$workspaceCtrlWithProfile = new FreelancerWorkspaceController(
    $authGuard,
    static fn() => [$mockRepoPopulated, $matchingService],
    static fn() => $profileService
);
$resRefined = $workspaceCtrlWithProfile->index();
check($resRefined['status'] === 200, 'Refined workspace returns 200 OK');

// 1. Navigation buttons check
check(str_contains($resRefined['body'], 'class="gh-workspace-nav"'), 'Shortcut navigation bar present');
check(str_contains($resRefined['body'], 'href="?route=/freelancer/bids"'), 'My Bids link present');
check(str_contains($resRefined['body'], 'href="?route=/freelancer/wallet"'), 'My Wallet link present');
check(str_contains($resRefined['body'], 'href="?route=/freelancer/profile"'), 'View Profile link present');
check(str_contains($resRefined['body'], 'href="?route=/freelancer/profile/edit"'), 'Edit Profile & Skills link present');
check(str_contains($resRefined['body'], 'href="?route=/jobs"'), 'Browse All Jobs link present');

// 2. Modal trigger check (replaces redirect link)
check(str_contains($resRefined['body'], 'id="gh-open-skills-modal"'), 'Modal trigger button present in skills section');
check(str_contains($resRefined['body'], 'Update skills</button>'), 'Update skills is a button element');
check(!str_contains($resRefined['body'], '<a class="gh-link-subtle" href="?route=/freelancer/profile/edit">Update skills</a>'), 'Old redirect link removed from skills section');

// 3. Modal dialog markup check
check(str_contains($resRefined['body'], '<dialog id="gh-skills-modal"'), 'Modal dialog element present');
check(str_contains($resRefined['body'], 'Update Your Skills'), 'Modal dialog title present');
check(str_contains($resRefined['body'], 'id="gh-skill-search-input"'), 'Search input present in modal');
check(str_contains($resRefined['body'], 'id="gh-selected-counter"'), 'Selected skills counter present in modal');
check(str_contains($resRefined['body'], 'id="gh-skills-cancel-btn"'), 'Cancel button present in modal');
check(str_contains($resRefined['body'], 'id="gh-modal-close-btn"'), 'Close button present in modal');
check(str_contains($resRefined['body'], 'name="skills[]" value="1" checked'), 'Skill 1 (PHP) pre-checked in modal');
check(str_contains($resRefined['body'], 'name="skills[]" value="2" checked'), 'Skill 2 (MySQL) pre-checked in modal');
check(str_contains($resRefined['body'], 'name="skills[]" value="3"'), 'Skill 3 (Docker) present in modal');
check(str_contains($resRefined['body'], 'name="skills[]" value="4"'), 'Skill 4 (Redis) present in modal');

// 4. Recommendation Empty State (Removal of duplicate "Add More Skills")
$workspaceCtrlNoMatchesRefined = new FreelancerWorkspaceController(
    $authGuard,
    static fn() => [$mockRepoNoMatches, $matchingService],
    static fn() => $profileService
);
$resNoMatchesRefined = $workspaceCtrlNoMatchesRefined->index();
check($resNoMatchesRefined['status'] === 200, 'No-matches workspace returns 200 OK');
check(str_contains($resNoMatchesRefined['body'], 'Browse All Open Jobs'), 'Browse All Open Jobs kept in empty state');
check(!str_contains($resNoMatchesRefined['body'], 'Add More Skills</a>'), 'Duplicate Add More Skills button removed from recommendation empty state');
check(str_contains($resNoMatchesRefined['body'], 'or update your registered skills above to explore different matches'), 'Informational guidance text present in recommendation empty state');

// 5. Zero-Skills Empty State has modal trigger
$workspaceCtrlNoSkillsRefined = new FreelancerWorkspaceController(
    $authGuard,
    static fn() => [$mockRepoNoSkills, $matchingService],
    static fn() => $profileService
);
$resNoSkillsRefined = $workspaceCtrlNoSkillsRefined->index();
check(str_contains($resNoSkillsRefined['body'], 'id="gh-open-skills-modal-empty"'), 'Zero-skills empty state has button to open modal');
check(str_contains($resNoSkillsRefined['body'], 'id="gh-open-skills-modal-rec"'), 'Recommendations empty state has button to open modal when 0 skills');

// 6. CSP Compliance & Modal External Asset Wiring Checks
check(str_contains($resRefined['body'], '<script src="assets/workspace.js?v='), 'workspace.js external script included with cache-busting version hash');
check(str_contains($resRefined['body'], 'defer></script>'), 'workspace.js script tag includes defer attribute');
check(!str_contains($resRefined['body'], '<script>'), 'No inline <script> tags in workspace view (CSP default-src self compliant)');
check(!str_contains($resRefined['body'], 'onclick='), 'No inline onclick attributes in workspace view (CSP default-src self compliant)');

$workspaceJsPath = dirname(__DIR__) . '/public/assets/workspace.js';
check(is_file($workspaceJsPath), 'public/assets/workspace.js file exists on disk');
$workspaceJsContent = (string)file_get_contents($workspaceJsPath);
check(strlen($workspaceJsContent) > 500, 'public/assets/workspace.js contains substantial implementation');
check(str_contains($workspaceJsContent, "'use strict';"), 'workspace.js enforces strict mode');
check(str_contains($workspaceJsContent, 'gh-skills-modal'), 'workspace.js binds modal element');
check(str_contains($workspaceJsContent, 'gh-open-skills-modal'), 'workspace.js binds open buttons');
check(str_contains($workspaceJsContent, 'gh-modal-close-btn'), 'workspace.js binds close button');
check(str_contains($workspaceJsContent, 'gh-skills-cancel-btn'), 'workspace.js binds cancel button');
check(str_contains($workspaceJsContent, 'gh-skill-search-input'), 'workspace.js binds search input');
check(str_contains($workspaceJsContent, 'gh-selected-counter'), 'workspace.js binds live counter');
check(str_contains($workspaceJsContent, 'showModal'), 'workspace.js uses showModal dialog API');
check(str_contains($workspaceJsContent, 'restoreInitialState'), 'workspace.js implements cancellation state restoration');


// 8d. FreelancerWorkspaceController::updateSkills POST action
// Unauthenticated POST -> 303 to /login
$_SESSION = [];
$unauthSession = new AuthSession(null, static fn() => true);
$unauthGuard = new AuthGuard(static fn() => $authService, $unauthSession);
$workspaceCtrlUnauthSkills = new FreelancerWorkspaceController($unauthGuard, static fn() => [$mockRepoPopulated, $matchingService], static fn() => $profileService);
$resUnauthPost = $workspaceCtrlUnauthSkills->updateSkills(['csrf_token' => 'x']);
check($resUnauthPost['status'] === 303 && $resUnauthPost['location'] === '?route=/login', 'Unauthenticated updateSkills redirects to login');

// Client role POST -> 403 Forbidden
$clientSession = new AuthSession(null, static fn() => true);
$clientSession->establish(5);
$clientUserRepo = new class extends UserRepository {
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false {
        return ['id' => 5, 'role' => 'client', 'display_name' => 'Alice Client', 'status' => 'active', 'email_verified_at' => '2026-03-01 10:00:00'];
    }
};
$clientAuthGuard = new AuthGuard(static fn() => new AuthenticationService($clientUserRepo), $clientSession);
$workspaceCtrlClientSkills = new FreelancerWorkspaceController($clientAuthGuard, static fn() => [$mockRepoPopulated, $matchingService], static fn() => $profileService);
$resClientPost = $workspaceCtrlClientSkills->updateSkills(['csrf_token' => 'x']);
check($resClientPost['status'] === 403, 'Client role updateSkills returns 403 Forbidden');

// Authenticated Freelancer: Invalid CSRF Token -> 303 PRG with error flash
$authSession->establish(42);
$resBadCsrf = $workspaceCtrlWithProfile->updateSkills(['csrf_token' => 'invalid-token', 'skills' => [1]]);
check($resBadCsrf['status'] === 303, 'Invalid CSRF returns 303 PRG');
check($resBadCsrf['headers']['Location'] === '?route=/freelancer', 'Redirects to /freelancer');
$flashNotifs = Notification::consume();
check(count($flashNotifs) > 0 && $flashNotifs[0]['type'] === 'error', 'Error flash set for invalid CSRF');

// Authenticated Freelancer: Valid submission with valid CSRF Token -> 303 PRG with success flash
$validToken = Csrf::token('freelancer_profile');
$resGoodPost = $workspaceCtrlWithProfile->updateSkills(['csrf_token' => $validToken, 'skills' => [1, 3]]);
check($resGoodPost['status'] === 303, 'Valid skill update returns 303 PRG');
check($resGoodPost['headers']['Location'] === '?route=/freelancer', 'Redirects to /freelancer');
$flashSuccess = Notification::consume();
check(count($flashSuccess) > 0 && $flashSuccess[0]['type'] === 'success', 'Success flash set for valid skill update');
check($flashSuccess[0]['message'] === 'Your skills have been updated.', 'Success flash message text verified');

// Authenticated Freelancer: Invalid/forged skill ID -> 303 PRG with error flash
$validToken2 = Csrf::token('freelancer_profile');
$resForgedPost = $workspaceCtrlWithProfile->updateSkills(['csrf_token' => $validToken2, 'skills' => [9999]]);
check($resForgedPost['status'] === 303, 'Forged skill update returns 303 PRG');
$flashForged = Notification::consume();
check(count($flashForged) > 0 && $flashForged[0]['type'] === 'error', 'Error flash set for forged skill ID');

// 8e. Verify profile fields (headline, bio) untouched after skill updates
$currentProfile = $profileService->profile(42);
check($currentProfile['headline'] === 'Senior Backend Developer', 'Headline remains untouched');
check($currentProfile['bio'] === 'Experienced with high performance systems.', 'Bio remains untouched');

echo "All $count tests in Module 18 offline test suite PASSED successfully!\n";
