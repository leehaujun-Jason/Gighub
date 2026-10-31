<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// All requests are invalid or synthetic unknown-account requests. No fixture writes.
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) { throw new RuntimeException($label); } $checks++;
};
try {
    $root = dirname(__DIR__);
    $check(is_file($root . '/app/Controllers/EmailVerificationController.php'), 'verification controller exists');
    require_once $root . '/app/Core/Csrf.php';
    require_once $root . '/app/Core/VerificationThrottle.php';
    require_once $root . '/app/Controllers/EmailVerificationController.php';
    $_SESSION = [];
    $factoryCalls = 0;
    $controller = new GigHub\Controllers\EmailVerificationController(static function () use (&$factoryCalls): never { $factoryCalls++; throw new RuntimeException('private diagnostic'); });
    $page = $controller->show();
    $check($page['status'] === 200 && $factoryCalls === 0, 'scanner GET never opens verification service');
    $check(str_contains($page['body'], '<details id="verification-fallback"') && str_contains($page['body'], '<noscript>'), 'manual fallback and script-free instructions');
    $check(!str_contains($page['body'], 'name="verification_key" value='), 'no server key repopulation');
    $badKey = 'private-malformed-key';
    $response = $controller->confirm(['verification_key' => $badKey]);
    $check($response['status'] === 403 && !str_contains($response['body'], $badKey), 'CSRF rejection does not reflect key');
    $csrf = GigHub\Core\Csrf::token('verification');
    foreach (['', '0.' . str_repeat('a', 64), '4294967296.' . str_repeat('a', 64), '01.' . str_repeat('a', 64), ['bad']] as $key) {
        $response = $controller->confirm(['csrf_token' => $csrf, 'verification_key' => $key]);
        $check($response['status'] === 303 && $factoryCalls === 0, 'malformed keys take generic PRG without service');
    }
    $result = $controller->result();
    $check(str_contains($result['body'], 'This verification link cannot be used.'), 'generic invalid result includes recovery');
    $check($controller->result()['status'] === 303, 'result flash is one-time');
    $_SESSION = []; $csrf = GigHub\Core\Csrf::token('verification');
    $temporaryKey = '1.' . str_repeat('c', 64);
    $response = $controller->confirm(['csrf_token' => $csrf, 'verification_key' => $temporaryKey]);
    $check($response['status'] === 303 && $_SESSION['verification_result_notice'] === 'unavailable', 'temporary verification failure has a separate recoverable outcome');
    $check(!str_contains(serialize($_SESSION), $temporaryKey), 'temporary failure never stores the key');
    $html = $controller->result()['body'];
    $check(str_contains($html, 'Verification is temporarily unavailable.') && str_contains($html, 'original verification message') && !str_contains($html, '?route=/verify-email/resend'), 'temporary failure preserves original-link retry guidance without immediate resend');
    $check(!str_contains($html, $temporaryKey) && !str_contains($html, 'private diagnostic'), 'temporary failure reflects no key or diagnostic');
    $factoryCalls = 0;
    $_SESSION = []; $csrf = GigHub\Core\Csrf::token('verification');
    $service = new class {
        public array $seen = [];
        public string $delivery = 'captured';
        public function verify(string $key): string { $this->seen[] = $key; return 'verified'; }
        public function request(string $email): string { $this->seen[] = $email; return $this->delivery; }
    };
    $successController = new GigHub\Controllers\EmailVerificationController(static fn () => $service);
    $syntheticKey = '4294967295.' . str_repeat('b', 64);
    $response = $successController->confirm(['csrf_token' => $csrf, 'verification_key' => $syntheticKey]);
    $check($response['status'] === 303 && $service->seen === [$syntheticKey], 'script-free key body reaches service after explicit POST');
    $check($_SESSION['verification_result_notice'] === 'verified' && !str_contains(serialize($_SESSION), $syntheticKey), 'session stores outcome without verification key');
    $html = $successController->result()['body'];
    $check(str_contains($html, 'Your email is verified.') && !str_contains($html, '?route=/login') && !isset($_SESSION['user_id']), 'success result creates no login session or link');
    $genericHtml = null;
    foreach (['captured', 'accepted', 'uncertain', 'failed', 'not_sent'] as $delivery) {
        $service->delivery = $delivery;
        $response = $successController->resend(['csrf_token' => $csrf, 'email' => ' UNKNOWN-VERIFICATION@EXAMPLE.INVALID ']);
        $check($response['status'] === 303 && end($service->seen) === 'unknown-verification@example.invalid', 'resend normalizes email and redirects');
        $html = $successController->result()['body'];
        $genericHtml ??= $html;
        $check($html === $genericHtml, 'every delivery status has identical public response');
    }
    foreach (['captured', 'uncertain', 'failed', 'unavailable', 'not_configured'] as $deliveryStatus) {
        ob_start(); require $root . '/app/Views/registration-pending.php'; $html = ob_get_clean();
        $check(str_contains($html, 'No verification email has been sent.') && str_contains($html, '?route=/verify-email/resend'), 'pending delivery variants retain truthful development notice and recovery');
        if ($deliveryStatus === 'failed') { $check(str_contains($html, 'The verification attempt could not be completed.') && !str_contains($html, 'could not prepare'), 'failed transport does not claim token issuance failed'); }
    }
    $_SESSION = [];
    $csrf = GigHub\Core\Csrf::token('verification');
    $response = $controller->resend(['csrf_token' => $csrf, 'email' => '<script>@example.invalid']);
    $check($response['status'] === 422 && str_contains($response['body'], '&lt;script&gt;') && $factoryCalls === 0, 'invalid email bounded escaped and no service');
    $response = $controller->resend(['csrf_token' => $csrf, 'email' => str_repeat('x', 300)]);
    $check($response['status'] === 422 && !str_contains($response['body'], str_repeat('x', 255)), 'oversized email bounded');
    $response = $controller->resend(['csrf_token' => $csrf, 'email' => 'unknown-verification@example.invalid']);
    $check($response['status'] === 303 && $factoryCalls === 1, 'service failure uses generic resend PRG');
    $html = $controller->result()['body'];
    $check(!str_contains($html, 'private diagnostic') && !str_contains($html, 'unknown-verification@example.invalid'), 'no diagnostic or account reflection in result');
    $_SESSION = [];
    for ($i = 0; $i < 10; $i++) { $check(GigHub\Core\VerificationThrottle::consume(1000)['allowed'], 'anonymous request budget'); }
    $check(GigHub\Core\VerificationThrottle::consume(1599) === ['allowed' => false, 'retry_after' => 1], 'request budget boundary 599');
    $check(GigHub\Core\VerificationThrottle::consume(1600)['allowed'], 'request budget boundary 600');
    $_SESSION = []; $csrf = GigHub\Core\Csrf::token('verification');
    for ($i = 0; $i < 10; $i++) { $controller->confirm(['csrf_token' => $csrf]); }
    $response = $controller->resend(['csrf_token' => $csrf]);
    $check($response['status'] === 429 && isset($response['retry_after']), 'malformed confirmation and resend share budget');

    $cookie = '';
    $request = static function (string $route, ?array $post = null) use (&$cookie): array {
        $headers = ['Connection: close'];
        if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
        if ($post !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
        $context = stream_context_create(['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $post === null ? '' : http_build_query($post), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = @file_get_contents('http://localhost/GigHub/public/index.php?route=' . $route, false, $context);
        if (!is_string($body)) { throw new RuntimeException('Local HTTP unavailable'); }
        foreach ($http_response_header as $header) { if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) { $cookie = $match[1]; } }
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
        return [(int) ($match[1] ?? 0), $body];
    };
    [$status, $html] = $request('/verify-email');
    $check($status === 200 && str_contains($html, 'Confirm Verification'), 'HTTP scanner GET confirmation only');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $match);
    $check(isset($match[1]), 'HTTP verification CSRF'); $csrf = $match[1];
    [$status, $html] = $request('/verify-email', ['verification_key' => $badKey]);
    $check($status === 403 && !str_contains($html, $badKey), 'HTTP key not reflected on CSRF failure');
    [$status, $html] = $request('/verify-email', ['csrf_token' => $csrf, 'verification_key' => $badKey]);
    $check($status === 303, 'HTTP script-free malformed manual confirmation PRG');
    [$status, $html] = $request('/verify-email/result');
    $check($status === 200 && str_contains($html, '?route=/verify-email/resend'), 'HTTP invalid result recovery');
    [$status] = $request('/verify-email/result'); $check($status === 303, 'HTTP result one-time');
    [$status, $html] = $request('/verify-email/resend', ['csrf_token' => $csrf, 'email' => '<script>@example.invalid']);
    $check($status === 422 && str_contains($html, '&lt;script&gt;'), 'HTTP invalid email escaped');
    foreach (['app/Mail/MailTransport.php', 'app/Services/EmailVerificationService.php'] as $privatePath) {
        $privateContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        @file_get_contents('http://localhost/GigHub/' . $privatePath, false, $privateContext);
        $check(str_contains($http_response_header[0] ?? '', ' 403 '), 'Private verification source must remain denied');
    }
    echo "PASS: {$checks} verification controller/HTTP checks; no fixture writes.\n";
} catch (Throwable $error) { fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n"); exit(1); }
