<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
foreach (['Core/Csrf','Core/AuthSession','Core/AuthGuard','Core/PublicIdentity','Repositories/UserRepository','Services/LoginValidator','Services/AuthenticationService','Repositories/SkillRepository','Repositories/FreelancerProfileRepository','Services/FreelancerProfileValidator','Services/FreelancerProfileException','Services/FreelancerProfileService','Controllers/FreelancerProfileController'] as $class) require_once $root.'/app/'.$class.'.php';
use GigHub\Services\FreelancerProfileValidator as Validator;
use GigHub\Services\FreelancerProfileException;
use GigHub\Services\FreelancerProfileService;
use GigHub\Core\Csrf;
$checks=0;
function check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$message); ++$checks; }
check(isset(Validator::validate([])['errors']['skills']),'empty skills rejected');
check(Validator::validate(['headline'=>str_repeat('界',150),'skills'=>['1']])['errors']===[],'150 Unicode characters');
check(isset(Validator::validate(['headline'=>str_repeat('界',151)])['errors']['headline']),'headline limit');
check(Validator::validate(['bio'=>str_repeat('🌍',10000),'skills'=>['1']])['errors']===[],'Unicode bio TEXT capacity');
check(isset(Validator::validate(['bio'=>str_repeat('a',10001)])['errors']['bio']),'bio limit');
foreach ([['headline'=>[]],['bio'=>"\xff"],['bio'=>"a\0b"],['skills'=>'1'],['skills'=>[[]]],['skills'=>['0']],['skills'=>['4294967296']],['skills'=>['1 OR 1=1']],['skills'=>[true]]] as $input) check(Validator::validate($input)['errors']!==[],'reject malformed input');
check(Validator::validate(['headline'=>'  PHP developer  ','skills'=>['2',1]])['data']===['headline'=>'PHP developer','bio'=>'','skills'=>[1,2]],'normalization and skill set');
check(isset(Validator::validate(['skills'=>['1',1]])['errors']['skills']),'duplicate skills rejected');

class ProfileTestPDO extends PDO {
    public bool $active=false; public array $events=[];
    public function __construct() {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->active=true; $this->events[]='begin'; return true; }
    public function commit(): bool { $this->active=false; $this->events[]='commit'; return true; }
    public function rollBack(): bool { $this->active=false; $this->events[]='rollback'; return true; }
    public function exec(string $statement): int|false { $this->events[]=$statement; return 0; }
}
$pdo=new ProfileTestPDO();
$repo=new class extends GigHub\Repositories\FreelancerProfileRepository {
    public bool $eligible=true; public bool $fail=false; public array $writes=[]; public array $locked=[];
    public array|false $row=['id'=>7,'display_name'=>'<Freelancer>','headline'=>'<script>alert(1)</script>','bio'=>"Work & experience\nNew line"];
    public function __construct() {}
    public function find(int $id): array|false { return $id===7?$this->row:false; }
    public function skills(int $id): array { return [['id'=>1,'name'=>'C++ <expert>']]; }
    public function lockEligible(int $id): bool { $this->locked[]=$id; return $this->eligible; }
    public function update(int $id,array $data): void { if ($this->fail) throw new RuntimeException('private SQL detail'); $this->writes[]=[$id,$data]; }
};
$skills=new class extends GigHub\Repositories\SkillRepository {
    public function __construct() {}
    public function all(): array { return [['id'=>1,'name'=>'PHP'],['id'=>2,'name'=>'CSS']]; }
    public function existingIds(array $ids): array { return array_values(array_intersect($ids,[1,2])); }
};
$service=new FreelancerProfileService($pdo,$repo,$skills);
$service->save(7,['headline'=>'Headline','skills'=>['1',2],'user_id'=>999]);
check($repo->writes[0][0]===7 && $repo->locked===[7],'session-derived owner used despite forged ID');
check($repo->writes[0][1]['skills']===[1,2] && $pdo->events===['begin','commit'],'atomic normalized save');
try { $service->save(7,['skills'=>[99]]); check(false,'unknown skill accepted'); } catch (FreelancerProfileException $e) { check(isset($e->errors['skills']) && end($pdo->events)==='rollback','unknown catalogue ID rollback'); }
$repo->eligible=false;
try { $service->save(7,[]); check(false,'ineligible profile saved'); } catch (FreelancerProfileException $e) { check($e->status===403 && end($pdo->events)==='rollback','missing/ineligible profile fails closed'); }
$repo->eligible=true; $repo->fail=true;
try { $service->save(7,[]); check(false,'write failure accepted'); } catch (RuntimeException $e) { check(!str_contains($e->getMessage(),'private') && end($pdo->events)==='rollback','sanitized failure rolls back'); }
$repo->fail=false; $pdo->active=true; $pdo->events=[];
$service->save(7,[]);
check($pdo->active && $pdo->events===['SAVEPOINT gighub_profile','RELEASE SAVEPOINT gighub_profile'],'outer transaction preserved');
$repo->fail=true;
try { $service->save(7,[]); } catch (RuntimeException) {}
check(in_array('ROLLBACK TO SAVEPOINT gighub_profile',$pdo->events,true) && $pdo->active,'savepoint failure rollback');
$pdo->active=false; $repo->fail=false;

