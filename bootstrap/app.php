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
use GigHub\Mail\MailTransportFactory;
use GigHub\Core\AuthSession;
use GigHub\Core\AuthGuard;
use GigHub\Services\AuthenticationService;
use GigHub\Controllers\AuthenticationController;
use GigHub\Controllers\AccountHomeController;

$root = dirname(__DIR__);
// Optional while mail is disabled; SMTP configuration fails closed without PHPMailer.
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
foreach (['Config', 'Database', 'SecureSession', 'Router', 'Csrf', 'RegistrationThrottle', 'VerificationThrottle', 'LoginThrottle', 'AuthSession', 'AuthGuard'] as $class) {
    require_once $root . '/app/Core/' . $class . '.php';
}
require_once $root . '/app/Controllers/HomeController.php';
require_once $root . '/app/Controllers/HealthController.php';
require_once $root . '/app/Controllers/RegistrationController.php';
require_once $root . '/app/Controllers/EmailVerificationController.php';
require_once $root . '/app/Repositories/UserRepository.php';
require_once $root . '/app/Repositories/VerificationTokenRepository.php';
foreach (['MailTransport', 'VerificationMail', 'InMemoryMailTransport', 'DisabledMailTransport', 'SmtpDiagnostics', 'PhpMailerTransport', 'MailTransportFactory'] as $class) {
    require_once $root . '/app/Mail/' . $class . '.php';
}
if (class_exists(\PHPMailer\PHPMailer\SMTP::class)) { require_once $root . '/app/Mail/DiagnosticSmtp.php'; }
require_once $root . '/app/Services/EmailVerificationService.php';
foreach (['RegistrationValidator', 'RegistrationValidationException', 'RegistrationService'] as $class) {
    require_once $root . '/app/Services/' . $class . '.php';
}

date_default_timezone_set('UTC');
$config = Config::load($root . '/config');
SecureSession::start($config['session']);
$router = new Router();
foreach (['Services/LoginValidator','Services/AuthenticationService','Controllers/AuthenticationController','Controllers/AccountHomeController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$authenticationFactory=static function() use ($config): AuthenticationService {
    $pdo=(new Database($config['database']))->connection();
    return new AuthenticationService(new UserRepository($pdo));
};
$authSession=new AuthSession();
$authentication=new AuthenticationController($authenticationFactory,$authSession);
$accountHome=new AccountHomeController(new AuthGuard($authenticationFactory,$authSession));
$router->get('/login',static fn()=>$authentication->show());
$router->post('/login',static fn()=>$authentication->login($_POST));
$router->post('/logout',static fn()=>$authentication->logout($_POST));
foreach (['client','freelancer','admin'] as $role) {
    $router->get('/'.$role,static fn()=>$accountHome->show($role));
}
$verificationFactory = static function () use ($config): EmailVerificationService {
    $pdo = (new Database($config['database']))->connection();
    return new EmailVerificationService($pdo, MailTransportFactory::create($config['mail'], $config['app']['environment']), $config['verification']['base_url']);
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
