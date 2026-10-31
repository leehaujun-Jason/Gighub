<?php
declare(strict_types=1);

use GigHub\Core\Config;
use GigHub\Core\Router;
use GigHub\Core\SecureSession;
use GigHub\Controllers\HealthController;
use GigHub\Controllers\HomeController;

$root = dirname(__DIR__);
foreach (['Config', 'Database', 'SecureSession', 'Router'] as $class) {
    require_once $root . '/app/Core/' . $class . '.php';
}
require_once $root . '/app/Controllers/HomeController.php';
require_once $root . '/app/Controllers/HealthController.php';

date_default_timezone_set('UTC');
$config = Config::load($root . '/config');
SecureSession::start($config['session']);
$router = new Router();
$router->get('/', static fn () => (new HomeController())->show());
$router->get('/health', static fn () => (new HealthController())->show(
    $config['app']['environment'],
    $_SERVER['REMOTE_ADDR'] ?? ''
));
// No Database instance is connected or queried during bootstrap or health checks.
return $router;
