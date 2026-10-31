<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Script-free HTTP client: no account can be created by these invalid requests.
$checks = 0; $cookie = '';
$check = static function (bool $value, string $label) use (&$checks): void {
    if (!$value) { throw new RuntimeException($label); } $checks++;
};
$request = static function (?array $post = null) use (&$cookie): array {
    $headers = ['Connection: close'];
    if ($cookie !== '') { $headers[] = 'Cookie: ' . $cookie; }
    if ($post !== null) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    $context = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
        'content' => $post === null ? '' : http_build_query($post), 'ignore_errors' => true,
        'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = @file_get_contents('http://localhost/GigHub/public/index.php?route=/register', false, $context);
    if (!is_string($body)) { throw new RuntimeException('Local HTTP unavailable'); }
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) { $cookie = $match[1]; }
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    return [(int) ($status[1] ?? 0), $body];
};
try {
    [$status, $html] = $request();
    $check($status === 200, 'GET form');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $match);
    $check(isset($match[1]), 'CSRF token available'); $token = $match[1];
    $check(strpos($html, 'id="create-account"') < strpos($html, 'id="role-update"'), 'creation precedes fallback without scripts');
    [$status, $html] = $request(['csrf_token' => $token, 'intent' => 'update_role', 'role' => 'client', 'display_name' => 'Synthetic HTTP Review']);
    $check($status === 200 && str_contains($html, 'value="Synthetic HTTP Review"'), 'script-free role refresh keeps values');
    $check(!preg_match('/id="client-profile"[^>]*(?:hidden|disabled)/', $html), 'script-free client fields available');
    [$status, $html] = $request(['csrf_token' => $token, 'intent' => 'update_role', 'role' => 'freelancer', 'company_name' => 'stale']);
    $check($status === 200 && (bool) preg_match('/id="client-profile"[^>]*hidden[^>]*disabled/', $html), 'script-free freelancer fields protected');
    $base = ['csrf_token' => $token, 'display_name' => "Synthetic\x7FReview", 'email' => 'review@example.invalid', 'role' => 'client'];
    foreach ([11 => false, 12 => true, 128 => true, 129 => false] as $length => $accepted) {
        $password = mb_substr(str_repeat("\u{1F600}\u{1F680}", 65), 0, $length, 'UTF-8');
        [$status, $html] = $request($base + ['password' => $password, 'password_confirmation' => $password]);
        $check($status === 422 && str_contains($html, 'id="display_name-error"'), 'forced validation failure prevents database access');
        $check(!str_contains($html, 'id="password-error"') === $accepted, 'script-free Unicode boundary ' . $length);
    }
    [$status, $html] = $request($base + ['password' => '123456789012', 'password_confirmation' => '123456789012']);
    $check($status === 422 && str_contains($html, 'id="password-error"'), 'common password rejected over HTTP');
    $check(str_contains($html, 'id="password-error">This password is too easy to guess. Try a longer, less predictable password.</p>'), 'specific common-password error directly below field');
    $check(str_contains($html, 'aria-describedby="password-help password-error"'), 'HTTP password error associated');
    [$status, $html] = $request(['csrf_token' => 'bad', 'display_name' => '<b>Review</b>', 'password' => 'Never reflect this synthetic value']);
    $check($status === 403 && str_contains($html, '&lt;b&gt;Review&lt;/b&gt;'), 'HTTP CSRF rejection preserves escaped name');
    $check(!str_contains($html, 'Never reflect this synthetic value') && str_contains($html, 'Registration needs attention'), 'HTTP error title and no password reflection');
    echo "PASS: {$checks} registration HTTP checks; script-free requests; no database writes.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL: Registration HTTP checks stopped: " . $error->getMessage() . "\n"); exit(1);
}