$users=new class extends GigHub\Repositories\UserRepository {
    public array|false $row=['id'=>7,'display_name'=>'<Freelancer>','role'=>'freelancer','status'=>'active','email_verified_at'=>'2026-01-01 00:00:00'];
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false { return $this->row; }
};
$_SESSION=[];
$session=new GigHub\Core\AuthSession(null,static fn()=>true);
$auth=new GigHub\Services\AuthenticationService($users);
$guard=new GigHub\Core\AuthGuard(static fn()=>$auth,$session);
$controller=new GigHub\Controllers\FreelancerProfileController($guard,static fn()=>$service);
check($controller->own()['status']===303,'anonymous owner page redirects');
check($controller->update([])['status']===303,'anonymous write denied');
$session->establish(7); $users->row['role']='client';
check($controller->own()['status']===403 && $controller->update([])['status']===403,'non-freelancer denied');
$users->row['role']='freelancer';
check($controller->update(['csrf_token'=>'bad'])['status']===403,'CSRF denied');
check(!Csrf::valid(Csrf::token('logout'),'freelancer_profile'),'purpose-specific CSRF');
$own=$controller->own();
check($own['status']===200 && str_contains($own['body'],'Edit profile'),'own profile');
check(str_contains($own['body'],'href="?route=/freelancer/profile">My Profile</a>'),'Freelancer account menu profile link');
check(str_contains($own['body'],'&lt;script&gt;') && !str_contains($own['body'],'<script>'),'escaped headline');
check(str_contains($own['body'],'C++ &lt;expert&gt;'),'escaped skill');
$edit=$controller->own(true);
check($edit['status']===200 && str_contains($edit['body'],'name="skills[]"') && str_contains($edit['body'],'method="post"'),'editor and skill selection');
check(!str_contains($edit['body'],'name="user_id"'),'no posted owner');
$invalid=$controller->update(['csrf_token'=>Csrf::token('freelancer_profile'),'headline'=>str_repeat('a',151)]);
check($invalid['status']===422 && str_contains($invalid['body'],'aria-invalid="true"'),'field validation presentation');
$saved=$controller->update(['csrf_token'=>Csrf::token('freelancer_profile'),'user_id'=>999,'skills'=>['1']]);
check($saved['status']===303 && $saved['location']==='?route=/freelancer/profile' && end($repo->writes)[0]===7,'PRG owner and fixed redirect');
check(str_contains($controller->own()['body'],'Your profile has been updated.'),'save flash');
check(!str_contains($controller->own()['body'],'Your profile has been updated.'),'flash consumed');
$public=$controller->show(['id'=>'7']);
check($public['status']===200 && !str_contains($public['body'],'Edit profile'),'public read-only');
check(!str_contains($public['body'],'My Profile</a>'),'guest navigation has no private profile link');
$missing=$controller->show(['id'=>'99']);
foreach ([[],['id'=>[]],['id'=>'0'],['id'=>'4294967296']] as $input) check($controller->show($input)===$missing,'uniform malformed/missing 404');
$repo->row=false; check($controller->show(['id'=>'7'])===$missing,'hidden profile same 404');
$repo->row=['id'=>7,'display_name'=>'Name','headline'=>null,'bio'=>null];
check(str_contains($controller->show(['id'=>'7'])['body'],'No professional headline'),'incomplete profile empty states');
$source=file_get_contents($root.'/app/Repositories/FreelancerProfileRepository.php');
check(str_contains($source,"u.role='freelancer' AND u.status='active' AND u.email_verified_at IS NOT NULL"),'public eligibility SQL');
check(!str_contains($source,'SELECT *') && !str_contains($source,'password_hash') && !str_contains($source,'u.email,'),'public field projection');
check(!str_contains($source,'INSERT INTO freelancer_profiles') && str_contains($source,'WHERE user_id=?'),'existing profile only');
check(str_contains($source,'DELETE FROM user_skills WHERE freelancer_id=?'),'scoped skill replacement');
echo "PASS: $checks focused profile checks (offline; no database connections).\n";
