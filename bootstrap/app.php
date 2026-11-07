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
foreach (['Config', 'Database', 'SecureSession', 'Router', 'Csrf', 'Notification', 'RegistrationThrottle', 'VerificationThrottle', 'LoginThrottle', 'AuthSession', 'AuthGuard', 'PublicIdentity'] as $class) {
    require_once $root . '/app/Core/' . $class . '.php';
}
require_once $root . '/app/Controllers/HomeController.php';
require_once $root . '/app/Controllers/HealthController.php';
require_once $root . '/app/Controllers/RegistrationController.php';
require_once $root . '/app/Controllers/EmailVerificationController.php';
require_once $root . '/app/Repositories/UserRepository.php';
require_once $root . '/app/Repositories/VerificationTokenRepository.php';
require_once $root . '/app/Repositories/NotificationRepository.php';
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
    return new \GigHub\Services\BidService($pdo,new \GigHub\Repositories\BidRepository($pdo),new \GigHub\Repositories\NotificationRepository($pdo));
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
    return new \GigHub\Services\AwardService($pdo,new \GigHub\Repositories\BidRepository($pdo),new \GigHub\Repositories\NotificationRepository($pdo));
};
$awards=new \GigHub\Controllers\AwardController(new AuthGuard($authenticationFactory,$authSession),$awardFactory);
$router->post('/client/jobs/award',static fn()=>$awards->award($_POST));
foreach (['Repositories/WalletRepository','Services/WalletException','Services/WalletService','Controllers/WalletController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$walletFactory=static function() use ($config): \GigHub\Services\WalletService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\WalletService($pdo,new \GigHub\Repositories\WalletRepository($pdo));
};
$wallets=new \GigHub\Controllers\WalletController(new AuthGuard($authenticationFactory,$authSession),$walletFactory);
$router->get('/client/wallet',static fn()=>$wallets->show($_GET));
$router->get('/freelancer/wallet',static fn()=>$wallets->showFreelancer($_GET));
$router->post('/client/wallet/claim-demo-credit',static fn()=>$wallets->claimDemoCredit($_POST));
foreach (['Repositories/MilestoneRepository','Services/MilestoneValidator','Services/MilestoneException','Services/MilestoneService','Controllers/MilestoneController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$milestoneFactory=static function() use ($config): \GigHub\Services\MilestoneService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\MilestoneService($pdo,new \GigHub\Repositories\MilestoneRepository($pdo));
};
$milestones=new \GigHub\Controllers\MilestoneController(new AuthGuard($authenticationFactory,$authSession),$milestoneFactory);
$router->get('/client/jobs/milestones',static fn()=>$milestones->index($_GET));
$router->get('/freelancer/jobs/milestones',static fn()=>$milestones->freelancerIndex($_GET));
$router->post('/client/jobs/milestones/create',static fn()=>$milestones->create($_POST));
$router->get('/client/jobs/milestones/edit',static fn()=>$milestones->edit($_GET));
$router->post('/client/jobs/milestones/update',static fn()=>$milestones->update($_POST));
$router->post('/client/jobs/milestones/delete',static fn()=>$milestones->delete($_POST));
foreach (['Repositories/EscrowRepository','Services/EscrowException','Services/EscrowService','Controllers/EscrowController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$escrowFactory=static function() use ($config): \GigHub\Services\EscrowService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\EscrowService($pdo,new \GigHub\Repositories\EscrowRepository($pdo),new \GigHub\Repositories\NotificationRepository($pdo));
};
$escrows=new \GigHub\Controllers\EscrowController(new AuthGuard($authenticationFactory,$authSession),$escrowFactory);
$router->get('/client/jobs/fund',static fn()=>$escrows->preview($_GET));
$router->post('/client/jobs/fund/confirm',static fn()=>$escrows->confirm($_POST));
$router->post('/client/jobs/escrow/release',static fn()=>$escrows->release($_POST));
foreach (['Repositories/SubmissionRepository','Services/SubmissionException','Services/SubmissionValidator','Services/ReviewValidator','Services/SubmissionService','Controllers/SubmissionController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$submissionFactory=static function() use ($config): \GigHub\Services\SubmissionService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\SubmissionService($pdo,new \GigHub\Repositories\SubmissionRepository($pdo),new \GigHub\Repositories\NotificationRepository($pdo));
};
$submissions=new \GigHub\Controllers\SubmissionController(new AuthGuard($authenticationFactory,$authSession),$submissionFactory);
$router->get('/freelancer/milestones/submit',static fn()=>$submissions->createForm($_GET));
$router->post('/freelancer/milestones/submit',static fn()=>$submissions->submit($_POST));
$router->post('/client/milestones/approve',static fn()=>$submissions->approve($_POST));
$router->post('/client/milestones/request-revision',static fn()=>$submissions->requestRevision($_POST));
$router->get('/freelancer/jobs/final-submit',static fn()=>$submissions->finalSubmitForm($_GET));
$router->post('/freelancer/jobs/final-submit',static fn()=>$submissions->submitFinal($_POST));
$router->post('/client/jobs/final-review/approve',static fn()=>$submissions->approveFinal($_POST));
$router->post('/client/jobs/final-review/request-revision',static fn()=>$submissions->requestFinalRevision($_POST));
foreach (['Repositories/ReviewRepository','Services/ReviewSubmissionValidator','Services/ReviewException','Services/ReviewService','Controllers/ReviewController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$reviewFactory=static function() use ($config): \GigHub\Services\ReviewService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\ReviewService($pdo,new \GigHub\Repositories\ReviewRepository($pdo));
};
$reviews=new \GigHub\Controllers\ReviewController(new AuthGuard($authenticationFactory,$authSession),$reviewFactory);
$router->get('/jobs/review',static fn()=>$reviews->createForm($_GET));
$router->post('/jobs/review',static fn()=>$reviews->store($_POST));
foreach (['Repositories/NotificationRepository','Services/NotificationService','Controllers/NotificationController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$notificationFactory=static function() use ($config): array {
    $pdo=(new Database($config['database']))->connection();
    $repo=new \GigHub\Repositories\NotificationRepository($pdo);
    $service=new \GigHub\Services\NotificationService($repo);
    return [$repo,$service];
};
$notifications=new \GigHub\Controllers\NotificationController(new AuthGuard($authenticationFactory,$authSession),$notificationFactory);
$router->get('/notifications',static fn()=>$notifications->index($_GET));
$router->post('/notifications/read',static fn()=>$notifications->markRead($_POST));
$router->post('/notifications/read-all',static fn()=>$notifications->markAllRead($_POST));
$router->get('/notifications/count',static fn()=>$notifications->unreadCount());
require_once $root.'/app/Repositories/ClientWorkspaceRepository.php';
require_once $root.'/app/Controllers/ClientWorkspaceController.php';
$clientWorkspaceFactory=static function() use ($config): \GigHub\Repositories\ClientWorkspaceRepository {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Repositories\ClientWorkspaceRepository($pdo);
};
$clientWorkspace=new \GigHub\Controllers\ClientWorkspaceController(new AuthGuard($authenticationFactory,$authSession),$clientWorkspaceFactory);
foreach (['Services/MatchingService','Repositories/JobMatchingRepository','Controllers/FreelancerWorkspaceController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$matchingFactory=static function() use ($config): array {
    $pdo=(new Database($config['database']))->connection();
    return [new \GigHub\Repositories\JobMatchingRepository($pdo),new \GigHub\Services\MatchingService()];
};
$freelancerWorkspace=new \GigHub\Controllers\FreelancerWorkspaceController(new AuthGuard($authenticationFactory,$authSession),$matchingFactory,$profileFactory);
$publicJobs=new \GigHub\Controllers\PublicJobController($publicRepositoryFactory, null, $publicIdentity, $matchingFactory);
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
$router->get('/client',static fn()=>$clientWorkspace->index());
$router->get('/freelancer',static fn()=>$freelancerWorkspace->index());
$router->post('/freelancer/skills',static fn()=>$freelancerWorkspace->updateSkills($_POST));
$router->get('/admin',static fn()=>$accountHome->show('admin'));
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
