<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$count;
};
$check(is_file($root . '/app/Core/PublicIdentity.php'), 'optional public identity exists');
foreach (['Core/Csrf','Core/AuthSession','Core/PublicIdentity','Services/JobBrowseValidator','Controllers/HomeController'] as $class) {
    require_once $root . '/app/' . $class . '.php';
}
$session = new GigHub\Core\AuthSession(static fn() => 1000);
$identity = ['id'=>7, 'role'=>'client', 'display_name'=>'<script>unsafe</script>'];
$calls = 0;
$resolver = new GigHub\Core\PublicIdentity($session, static function (int $id) use (&$identity, &$calls): ?array {
    ++$calls;
    if ($id !== 7) { throw new RuntimeException('Wrong identity'); }
    return $identity;
});
$_SESSION = [];
$check($resolver->current() === null && $calls === 0, 'guest never invokes database identity lookup');
$guest = (new GigHub\Controllers\HomeController($resolver))->show();
$check($guest['status'] === 200, 'guests are not redirected');
foreach (['/jobs','/login','/register'] as $route) {
    $check(str_contains($guest['body'], 'href="?route=' . $route . '"'), 'guest route ' . $route);
}
$check(!str_contains($guest['body'], 'action="?route=/logout"'), 'guest has no logout form');
foreach (['client','freelancer','admin'] as $role) {
    $_SESSION = ['auth'=>['user_id'=>7,'issued_at'=>900,'last_activity'=>950]];
    $identity['role'] = $role;
    $response = (new GigHub\Controllers\HomeController($resolver))->show();
    $html = $response['body'];
    $check($response['status'] === 200, $role . ' homepage remains public');
    $check(str_contains($html, 'href="?route=/' . $role . '"'), $role . ' fixed workspace route');
    $check(str_contains($html, 'href="?route=/jobs"'), $role . ' browse link');
    $check(!str_contains($html, 'href="?route=/login"'), $role . ' no guest sign-in link');
    $check(str_contains($html, '&lt;script&gt;unsafe&lt;/script&gt;') && !str_contains($html, '<script>unsafe'), 'identity output escaped');
    $check(str_contains($html, '<form method="post" action="?route=/logout"'), 'logout POST only');
    $check(GigHub\Core\Csrf::valid($_SESSION['logout_csrf'] ?? null, 'logout'), 'existing logout CSRF purpose');
    $check(str_contains($html, 'value="' . $_SESSION['logout_csrf'] . '"'), 'logout token rendered');
    $check(!str_contains($html, 'href="?route=/logout"'), 'no GET logout');
    $check((str_contains($html, 'href="?route=/client/jobs"')) === ($role === 'client'), 'Client My Jobs scope');
    $check((str_contains($html, 'href="?route=/client/jobs/create"')) === ($role === 'client'), 'Client posting scope');
    $check(str_contains($html, '<details') && str_contains($html, '<summary>Menu</summary>'), 'native no-JS disclosure');
    $check(str_contains($html, 'aria-label="Main navigation"') && str_contains($html, 'href="#main-content"'), 'navigation landmark and skip link');
    $check(substr_count($html, '<form ') === 2 && substr_count($html, '</form>') === 2
        && preg_match('/<form\b[^>]*>\s*<input[^>]+csrf_token[^>]*>\s*<button[^>]*>Sign out<\/button>\s*<\/form>/s', $html) === 1, 'logout form remains separate from homepage search');
    $check(str_contains($html, 'class="gh-brand" href="?route=/"'), 'logo always public home');
    if ($role === 'client') {
        $check(str_contains($html, 'class="gh-account-item gh-account-workspace" href="?route=/client"'), 'client dropdown has workspace');
    } elseif ($role === 'freelancer') {
        $check(str_contains($html, 'class="gh-account-item gh-account-workspace" href="?route=/freelancer"'), 'freelancer dropdown has workspace');
    }
}
$identity = null;
$check($resolver->current() === null && !isset($_SESSION['auth']), 'ineligible identity is forgotten');
$_SESSION = ['auth'=>['user_id'=>7,'issued_at'=>900,'last_activity'=>950]];
$unavailable = new GigHub\Core\PublicIdentity($session, static function (): never { throw new RuntimeException('private diagnostic'); });
$html = (new GigHub\Controllers\HomeController($unavailable))->show()['body'];
$check(str_contains($html, 'href="?route=/login"') && !str_contains($html, 'private diagnostic'), 'lookup failure shows safe guest navigation');
$check(isset($_SESSION['auth']), 'transient lookup failure does not destroy session');
$_SESSION['auth']['last_activity'] = -1000;
$check($resolver->current() === null, 'expired session is not shown as authenticated');
$bootstrap = file_get_contents($root . '/bootstrap/app.php');
$check(str_contains($bootstrap, 'new PublicIdentity($authSession') && str_contains($bootstrap, '->identity($id)'), 'production reuses database-validated identity');
$check(str_contains($bootstrap, "\$router->post('/logout'") && !str_contains($bootstrap, "\$router->get('/logout'"), 'existing POST logout route retained');

