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
foreach (['Repositories/ProfessionalDetailsRepository','Services/ProfessionalDetails','Controllers/ProfessionalDetailsController'] as $class) {
    require_once $root . '/app/' . $class . '.php';
}
$detailsFactory = static fn () => new \GigHub\Repositories\ProfessionalDetailsRepository((new Database($config['database']))->connection());
$details = new \GigHub\Controllers\ProfessionalDetailsController(new AuthGuard($authenticationFactory,$authSession), $detailsFactory);
$router->get('/freelancer/profile/details', static fn () => $details->form($_GET));
$router->post('/freelancer/profile/details', static fn () => $details->form($_GET, $_POST));
$profiles=new \GigHub\Controllers\FreelancerProfileController(new AuthGuard($authenticationFactory,$authSession),$profileFactory,$publicIdentity,$detailsFactory);
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
$router->post('/freelancer/bids/create',static fn()=>$bids->save(['_proposal'=>$_FILES['proposal']??null]+$_POST));
$router->get('/freelancer/bids/edit',static fn()=>$bids->form($_GET,true));
$router->post('/freelancer/bids/update',static fn()=>$bids->save(['_proposal'=>$_FILES['proposal']??null]+$_POST,true));
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
foreach (['Repositories/DisputeRepository','Services/DisputeException','Services/DisputeService','Controllers/DisputeController','Controllers/AdminDisputeController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$disputeFactory=static function() use ($config): \GigHub\Services\DisputeService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\DisputeService($pdo,new \GigHub\Repositories\DisputeRepository($pdo));
};
$disputes=new \GigHub\Controllers\DisputeController(new AuthGuard($authenticationFactory,$authSession),$disputeFactory);
$adminDisputes=new \GigHub\Controllers\AdminDisputeController(new AuthGuard($authenticationFactory,$authSession),$disputeFactory);
$router->post('/jobs/cancellation',static fn()=>$disputes->requestCancellation($_POST));
$router->post('/jobs/cancellation/respond',static fn()=>$disputes->respondCancellation($_POST));
$router->post('/jobs/dispute',static fn()=>$disputes->raiseDispute($_POST));
$router->post('/jobs/dispute/respond',static fn()=>$disputes->submitDisputeResponse($_POST));
foreach (['Repositories/MilestoneRepository','Services/MilestoneValidator','Services/MilestoneException','Services/MilestoneService','Controllers/MilestoneController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$milestoneFactory=static function() use ($config): \GigHub\Services\MilestoneService {
    $pdo=(new Database($config['database']))->connection();
    return new \GigHub\Services\MilestoneService($pdo,new \GigHub\Repositories\MilestoneRepository($pdo));
};
$milestones=new \GigHub\Controllers\MilestoneController(new AuthGuard($authenticationFactory,$authSession),$milestoneFactory,$disputeFactory);
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
foreach (['Repositories/MessageRepository','Services/MessageService','Services/ChatAttachmentStorage','Controllers/MessageController'] as $class) {
    require_once $root.'/app/'.$class.'.php';
}
$messageFactory=static function() use ($config): array {
    $pdo=(new Database($config['database']))->connection();
    $repo=new \GigHub\Repositories\MessageRepository($pdo);
    $service=new \GigHub\Services\MessageService();
    return [$repo,$service];
};
$messages=new \GigHub\Controllers\MessageController(new AuthGuard($authenticationFactory,$authSession),$messageFactory);
$router->get('/messages',static fn()=>$messages->inbox());
$router->get('/messages/thread',static fn()=>$messages->thread($_GET));
$router->post('/messages/send',static fn()=>$messages->send($_POST, $_FILES['attachment'] ?? null, $_FILES['attachments'] ?? null));
$router->get('/messages/attachment',static fn()=>$messages->attachment($_GET));
$router->get('/messages/poll',static fn()=>$messages->poll($_GET));
$router->post('/messages/read',static fn()=>$messages->read($_POST));
$router->get('/messages/count',static fn()=>$messages->unreadCount());
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
$router->post('/client/jobs/create',static fn()=>$clientJobs->store(['_documents'=>$_FILES['documents']??[]]+$_POST));
$router->get('/client/jobs/show',static fn()=>$clientJobs->show($_GET));
$router->get('/client/jobs/edit',static fn()=>$clientJobs->edit($_GET));
$router->post('/client/jobs/update',static fn()=>$clientJobs->update(['_documents'=>$_FILES['documents']??[]]+$_POST));
$router->post('/client/jobs/publish',static fn()=>$clientJobs->publish($_POST));
$router->post('/client/jobs/delete',static fn()=>$clientJobs->delete($_POST));
require_once $root.'/app/Services/MarketplaceDocuments.php';
$router->get('/marketplace/document', static function () use ($config,$authenticationFactory,$authSession): array {
    $missing=['status'=>404,'type'=>'text/plain; charset=UTF-8','body'=>'Document not found.'];
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) return $missing;
    try {
        $pdo=(new Database($config['database']))->connection();
        $access=(new AuthGuard($authenticationFactory,$authSession))->check();
        $user=$access['user']??[];
        if (($_GET['kind']??null)==='proposal') {
            $q=$pdo->prepare("SELECT b.proposal_document_key AS storage_key,b.proposal_document_name AS display_name
                FROM bids b JOIN jobs j ON j.id=b.job_id WHERE b.id=? AND b.proposal_document_key IS NOT NULL
                AND ((?='client' AND j.client_id=?) OR (?='freelancer' AND b.freelancer_id=?))");
            $q->execute([$id,$user['role']??'',$user['id']??0,$user['role']??'',$user['id']??0]);
        } elseif (($_GET['kind']??null)==='job') {
            $q=$pdo->prepare('SELECT d.storage_key,d.display_name,d.job_id,j.client_id FROM job_requirement_documents d JOIN jobs j ON j.id=d.job_id WHERE d.id=?');
            $q->execute([$id]);
            $file=$q->fetch();
            if (!$file) return $missing;
            if (!(($user['role']??null)==='client' && (int)$user['id']===(int)$file['client_id'])
                && ($user['role']??null)!=='admin'
                && (new \GigHub\Repositories\PublicJobRepository($pdo))->find((int)$file['job_id'])===false) return $missing;
            return \GigHub\Services\MarketplaceDocuments::response($file);
        } else return $missing;
        $file=$q->fetch();
        return $file ? \GigHub\Services\MarketplaceDocuments::response($file) : $missing;
    } catch (\Throwable) {
        return $missing;
    }
});
foreach (['Repositories/PasswordResetRepository', 'Services/PasswordResetService', 'Controllers/PasswordResetController'] as $class) {
    require_once $root . '/app/' . $class . '.php';
}
$passwordReset = new \GigHub\Controllers\PasswordResetController(static function () use ($config): \GigHub\Services\PasswordResetService {
    $pdo = (new Database($config['database']))->connection();
    return new \GigHub\Services\PasswordResetService(
        $pdo,
        new \GigHub\Repositories\PasswordResetRepository($pdo),
        static fn () => MailTransportFactory::create($config['mail'], $config['app']['environment']),
        $config['verification']['base_url'],
    );
}, $authSession);
$router->get('/forgot-password', static fn () => $passwordReset->page('request'));
$router->post('/forgot-password', static fn () => $passwordReset->request($_POST));
$router->get('/forgot-password/sent', static fn () => $passwordReset->page('sent'));
$router->get('/reset-password', static fn () => $passwordReset->page('reset'));
$router->post('/reset-password', static fn () => $passwordReset->confirm($_POST));
$router->get('/reset-password/success', static fn () => $passwordReset->success());
$router->get('/login',static fn()=>$authentication->show());
$router->post('/login',static fn()=>$authentication->login($_POST));
$router->post('/logout',static fn()=>$authentication->logout($_POST));
foreach (['Repositories/OnboardingRepository', 'Services/OnboardingService', 'Controllers/OnboardingController'] as $class) {
    require_once $root . '/app/' . $class . '.php';
}
$onboarding = new \GigHub\Controllers\OnboardingController(
    new AuthGuard($authenticationFactory, $authSession),
    static function () use ($config): \GigHub\Services\OnboardingService {
        $pdo = (new Database($config['database']))->connection();
        return new \GigHub\Services\OnboardingService(
            $pdo, new \GigHub\Repositories\OnboardingRepository($pdo), new \GigHub\Repositories\SkillRepository($pdo),
        );
    },
    $detailsFactory,
);
foreach (['Repositories/AvatarRepository', 'Services/AvatarStorage', 'Controllers/AvatarController'] as $class) {
    require_once $root . '/app/' . $class . '.php';
}
$avatars = new \GigHub\Controllers\AvatarController(
    new AuthGuard($authenticationFactory, $authSession),
    static fn () => new \GigHub\Repositories\AvatarRepository((new Database($config['database']))->connection()),
    new \GigHub\Services\AvatarStorage($config['avatar_storage']),
);
$router->get('/profile/photo', static fn () => $avatars->show($_GET));
$router->post('/profile/photo', static fn () => $avatars->change($_POST, $_FILES));
require_once $root . '/app/Services/ClientLocation.php';
$router->get('/profile/setup', static fn () => $onboarding->setup());
$router->post('/profile/setup', static fn () => $onboarding->setup($_POST));
require_once $root . '/app/Repositories/ClientProfileRepository.php';
require_once $root . '/app/Controllers/ClientProfileController.php';
$clientProfile = new \GigHub\Controllers\ClientProfileController(
    new AuthGuard($authenticationFactory, $authSession),
    static fn () => new \GigHub\Repositories\ClientProfileRepository((new Database($config['database']))->connection()),
);
$router->get('/client/profile', static fn () => $clientProfile->show());
$router->get('/client/profile/edit', static fn () => $clientProfile->show(true));
$router->post('/client/profile/edit', static fn () => $clientProfile->show(true, $_POST));
$router->get('/client',static fn()=>$clientWorkspace->index());
$router->get('/freelancer',static fn()=>$freelancerWorkspace->index());
$router->post('/freelancer/skills',static fn()=>$freelancerWorkspace->updateSkills($_POST));
$router->get('/admin',static fn()=>$adminDisputes->index($_GET));
$router->get('/admin/disputes',static fn()=>$adminDisputes->index($_GET));
$router->get('/admin/disputes/show',static fn()=>$adminDisputes->show($_GET));
$router->post('/admin/disputes/resolve',static fn()=>$adminDisputes->resolve($_POST));
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
foreach (['freelancer', 'client'] as $registrationRole) {
    $router->get('/register/' . $registrationRole, static fn () => $registration->show($registrationRole));
    $router->post('/register/' . $registrationRole, static fn () => $registration->submit($_POST, $registrationRole));
}
$router->get('/register/pending', static fn () => $registration->pending());
$router->get('/', static fn () => (new HomeController($publicIdentity, $publicRepositoryFactory))->show($_GET));
$router->get('/health', static fn () => (new HealthController())->show(
    $config['app']['environment'],
    $_SERVER['REMOTE_ADDR'] ?? ''
));
// No Database instance is connected or queried during bootstrap or health checks.
return $router;
