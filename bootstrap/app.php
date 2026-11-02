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
use GigHub\Core\PublicIdentity;
use GigHub\Services\AuthenticationService;
use GigHub\Controllers\AuthenticationController;
use GigHub\Controllers\AccountHomeController;

$root = dirname(__DIR__);
// Optional while mail is disabled; SMTP configuration fails closed without PHPMailer.
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
foreach (['Config', 'Database', 'SecureSession', 'Router', 'Csrf', 'RegistrationThrottle', 'VerificationThrottle', 'LoginThrottle', 'AuthSession', 'AuthGuard', 'PublicIdentity'] as $class) {
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
foreach (['Repositories/JobRepository','Repositories/SkillRepository','Services/JobPostingValidator','Services/JobPostingException','Services/JobPostingService','Controllers/ClientJobController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$jobFactory=static function() use ($config): \GigHub\Services\JobPostingService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\JobPostingService($pdo,new \GigHub\Repositories\JobRepository($pdo),new \GigHub\Repositories\SkillRepository($pdo));
};
$clientJobs=new \GigHub\Controllers\ClientJobController(new AuthGuard($authenticationFactory,$authSession),$jobFactory);
foreach (['Repositories/PublicJobRepository','Services/JobBrowseValidator','Controllers/PublicJobController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$publicRepositoryFactory=static function() use ($config): array {
    $pdo=(new Database($config['database']))->connection();
    return [new \GigHub\Repositories\PublicJobRepository($pdo),new \GigHub\Repositories\SkillRepository($pdo)];
};
$publicIdentity = new PublicIdentity($authSession, static fn(int $id): ?array => $authenticationFactory()->identity($id));
foreach (['Repositories/FreelancerProfileRepository','Services/FreelancerProfileValidator','Services/FreelancerProfileException','Services/FreelancerProfileService','Controllers/FreelancerProfileController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$profileFactory=static function() use ($config): \GigHub\Services\FreelancerProfileService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\FreelancerProfileService($pdo,new \GigHub\Repositories\FreelancerProfileRepository($pdo),new \GigHub\Repositories\SkillRepository($pdo));
};
$profiles=new \GigHub\Controllers\FreelancerProfileController(new AuthGuard($authenticationFactory,$authSession),$profileFactory,$publicIdentity);
$router->get('/freelancer/profile',static fn()=>$profiles->own());
$router->get('/freelancer/profile/edit',static fn()=>$profiles->own(true));
$router->post('/freelancer/profile/edit',static fn()=>$profiles->update($_POST));
$router->get('/freelancers/show',static fn()=>$profiles->show($_GET));
foreach (['Repositories/BidRepository','Services/BidValidator','Services/BidException','Services/BidService','Controllers/BidController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$bidFactory=static function() use ($config): \GigHub\Services\BidService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\BidService($pdo,new \GigHub\Repositories\BidRepository($pdo));
};
$bids=new \GigHub\Controllers\BidController(new AuthGuard($authenticationFactory,$authSession),$bidFactory);
$router->get('/freelancer/bids',static fn()=>$bids->index($_GET));
$router->get('/freelancer/bids/create',static fn()=>$bids->form($_GET));
$router->post('/freelancer/bids/create',static fn()=>$bids->save($_POST));
$router->get('/freelancer/bids/edit',static fn()=>$bids->form($_GET,true));
$router->post('/freelancer/bids/update',static fn()=>$bids->save($_POST,true));
$router->post('/freelancer/bids/withdraw',static fn()=>$bids->withdraw($_POST));
$router->get('/client/jobs/bids',static fn()=>$bids->client($_GET));
require_once $root.'/app/Services/AwardService.php';
require_once $root.'/app/Controllers/AwardController.php';
$awardFactory=static function() use ($config): \GigHub\Services\AwardService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\AwardService($pdo,new \GigHub\Repositories\BidRepository($pdo));
};
$awards=new \GigHub\Controllers\AwardController(new AuthGuard($authenticationFactory,$authSession),$awardFactory);
$router->post('/client/jobs/award',static fn()=>$awards->award($_POST));
$publicJobs=new \GigHub\Controllers\PublicJobController($publicRepositoryFactory, null, $publicIdentity);
$router->get('/jobs',static fn()=>$publicJobs->index($_GET));
$router->get('/jobs/show',static fn()=>$publicJobs->show($_GET));
$router->get('/client/jobs',static fn()=>$clientJobs->index($_GET));
$router->get('/client/jobs/create',static fn()=>$clientJobs->create());
$router->post('/client/jobs/create',static fn()=>$clientJobs->store($_POST));
$router->get('/client/jobs/show',static fn()=>$clientJobs->show($_GET));
$router->get('/client/jobs/edit',static fn()=>$clientJobs->edit($_GET));
$router->post('/client/jobs/update',static fn()=>$clientJobs->update($_POST));
$router->post('/client/jobs/publish',static fn()=>$clientJobs->publish($_POST));
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
$router->get('/', static fn () => (new HomeController($publicIdentity, $publicRepositoryFactory))->show($_GET));
$router->get('/health', static fn () => (new HealthController())->show(
    $config['app']['environment'],
    $_SERVER['REMOTE_ADDR'] ?? ''
));
// No Database instance is connected or queried during bootstrap or health checks.
return $router;