// Active Navigation & Dropdown Suite
$renderHeader = static function (?array $user, string $route) use ($root): string {
    $_GET['route'] = $route;
    $navigationUser = $user;
    $logoutToken = 'csrf_logout_test_token';
    $unreadNotificationCount = 0;
    $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ob_start();
    require $root . '/app/Views/partials/header.php';
    return (string)ob_get_clean();
};

$clientUser = ['id' => 7, 'role' => 'client', 'display_name' => 'Sarah Client'];
$freelancerUser = ['id' => 8, 'role' => 'freelancer', 'display_name' => 'Alex Freelancer'];

// 1. Client Exact & Nested Route Matching
$html = $renderHeader($clientUser, '/client');
$check(str_contains($html, 'href="?route=/client" aria-current="page"'), 'client exact /client is active');
$check(!str_contains($html, 'href="?route=/client/jobs" aria-current="page"'), 'client /client does not activate /client/jobs');
$check(substr_count($html, 'aria-current="page"') === 2, 'client /client exactly one active link per nav');

$html = $renderHeader($clientUser, '/client/jobs');
$check(str_contains($html, 'href="?route=/client/jobs" aria-current="page"'), 'client exact /client/jobs is active');
$check(!str_contains($html, 'href="?route=/client" aria-current="page"'), 'client /client/jobs does not activate /client');
$check(!str_contains($html, 'href="?route=/client/jobs/create" aria-current="page"'), 'client /client/jobs does not activate create');

$html = $renderHeader($clientUser, '/client/jobs/create');
$check(str_contains($html, 'href="?route=/client/jobs/create" aria-current="page"'), 'client exact /client/jobs/create is active');
$check(!str_contains($html, 'href="?route=/client/jobs" aria-current="page"'), 'client /client/jobs/create does not double-activate /client/jobs');

$html = $renderHeader($clientUser, '/client/jobs/edit');
$check(str_contains($html, 'href="?route=/client/jobs" aria-current="page"'), 'client nested /client/jobs/edit falls back to My Jobs');
$check(!str_contains($html, 'href="?route=/client/jobs/create" aria-current="page"'), 'client nested edit does not activate create');

$html = $renderHeader($clientUser, '/client/jobs/show');
$check(str_contains($html, 'href="?route=/client/jobs" aria-current="page"'), 'client nested /client/jobs/show falls back to My Jobs');

$html = $renderHeader($clientUser, '/client/jobs/bids');
$check(str_contains($html, 'href="?route=/client/jobs" aria-current="page"'), 'client nested /client/jobs/bids falls back to My Jobs');

$html = $renderHeader($clientUser, '/client/wallet');
$check(str_contains($html, 'href="?route=/client/wallet" aria-current="page"'), 'client exact /client/wallet is active');

// 2. Freelancer Exact & Nested Route Matching
$html = $renderHeader($freelancerUser, '/freelancer');
$check(str_contains($html, 'href="?route=/freelancer" aria-current="page"'), 'freelancer exact /freelancer is active');
$check(!str_contains($html, 'href="?route=/freelancer/bids" aria-current="page"'), 'freelancer /freelancer does not activate bids');

$html = $renderHeader($freelancerUser, '/freelancer/bids');
$check(str_contains($html, 'href="?route=/freelancer/bids" aria-current="page"'), 'freelancer exact /freelancer/bids is active');
$check(!str_contains($html, 'href="?route=/freelancer" aria-current="page"'), 'freelancer /freelancer/bids does not activate workspace');

$html = $renderHeader($freelancerUser, '/freelancer/bids/create');
$check(str_contains($html, 'href="?route=/freelancer/bids" aria-current="page"'), 'freelancer nested /freelancer/bids/create falls back to My Bids');

$html = $renderHeader($freelancerUser, '/freelancer/bids/edit');
$check(str_contains($html, 'href="?route=/freelancer/bids" aria-current="page"'), 'freelancer nested /freelancer/bids/edit falls back to My Bids');

