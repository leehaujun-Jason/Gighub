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
echo "PASS: {$count} shared navigation checks; no database or network access.\n";
