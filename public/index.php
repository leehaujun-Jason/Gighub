<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(static function (Throwable $error): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('[GigHub] Unhandled application error: ' . get_class($error));
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'An application error occurred.';
});

$router = require dirname(__DIR__) . '/bootstrap/app.php';
$response = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_GET['route'] ?? '/');
http_response_code($response['status']);
header('Content-Type: ' . $response['type']);
if ($response['status'] === 405) {
    header('Allow: GET');
}
echo $response['body'];
ob_end_flush();
