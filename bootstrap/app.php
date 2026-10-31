<?php
declare(strict_types=1);

use GigHub\Core\Config;
use GigHub\Core\Router;
use GigHub\Core\SecureSession;
use GigHub\Core\Database;
use GigHub\Controllers\RegistrationController;
use GigHub\Repositories\UserRepository;
use GigHub\Services\RegistrationService;
use GigHub\Controllers\HealthController;
use GigHub\Controllers\HomeController;
use GigHub\Controllers\EmailVerificationController;
use GigHub\Services\EmailVerificationService;
use GigHub\Mail\InMemoryMailTransport;

$root = dirname(__DIR__);
foreach (['Config', 'Database', 'SecureSession', 'Router', 'Csrf', 'RegistrationThrottle', 'VerificationThrottle'] as $class) {
    require_once $root . '/app/Core/' . $class . '.php';
}
require_once $root . '/app/Controllers/HomeController.php';
require_once $root . '/app/Controllers/HealthController.php';
require_once $root . '/app/Controllers/RegistrationController.php';
require_once $root . '/app/Controllers/EmailVerificationController.php';
require_once $root . '/app/Repositories/UserRepository.php';
require_once $root . '/app/Repositories/VerificationTokenRepository.php';
foreach (['MailTransport', 'VerificationMail', 'InMemoryMailTransport'] as $class) {
    require_once $root . '/app/Mail/' . $class . '.php';
}
require_once $root . '/app/Services/EmailVerificationService.php';
foreach (['RegistrationValidator', 'RegistrationValidationException', 'RegistrationService'] as $class) {
    require_once $root . '/app/Services/' . $class . '.php';
}

date_default_timezone_set('UTC');
$config = Config::load($root . '/config');
SecureSession::start($config['session']);
$router = new Router();
$verificationFactory = static function () use ($config): EmailVerificationService {
    $pdo = (new Database($config['database']))->connection();
    // 10B-A has no SMTP adapter or configurable external transport.
    return new EmailVerificationService($pdo, new InMemoryMailTransport(), $config['verification']['base_url']);
};
$registration = new RegistrationController(static function () use ($config): RegistrationService {
    $pdo = (new Database($config['database']))->connection();
    return new RegistrationService($pdo, new UserRepository($pdo));
}, $verificationFactory);
$verification = new EmailVerificationController($verificationFactory);
$router->get('/verify-email', static fn () => $verification->show());
$router->post('/verify-email', static fn () => $verification->confirm($_POST));
$router->get('/verify-email/resend', static fn () => $verification->resendForm());
$router->post('/verify-email/resend', static fn () => $verification->resend($_POST));
$router->get('/verify-email/result', static fn () => $verification->result());
$router->get('/register', static fn () => $registration->show());
$router->post('/register', static fn () => $registration->submit($_POST));
$router->get('/register/pending', static fn () => $registration->pending());
$router->get('/', static fn () => (new HomeController())->show());
$router->get('/health', static fn () => (new HealthController())->show(
    $config['app']['environment'],
    $_SERVER['REMOTE_ADDR'] ?? ''
));
// No Database instance is connected or queried during bootstrap or health checks.
return $router;