$html = $renderHeader($freelancerUser, '/jobs');
$check(str_contains($html, 'href="?route=/jobs" aria-current="page"'), 'freelancer exact /jobs is active');

$html = $renderHeader($freelancerUser, '/jobs/show');
$check(str_contains($html, 'href="?route=/jobs" aria-current="page"'), 'freelancer nested /jobs/show falls back to Browse Jobs');

$html = $renderHeader($freelancerUser, '/freelancer/profile');
$check(!str_contains($html, 'aria-current="page"'), 'freelancer /freelancer/profile does not activate unrelated nav');

$html = $renderHeader($freelancerUser, '/jobs-unrelated');
$check(!str_contains($html, 'aria-current="page"'), 'unrelated route does not activate any nav');

$html = $renderHeader($clientUser, '/client/unrelated');
$check(!str_contains($html, 'aria-current="page"'), 'unrelated client route does not activate workspace');

// 3. Compact My Jobs Header & View Integrity
$jobs = [];
$page = 1;
$hasNext = false;
$navigationUser = $clientUser;
$logoutToken = 'csrf_logout_test_token';
$escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
ob_start();
require $root . '/app/Views/client/jobs/index.php';
$html = (string)ob_get_clean();

$check(str_contains($html, 'class="jobs-page-header"'), 'My Jobs uses compact jobs-page-header');
$check(str_contains($html, '<h1>My Jobs</h1>'), 'My Jobs h1 rendered');
$check(str_contains($html, 'href="?route=/client/jobs/create"'), 'My Jobs links to create job');
$check(str_contains($html, '+ Post a Job'), 'My Jobs has + Post a Job label');
$check(!str_contains($html, 'class="jobs-heading"'), 'My Jobs eliminates legacy jobs-heading');
$check(!str_contains($html, 'YOUR NEXT COLLABORATION'), 'My Jobs eliminates eyebrow banner');

// 4. Compact Post a Job & Edit Draft Header Integrity
$errors = [];
$skills = [];
$old = [];
$token = 'csrf_job_token';

// Post a Job mode ($id === null)
$id = null;
ob_start();
require $root . '/app/Views/client/jobs/form.php';
$html = (string)ob_get_clean();
$check(str_contains($html, '<h1>Post a Job</h1>'), 'Post a Job heading rendered');
$check(str_contains($html, 'Describe your project and find the right freelancer.'), 'Post a Job subtitle rendered');
$check(!str_contains($html, 'BUILD SOMETHING GREAT'), 'Post a Job eliminates eyebrow banner');
$check(!str_contains($html, 'class="jobs-heading"'), 'Post a Job eliminates legacy jobs-heading');

// Edit Draft mode ($id !== null)
$id = 42;
ob_start();
require $root . '/app/Views/client/jobs/form.php';
$html = (string)ob_get_clean();
$check(str_contains($html, '<h1>Edit Your Draft</h1>'), 'Edit Draft heading rendered');
$check(str_contains($html, 'Update your draft details before publishing.'), 'Edit Draft subtitle rendered');

// 5. Client Job Details Back Navigation Integrity
$job = [
    'id' => 10,
    'title' => 'Test Client Project',
    'status' => 'open',
    'budget' => '1200.00',
    'deadline' => '2026-12-31',
    'description' => 'Project description text here.',
    'skills' => []
];
$notice = '';
ob_start();
require $root . '/app/Views/client/jobs/show.php';
$html = (string)ob_get_clean();
$check(str_contains($html, 'class="gh-back-nav"'), 'Job Details has gh-back-nav');
$check(str_contains($html, 'href="?route=/client/jobs"'), 'Job Details back nav links to My Jobs');
$check(str_contains($html, '← Back to My Jobs'), 'Job Details back nav label');

// 6. CSS Stability Assertions
$shellCss = file_get_contents($root . '/public/assets/shared-shell.css');
$check(str_contains($shellCss, 'scrollbar-gutter: stable;'), 'shared-shell has scrollbar-gutter: stable');
$check(str_contains($shellCss, '.gh-account-workspace::before'), 'shared-shell has workspace icon rule');

$jobsCss = file_get_contents($root . '/public/assets/jobs.css');
$check(str_contains($jobsCss, '.jobs-page-header'), 'jobs.css has .jobs-page-header');
$check(str_contains($jobsCss, '.jobs-page-header-actions'), 'jobs.css has .jobs-page-header-actions');
$check(str_contains($jobsCss, '.jobs-layout .gh-back-nav'), 'jobs.css has back nav styles');
echo "PASS: {$count} shared navigation checks; no database or network access.\n";
