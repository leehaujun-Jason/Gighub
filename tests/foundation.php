<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
if (!is_file($root . '/app/Core/Config.php')) {
    fwrite(STDERR, "FAIL: PHP foundation is not implemented yet.\n");
    exit(1);
}
require $root . '/app/Core/Config.php';
require $root . '/app/Core/Database.php';
require $root . '/app/Core/SecureSession.php';
require $root . '/app/Core/Router.php';
require $root . '/app/Controllers/HealthController.php';

use GigHub\Core\Config;
use GigHub\Core\Database;
use GigHub\Core\Router;
use GigHub\Core\SecureSession;
use GigHub\Controllers\HealthController;

$count = 0;
$check = static function (bool $condition, string $label) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $count++;
};
$reject = static function (callable $operation, string $label) use ($check): void {
    try {
        $operation();
    } catch (RuntimeException $error) {
        $check($error->getMessage() === 'Database connection unavailable.', $label);
        return;
    }
    throw new RuntimeException('FAIL: ' . $label);
};

$config = Config::load($root . '/config', false);
$check($config['database']['enabled'] === false, 'database disabled by default');
$check($config['database']['name'] === '', 'no implicit database name');
$check($config['database']['username'] === '', 'no implicit database username');
$check($config['database']['password'] === '', 'no stored credentials');
$reject(static fn () => (new Database($config['database']))->connection(), 'disabled connection rejected before PDO');
$enabled = array_replace($config['database'], ['enabled' => true]);
$reject(static fn () => (new Database($enabled))->connection(), 'missing settings rejected before PDO');
$base = array_replace($enabled, ['host' => 'localhost', 'name' => 'placeholder', 'username' => 'placeholder']);
$reject(static fn () => (new Database(array_replace($base, ['host' => 'localhost;dbname=other'])))->connection(), 'DSN injection rejected');
$reject(static fn () => (new Database(array_replace($base, ['name' => 'gighub_schema_test'])))->connection(), 'isolated test database protected');
$reject(static fn () => Database::forSchemaValidation($base), 'CLI validation refuses any other database');
$reject(static fn () => (new Database(array_replace($base, ['name' => 'unexpected_database'])))->connection(), 'normal connection refuses unexpected database');
$reject(static fn () => (new Database(array_replace($base, ['port' => 0])))->connection(), 'invalid port rejected');

SecureSession::start($config['session']);
$check(session_status() === PHP_SESSION_ACTIVE, 'session starts');
$cookie = session_get_cookie_params();
$check($cookie['httponly'] && $cookie['samesite'] === 'Lax', 'session cookie protections');
$check(ini_get('session.use_strict_mode') === '1', 'strict session IDs');
$check(ini_get('session.use_only_cookies') === '1' && ini_get('session.use_trans_sid') === '0', 'cookie-only session IDs');
session_write_close();

$router = new Router();
$router->get('/', static fn () => ['status' => 200, 'type' => 'text/plain; charset=UTF-8', 'body' => 'ready']);
$check($router->dispatch('GET', '/')['body'] === 'ready', 'registered route');
$check($router->dispatch('GET', '/missing')['status'] === 404, 'unknown route');
$check($router->dispatch('POST', '/')['status'] === 405, 'method rejected');
$check($router->dispatch('GET', ['bad'])['status'] === 400, 'array route rejected');
$health = new HealthController();
$check($health->show('local', '127.0.0.1')['status'] === 200, 'loopback health allowed');
$check($health->show('local', '192.0.2.1')['status'] === 404, 'non-loopback health hidden');
$check($health->show('production', '127.0.0.1')['status'] === 404, 'production health hidden');
$check(json_decode($health->show('local', '::1')['body'], true)['database'] === 'not_checked', 'health makes no connectivity claim');
echo "PASS: {$count} foundation checks; no database connection attempted.\n";
