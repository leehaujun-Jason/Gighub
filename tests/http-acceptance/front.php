<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
set_exception_handler(static function(Throwable $e):void{while(ob_get_level()>0){ob_end_clean();}http_response_code(503);header('Content-Type: text/plain');echo 'Isolated test request unavailable.';});
set_error_handler(static function(int $severity):bool{if(!(error_reporting()&$severity))return false;throw new RuntimeException('Isolated runtime warning.');});
ob_start();
require __DIR__.'/database.php';
$run=getenv('GIGHUB_HTTP_RUN')?:'';$dir=GigHub\Tests\Http\runtime($run);$state=json_decode(file_get_contents($dir.'/run.json'),true,512,JSON_THROW_ON_ERROR);
if(PHP_SAPI!=='cgi-fcgi'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'||($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:8765'
    ||($state['run']??null)!==$run||($state['port']??null)!==8765){throw new RuntimeException();}
if(array_key_exists('job_browsing_enabled',$state)&&!is_bool($state['job_browsing_enabled']))throw new RuntimeException();
$browseMode=($state['job_browsing_enabled']??false)===true;
if($browseMode) {
    require __DIR__.'/browse-policy.php';
    GigHub\Tests\Http\BrowsePolicy::validateState($state,$run);
    if(!GigHub\Tests\Http\BrowsePolicy::allows($_SERVER['REQUEST_METHOD']??'GET',$_GET['route']??'/login',$state)) {
        http_response_code(403);echo 'Only isolated browsing and approved authentication are enabled.';exit;
    }
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_GET['route']??'')==='/login'
        &&!GigHub\Tests\Http\BrowsePolicy::allowsLogin($_POST['email']??null,$run)) {
        http_response_code(403);echo 'Only approved browsing fixtures may sign in.';exit;
    }
}
if(!$browseMode&&($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&($state['mutations_enabled']??null)!==true){http_response_code(403);echo 'Test mutations are not enabled.';exit;}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_GET['route']??'')==='/register'){
    $email=is_string($_POST['email']??null)?strtolower(trim($_POST['email'])):'';
    if(filter_var($email,FILTER_VALIDATE_EMAIL)&&(!str_starts_with($email,'http'.$run.'-')||!str_ends_with($email,'@example.invalid'))){http_response_code(403);echo 'Only run-marked synthetic registration is allowed.';exit;}
}
$jobMode=($state['job_posting_enabled']??false)===true;
if($jobMode&&($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&!in_array($_GET['route']??'',['/login','/logout','/client/jobs/create','/client/jobs/update','/client/jobs/publish'],true)) {
    http_response_code(403);echo 'Only the isolated Job Posting workflow is enabled.';exit;
}
$root=dirname(__DIR__,2);
foreach(['Core/Router','Core/SecureSession','Core/Csrf','Core/RegistrationThrottle','Core/VerificationThrottle','Core/LoginThrottle','Core/AuthSession','Core/AuthGuard',
    'Repositories/UserRepository','Repositories/VerificationTokenRepository','Services/RegistrationValidator','Services/RegistrationValidationException','Services/RegistrationService',
    'Services/LoginValidator','Services/AuthenticationService','Mail/MailTransport','Mail/VerificationMail','Mail/DisabledMailTransport','Services/EmailVerificationService',
    'Controllers/RegistrationController','Controllers/EmailVerificationController','Controllers/AuthenticationController','Controllers/AccountHomeController'] as $class){require_once $root.'/app/'.$class.'.php';}
date_default_timezone_set('UTC');session_save_path($dir.'/sessions');ini_set('session.gc_maxlifetime','28800');
GigHub\Core\SecureSession::start(['name'=>$state['cookie'],'path'=>'/']);
// Every request verifies the isolated identity, even if a controller would otherwise be lazy.
$pdo=GigHub\Tests\Http\TestDatabase::connection();
$clock=static function()use($dir):int{$s=json_decode(file_get_contents($dir.'/run.json'),true,512,JSON_THROW_ON_ERROR);if(!is_int($s['clock']??null))throw new RuntimeException();return $s['clock'];};
$dateClock=static fn()=>new DateTimeImmutable('@'.$clock());
$verificationFactory=static fn()=>new GigHub\Services\EmailVerificationService($pdo,new GigHub\Mail\DisabledMailTransport(),'http://127.0.0.1:8765/',$dateClock);
$authFactory=static fn()=>new GigHub\Services\AuthenticationService(new GigHub\Repositories\UserRepository($pdo));
$session=new GigHub\Core\AuthSession($clock);
$auth=new GigHub\Controllers\AuthenticationController($authFactory,$session);
$home=new GigHub\Controllers\AccountHomeController(new GigHub\Core\AuthGuard($authFactory,$session));
$registration=new GigHub\Controllers\RegistrationController(static fn()=>new GigHub\Services\RegistrationService($pdo,new GigHub\Repositories\UserRepository($pdo)),$verificationFactory);
$verification=new GigHub\Controllers\EmailVerificationController($verificationFactory);$router=new GigHub\Core\Router();
if($browseMode) {
    foreach(['Repositories/JobRepository','Repositories/SkillRepository','Repositories/PublicJobRepository','Services/JobBrowseValidator','Controllers/PublicJobController'] as $class)require_once $root.'/app/'.$class.'.php';
    require_once __DIR__.'/job-fixtures.php';require_once __DIR__.'/browse-fixtures.php';
    GigHub\Tests\Http\BrowseFixtures::assertInventory($pdo,$dir,$run);
    $publicJobs=new GigHub\Controllers\PublicJobController(static fn()=>[new GigHub\Repositories\PublicJobRepository($pdo),new GigHub\Repositories\SkillRepository($pdo)],$dateClock);
    $router->get('/jobs',static fn()=>$publicJobs->index($_GET));
    $router->get('/jobs/show',static fn()=>$publicJobs->show($_GET));
}
$router->get('/login',static fn()=>$auth->show());$router->post('/login',static fn()=>$auth->login($_POST));$router->post('/logout',static fn()=>$auth->logout($_POST));
foreach(['client','freelancer','admin'] as $role){$router->get('/'.$role,static fn()=>$home->show($role));}
$router->get('/register',static fn()=>$registration->show());$router->post('/register',static fn()=>$registration->submit($_POST));$router->get('/register/pending',static fn()=>$registration->pending());
$router->get('/verify-email',static fn()=>$verification->show());$router->post('/verify-email',static fn()=>$verification->confirm($_POST));
$router->get('/verify-email/resend',static fn()=>$verification->resendForm());$router->post('/verify-email/resend',static fn()=>$verification->resend($_POST));$router->get('/verify-email/result',static fn()=>$verification->result());
if($jobMode) {
    foreach(['Repositories/JobRepository','Repositories/SkillRepository','Services/JobPostingException','Services/JobPostingValidator','Services/JobPostingService','Controllers/ClientJobController'] as $class)require_once $root.'/app/'.$class.'.php';
    require_once __DIR__.'/job-fixtures.php';
    GigHub\Tests\Http\JobFixtures::validateState($state,$run);
    GigHub\Tests\Http\JobFixtures::assertInventory($pdo,$dir,$run);
    $jobFactory=static fn()=>new GigHub\Services\JobPostingService($pdo,new GigHub\Tests\Http\TrackedJobRepository($pdo,$dir,$run),new GigHub\Repositories\SkillRepository($pdo),$dateClock);
    $jobs=new GigHub\Controllers\ClientJobController(new GigHub\Core\AuthGuard($authFactory,$session),$jobFactory);
    $router->get('/client/jobs',static fn()=>$jobs->index($_GET));
    $router->get('/client/jobs/create',static fn()=>$jobs->create());
    $router->post('/client/jobs/create',static fn()=>$jobs->store($_POST));
    $router->get('/client/jobs/show',static fn()=>$jobs->show($_GET));
    $router->get('/client/jobs/edit',static fn()=>$jobs->edit($_GET));
    $router->post('/client/jobs/update',static fn()=>$jobs->update($_POST));
    $router->post('/client/jobs/publish',static fn()=>$jobs->publish($_POST));
}
$response=$router->dispatch($_SERVER['REQUEST_METHOD']??'GET',$_GET['route']??'/login');
http_response_code($response['status']);header('Content-Type: '.$response['type']);header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
if(isset($response['location']))header('Location: '.$response['location']);if(isset($response['allow']))header('Allow: '.$response['allow']);if(isset($response['retry_after']))header('Retry-After: '.$response['retry_after']);
echo $response['body'];ob_end_flush();
