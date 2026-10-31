<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/app/Services/RegistrationValidator.php')) {
    fwrite(STDERR, "FAIL: Registration has not been implemented.\n"); exit(1);
}
require $root . '/app/Services/RegistrationValidator.php';
require $root . '/app/Core/Csrf.php';
require $root . '/app/Core/Router.php';
if (is_file($root . '/app/Core/RegistrationThrottle.php')) { require $root . '/app/Core/RegistrationThrottle.php'; }
require $root . '/app/Controllers/RegistrationController.php';
use GigHub\Services\RegistrationValidator as Validator;
use GigHub\Core\Csrf;
use GigHub\Core\Router;
use GigHub\Controllers\RegistrationController;
$count = 0;
$check = static function (bool $value, string $label) use (&$count): void {
    if (!$value) { throw new RuntimeException('FAIL: ' . $label); } $count++;
};
$_SESSION = [];
$valid = ['display_name' => 'Synthetic Person', 'email' => ' Person@example.invalid ', 'role' => 'client',
    'password' => 'Only a synthetic passphrase 2026!', 'password_confirmation' => 'Only a synthetic passphrase 2026!',
    'company_name' => 'Synthetic Company', 'bio' => 'Synthetic biography'];
$result = Validator::validate($valid);
$check($result['errors'] === [], 'valid client input');
$check($result['data']['email'] === 'person@example.invalid', 'normalized email');
$free = array_replace($valid, ['role' => 'freelancer', 'company_name' => '']);
$check(Validator::validate($free)['errors'] === [], 'valid freelancer input');
$twelve = array_replace($valid, ['password' => 'Twelve words', 'password_confirmation' => 'Twelve words']);
$check(Validator::validate($twelve)['errors'] === [], '12-character password accepted');
$eleven = array_replace($valid, ['password' => 'Eleven word', 'password_confirmation' => 'Eleven word']);
$check(isset(Validator::validate($eleven)['errors']['password']), '11-character password rejected');
$max = str_repeat('Ab', 64);
$check(Validator::validate(array_replace($valid, ['password' => $max, 'password_confirmation' => $max]))['errors'] === [], '128-character password accepted');
foreach ([['email', 'invalid'], ['email', str_repeat('a', 250) . '@example.invalid'], ['password', 'weak'],
    ['password', str_repeat('a', 20)], ['password_confirmation', 'mismatch'], ['role', 'admin'], ['role', ['client']],
    ['display_name', ''], ['display_name', str_repeat('x', 101)], ['company_name', str_repeat('x', 101)],
    ['bio', str_repeat('x', 2001)], ['password', "long synthetic\0password"], ['password', str_repeat('x', 129)]] as [$key, $value]) {
    $check(isset(Validator::validate(array_replace($valid, [$key => $value]))['errors'][$key]), 'reject invalid ' . $key);
}
$ignored = Validator::validate(array_replace($free, ['company_name' => 'stale client data', 'portfolio_url' => 'javascript:alert(1)', 'portfolio_description' => 'not registration data']));
$check($ignored['errors'] === [] && $ignored['data']['company_name'] === '', 'irrelevant company data ignored');
$check(!isset($ignored['data']['portfolio_url']) && !isset($ignored['data']['portfolio_description']), 'portfolio fields excluded from registration data');
$token = Csrf::token();
$check(strlen($token) === 64 && Csrf::valid($token), 'session-bound CSRF token');
$check(!Csrf::valid('bad') && !Csrf::valid([]), 'malformed CSRF rejected');
Csrf::rotate(); $check(!Csrf::valid($token), 'old CSRF token invalidated');
$controller = new RegistrationController(static function () { throw new RuntimeException('Database must not be called.'); });
$form = $controller->show()['body'];
$check(str_contains($form, '>Full Name <') && str_contains($form, 'name="display_name"'), 'Full Name uses existing display_name mapping');
$check(!str_contains($form, 'name="portfolio_description"') && !str_contains($form, 'name="portfolio_url"'), 'portfolio form fields absent');
$check(!preg_match('/id="password"[^>]*(?:minlength|maxlength)=/', $form), 'password does not use inconsistent UTF-16 HTML bounds');
$check(strpos($form, 'id="create-account"') < strpos($form, 'id="role-update"'), 'account creation is the first submit action even without JavaScript');
$check(str_contains($form, 'aria-controls="password"') && str_contains($form, 'Show password'), 'accessible password visibility control');
$check(str_contains($form, 'class="password-fields"') && !str_contains($form, 'class="two-columns"'), 'password fields use vertical full-width layout');
$check(str_contains($form, 'class="password-input"') && str_contains($form, '<svg') && str_contains($form, 'aria-live="polite"'), 'eye icons and accessible feedback');
$check(str_contains($form, 'id="password-help"') && str_contains($form, 'Use at least 12 characters for your password.'), 'fallback hint below Password');
$check(str_contains($form, 'Your next chapter starts here</h1>'), 'hero punctuation updated');
$clientForm = $controller->submit(['csrf_token' => Csrf::token(), 'intent' => 'update_role', 'role' => 'client', 'display_name' => 'Kept name', 'bio' => 'Kept bio'])['body'];
$freeForm = $controller->submit(['csrf_token' => Csrf::token(), 'intent' => 'update_role', 'role' => 'freelancer', 'company_name' => 'stale'])['body'];
$check(str_contains($clientForm, 'value="Kept name"') && str_contains($clientForm, '>Kept bio</textarea>'), 'role fallback preserves non-password input');
$check((bool) preg_match('/id="client-profile"[^>]*hidden[^>]*disabled/', $freeForm), 'Client group hidden and disabled for Freelancer');
$check(!preg_match('/id="client-profile"[^>]*(?:hidden|disabled)/', $clientForm), 'Client group available for Client fallback');
$response = $controller->submit(['csrf_token' => 'bad'] + $valid);
$check($response['status'] === 403, 'CSRF rejected before database');
$recovered = $controller->submit(array_replace($valid, ['csrf_token' => 'bad', 'display_name' => '<script>review</script>', 'bio' => str_repeat('x', 2100)]));
$check(str_contains($recovered['body'], '&lt;script&gt;review&lt;/script&gt;') && !str_contains($recovered['body'], '<script>review</script>'), 'CSRF recovery escapes non-password input');
$check(!str_contains($recovered['body'], $valid['password']) && !str_contains($recovered['body'], str_repeat('x', 2001)), 'CSRF recovery is bounded and excludes passwords');
$check(str_contains($recovered['body'], '<title>Registration needs attention · GigHub</title>'), 'rejected submission has an error-page title');
$response = $controller->submit(['csrf_token' => Csrf::token()] + array_replace($valid, ['email' => 'bad', 'display_name' => '<script>alert(1)</script>']));
$check($response['status'] === 422 && !str_contains($response['body'], '<script>alert(1)</script>'), 'validation and output escaping before database');
$check(!str_contains($response['body'], $valid['password']), 'password never repopulated');
$check(!isset($_SESSION['user_id']) && !isset($_SESSION['role']), 'no login session');
$check(!isset($_SESSION['registration_notice']), 'failed request has no success flash');
$unavailable = $controller->submit(['csrf_token' => Csrf::token()] + $valid);
$check($unavailable['status'] === 503 && !str_contains($unavailable['body'], 'Database must not be called.'), 'connection failure has generic feedback');
$check(!str_contains($unavailable['body'], $valid['password']) && !isset($_SESSION['registration_notice']), 'failure has no password or completion state');
$router = new Router(); $router->get('/register', static fn () => ['status' => 200]); $router->post('/register', static fn () => ['status' => 303]);
$check($router->dispatch('GET', '/register')['status'] === 200 && $router->dispatch('POST', '/register')['status'] === 303, 'GET and POST routing');
$check($router->dispatch('DELETE', '/register')['allow'] === 'GET, POST', 'correct Allow header');
$_SESSION = [];
for ($attempt = 0; $attempt < 5; $attempt++) {
    $check(GigHub\Core\RegistrationThrottle::consume(1000)['allowed'], 'five attempts allowed');
}
$blocked = GigHub\Core\RegistrationThrottle::consume(1000);
$check(!$blocked['allowed'] && $blocked['retry_after'] === 600, 'sixth attempt blocked with retry delay');
$check(GigHub\Core\RegistrationThrottle::consume(1600)['allowed'], 'throttle expires');
$_SESSION = [];
for ($attempt = 0; $attempt < 5; $attempt++) { GigHub\Core\RegistrationThrottle::consume(); }
$limited = $controller->submit(['csrf_token' => Csrf::token()] + $valid);
$check($limited['status'] === 429 && $limited['retry_after'] > 0 && !str_contains($limited['body'], $valid['password']), 'throttled request never reaches database');
$check(str_contains($limited['body'], 'minute'), 'retry duration visible to user');
foreach (['123456789012', 'Password123456', 'qwertyuiop123'] as $weak) {
    $check(isset(Validator::validate(array_replace($valid, ['password' => $weak, 'password_confirmation' => $weak]))['errors']['password']), 'reject common password');
}
foreach ([11 => false, 12 => true, 128 => true, 129 => false] as $length => $accepted) {
    $password = mb_substr(str_repeat("\u{1F600}\u{1F680}", 65), 0, $length, 'UTF-8');
    $errors = Validator::validate(array_replace($valid, ['password' => $password, 'password_confirmation' => $password]))['errors'];
    $check(!isset($errors['password']) === $accepted, 'Unicode password boundary ' . $length);
}
foreach ([['short', 'Use at least 12 characters.'], [str_repeat('Ab',65), 'Use no more than 128 characters.'],
    ['123456789012', 'This password is too easy to guess. Try a longer, less predictable password.'],
    [str_repeat('a',12), 'Avoid repeating the same character for the whole password.']] as [$password,$message]) {
    $errors = Validator::validate(array_replace($valid, ['password'=>$password,'password_confirmation'=>$password]))['errors'];
    $check(($errors['password'] ?? '') === $message, 'actionable password message');
}
$check(Validator::validate(array_replace($valid, ['password_confirmation'=>'different']))['errors']['password_confirmation'] === 'Passwords do not match.', 'concise confirmation mismatch');
echo "PASS: {$count} registration checks; no database access.\n";
