<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0');
$root=dirname(__DIR__);
if (!is_file($root.'/app/Services/AuthenticationService.php')) { fwrite(STDERR,"FAIL: Authentication service not implemented.\n"); exit(1); }
foreach (['Repositories/UserRepository','Services/LoginValidator','Services/AuthenticationService','Core/Csrf','Core/LoginThrottle','Core/AuthSession','Core/AuthGuard','Core/Router','Controllers/AuthenticationController','Controllers/AccountHomeController'] as $class) { require_once $root.'/app/'.$class.'.php'; }
use GigHub\Services\AuthenticationService;
use GigHub\Services\LoginValidator;
use GigHub\Core\AuthSession;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\LoginThrottle;
use GigHub\Controllers\AuthenticationController;
$checks=0; $check=static function(bool $ok,string $name) use (&$checks):void { if(!$ok)throw new RuntimeException('FAIL: '.$name); ++$checks; };
$repository=new class extends GigHub\Repositories\UserRepository {
    public array|false $row=false; public bool $unavailable=false; public int $lookups=0;
    public function __construct() {}
    public function findForAuthentication(string $email): array|false { ++$this->lookups; if($this->unavailable)throw new RuntimeException('private driver detail'); return $this->row; }
    public function findAuthIdentity(int $id): array|false { if($this->unavailable)throw new RuntimeException('private driver detail'); return $this->row; }
};
$service=new AuthenticationService($repository);
$password=' Exact Unicode passphrase 🌍 '; $hash=password_hash($password,PASSWORD_ARGON2ID);
$row=['id'=>7,'display_name'=>'Synthetic <User>','role'=>'client','status'=>'active','email_verified_at'=>'2030-01-01 00:00:00','password_hash'=>$hash];
$v=LoginValidator::validate(['email'=>' USER@EXAMPLE.INVALID ','password'=>$password]);
$check($v['email']==='user@example.invalid'&&$v['password']===$password&&$v['errors']===[],'normalization preserves password');
foreach ([[],['email'=>[],'password'=>[]],['email'=>'bad','password'=>''],['email'=>'a@example.invalid','password'=>str_repeat('a',1025)],['email'=>'a@example.invalid','password'=>"a\0b"],['email'=>'a@example.invalid','password'=>"\xff"]] as $input) { $check(LoginValidator::validate($input)['errors']!==[],'malformed input rejected'); }
$check(LoginValidator::validate(['email'=>'a@example.invalid','password'=>'short'])['errors']===[],'login does not reapply registration policy');
$check($service->authenticate('unknown@example.invalid',$password)===null,'unknown user generic rejection');
foreach(['client','freelancer','admin'] as $role){$repository->row=array_replace($row,['role'=>$role]);$identity=$service->authenticate('user@example.invalid',$password);$check($identity!==null&&$identity['role']===$role&&!isset($identity['password_hash']),'eligible role authentication');}
foreach([['status'=>'pending_verification'],['status'=>'suspended'],['email_verified_at'=>null],['role'=>'invalid']] as $change){$repository->row=array_replace($row,$change);$check($service->authenticate('user@example.invalid',$password)===null,'ineligible user rejected');}
$repository->row=$row;
$check($service->authenticate('user@example.invalid',trim($password))===null,'password never trimmed');
$now=2000000000; $regenerations=0; $destroyed=0; $expired=0;
$session=new AuthSession(static function() use (&$now):int{return $now;},static function() use (&$regenerations):bool{++$regenerations;return true;},static function() use (&$destroyed):bool{++$destroyed;return true;},static function() use (&$expired):bool{++$expired;return true;});
$_SESSION=[];$session->establish(7);$check($regenerations===1&&$session->userId()===7,'regeneration before authenticated identity');
$check(array_keys($_SESSION['auth'])===['user_id','issued_at','last_activity'],'minimal identity');
$guard=new AuthGuard(static fn()=>$service,$session);
$check($guard->check('client')['user']['id']===7,'eligible protected request');
$check($guard->check('admin')['status']===403,'wrong role denied');
$repository->row=array_replace($row,['status'=>'suspended']);$check($guard->check('client')['status']===303&&!isset($_SESSION['auth']),'current suspension invalidates identity');
$repository->row=$row;$session->establish(7);$repository->unavailable=true;
$check($guard->check('client')['status']===503,'database outage fails closed');$repository->unavailable=false;
$repository->row=array_replace($row,['role'=>'freelancer']);
$check($guard->check('client')['status']===403&&isset($guard->check('freelancer')['user']),'role is reloaded rather than trusted from session');
$repository->row=false;$check($guard->check('client')['status']===303,'deleted or missing user loses access');$repository->row=$row;
$session->establish(7);$now+=1799;$check(isset($guard->check('client')['user']),'before idle boundary');$now+=1799;$check($session->userId()===7,'authorized activity extends idle timeout');
$session->establish(7);$now+=1800;$check($session->userId()===null,'exact idle boundary expires');
$session->establish(7);$now+=28799;$_SESSION['auth']['last_activity']=$now;$check($session->userId()===7,'before absolute boundary');++$now;$check($session->userId()===null,'exact absolute boundary expires');
$failedSession=new AuthSession(null,static fn():bool=>false);$_SESSION=['auth'=>['user_id'=>99]];
try{$failedSession->establish(7);$check(false,'failed regeneration accepted');}catch(RuntimeException){$check(!isset($_SESSION['auth']),'failed regeneration leaves no authentication');}
$_SESSION=[];for($i=0;$i<5;$i++){$check(LoginThrottle::check($now)['allowed'],'five failures permitted');LoginThrottle::failure($now);}
$check(!LoginThrottle::check($now)['allowed']&&LoginThrottle::check($now)['retry_after']===900,'sixth attempt blocked');
$check(LoginThrottle::check($now+900)['allowed'],'rolling throttle boundary');
$_SESSION=[];$controller=new AuthenticationController(static fn()=>$service,$session);
$response=$controller->show();$check($response['status']===200&&str_contains($response['body'],'autocomplete="current-password"'),'login form autofill');
$check(str_contains($response['body'],'name="email"')&&str_contains($response['body'],'name="password"'),'labelled fields');
$check($controller->login(['csrf_token'=>'bad'])['status']===403,'login csrf');
$check(!Csrf::valid(Csrf::token('verification'),'login'),'csrf purpose separation');
$token=Csrf::token('login');$input=['csrf_token'=>$token,'email'=>'user@example.invalid','password'=>$password];
foreach([false,array_replace($row,['password_hash'=>password_hash('Different synthetic password',PASSWORD_ARGON2ID)]),array_replace($row,['status'=>'suspended']),array_replace($row,['status'=>'pending_verification'])] as $bad){$_SESSION=['login_csrf'=>$token];$repository->row=$bad;$response=$controller->login($input);$check($response['status']===422&&str_contains($response['body'],"We couldn't sign you in")&&str_contains($response['body'],'Please check your email address and password, then try again.')&&!str_contains($response['body'],$password),'uniform failure and no password reflection');}
$repository->row=$row;$_SESSION=['login_csrf'=>$token];$response=$controller->login($input+['next'=>'https://example.invalid']);$check($response['status']===303&&$response['location']==='?route=/client','fixed role redirect');
$check(!Csrf::valid($token,'login')&&strlen(Csrf::token('logout'))===64,'csrf rotated after authentication');
$check($controller->logout(['csrf_token'=>'bad'])['status']===403&&isset($_SESSION['auth']),'invalid logout csrf preserves session');
$response=$controller->logout(['csrf_token'=>Csrf::token('logout')]);$check($response['status']===303&&$response['location']==='?route=/login'&&$_SESSION===[]&&$destroyed===1&&$expired===1,'logout clears session cookie and server session');
$cleanupCalls=0;$cleanupFailure=new AuthSession(null,null,static function() use (&$cleanupCalls):bool{++$cleanupCalls;return true;},static function():bool{throw new Error('private cookie failure');});
$_SESSION=['auth'=>['user_id'=>7]];
try{$cleanupFailure->logout();$check(false,'cookie failure ignored');}catch(RuntimeException){$check($_SESSION===[]&&$cleanupCalls===1,'logout still destroys server session when cookie expiry throws');}
$router=new GigHub\Core\Router();$router->post('/logout',static fn()=>$controller->logout([]));$check($router->dispatch('GET','/logout')['status']===405,'GET logout never invokes logout');
foreach(['freelancer','admin'] as $role){$_SESSION=['login_csrf'=>$token];$repository->row=array_replace($row,['role'=>$role]);$response=$controller->login($input);$check($response['location']==='?route=/'.$role,'role mapping');}
$_SESSION=['login_csrf'=>$token];for($i=0;$i<5;$i++){LoginThrottle::failure();}$lookups=$repository->lookups;$response=$controller->login($input);
$check($response['status']===429&&$response['retry_after']>0&&$repository->lookups===$lookups,'throttle before credential lookup and hashing');
$_SESSION=['login_csrf'=>$token];$repository->unavailable=true;$response=$controller->login($input);$check($response['status']===503&&!str_contains($response['body'],'private driver detail'),'sanitized login outage');$repository->unavailable=false;
$_SESSION=['login_csrf'=>$token];$response=$controller->login(['csrf_token'=>$token,'email'=>'<script>','password'=>'']);$check(!str_contains($response['body'],'value="<script>')&&str_contains($response['body'],'&lt;script&gt;'),'old email escaped');
$repository->row=$row;$session->establish(7);$home=new GigHub\Controllers\AccountHomeController($guard);$response=$home->show('client');$check(str_contains($response['body'],'Synthetic &lt;User&gt;')&&!str_contains($response['body'],'Synthetic <User>'),'landing escapes user name');
require_once $root.'/app/Controllers/EmailVerificationController.php';
$_SESSION['verification_result_notice']='verified';$identityBefore=$_SESSION['auth'];
$verification=new GigHub\Controllers\EmailVerificationController(static function(){throw new RuntimeException('Unexpected verification service call');});
$response=$verification->result();$check($_SESSION['auth']===$identityBefore&&str_contains($response['body'],'Verification has not signed you in.'),'verification result neither authenticates nor clears an existing login');
echo "PASS: $checks authentication offline checks; no database or SMTP connection.\n";
