<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
$root=dirname(__DIR__);
if(!is_file($root.'/app/Controllers/ClientJobController.php')){fwrite(STDERR,"FAIL: Job Posting controller is not implemented.\n");exit(1);}
foreach(['Core/Csrf','Core/AuthSession','Core/AuthGuard','Repositories/UserRepository','Services/AuthenticationService','Repositories/JobRepository','Repositories/SkillRepository','Services/JobPostingException','Services/JobPostingValidator','Services/JobPostingService','Controllers/ClientJobController'] as $f)require $root.'/app/'.$f.'.php';
$count=0;$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);++$count;};
$repo=new class extends GigHub\Repositories\UserRepository {
 public string $role='client';public string $status='active';public function __construct(){}
 public function findAuthIdentity(int $id):array|false{return ['id'=>$id,'display_name'=>'Test','role'=>$this->role,'status'=>$this->status,'email_verified_at'=>'2026-01-01'];}
};
$auth=new GigHub\Services\AuthenticationService($repo);$session=new GigHub\Core\AuthSession(static fn()=>2000000000,static fn()=>true);
$guard=new GigHub\Core\AuthGuard(static fn()=>$auth,$session);
$service=new class extends GigHub\Services\JobPostingService {
 public int $writes=0;public ?array $failure=null;public bool $unavailable=false;public array $listRows=[];
 public function __construct(){}
 public function catalogue():array{return [['id'=>1,'name'=>'PHP <script>']];}
 public function listing(int $id,int $page, ?string $status = null):array{return $this->listRows;}
 public function owned(int $id,int $owner):array{return ['id'=>$id,'title'=>'<script>alert(1)</script>','description'=>str_repeat('a',50),'budget'=>'100.00','deadline'=>'2026-11-02','status'=>'draft','skills'=>[]];}
 public function save(int $owner,array $input,?int $id=null):int{if($this->unavailable)throw new RuntimeException('secret driver detail');if($this->failure)throw new GigHub\Services\JobPostingException($this->failure);++$this->writes;return 9;}
 public function publish(int $owner,int $id):int{++$this->writes;return $id;}
};
$c=new GigHub\Controllers\ClientJobController($guard,static fn()=>$service);
$_SESSION=[];$check($c->create()['status']===303,'anonymous redirect');
$session->establish(7);$repo->role='freelancer';$check($c->create()['status']===403,'wrong role');$repo->role='admin';$check($c->create()['status']===403,'admin cannot use Client posting');$repo->role='client';
$response=$c->create();$check($response['status']===200,'client form');$html=$response['body'];
$check(!str_contains($html,'PHP <script>')&&str_contains($html,'PHP &lt;script&gt;'),'skill name escaped');
$check(strpos($html,'value="save_draft"')<strpos($html,'value="publish"'),'Enter defaults to draft');
$check(str_contains($html,'type="submit"')&&!str_contains($html,'novalidate'),'native form semantics');
$check($c->store(['csrf_token'=>'invalid'])['status']===403&&$service->writes===0,'CSRF rejection before service write');
$token=GigHub\Core\Csrf::token('job_posting');
$response=$c->store(['csrf_token'=>$token]);$check($response['status']===303&&$response['location']==='?route=/client/jobs/show&id=9','fixed PRG');
$check($c->show(['id'=>'1 OR 1=1'])['status']===400,'invalid ID');
$check($c->edit(['id'=>'9'])['status']===200,'edit draft');
$check(str_contains($c->show(['id'=>'9'])['body'],'&lt;script&gt;'),'job text escaped');
foreach([
 ['store',['intent'=>'save_draft'],'Your job has been saved.'],
 ['update',['id'=>'9','intent'=>'save_draft'],'Your job has been saved.'],
 ['store',['intent'=>'publish'],'Your job has been published.'],
 ['update',['id'=>'9','intent'=>'publish'],'Your job has been published.'],
 ['publish',['id'=>'9'],'Your job has been published.'],
] as [$action,$input,$message]) {
 $input['csrf_token']=GigHub\Core\Csrf::token('job_posting');$response=$c->$action($input);
 $check($response['status']===303&&$response['location']==='?route=/client/jobs/show&id=9','unchanged '.$action.' redirect');
 $check(($_SESSION['job_posting_notice']??null)===$message,'appropriate '.$action.' flash');
 $html=$c->show(['id'=>'9'])['body'];$other=$message==='Your job has been saved.'?'Your job has been published.':'Your job has been saved.';
 $check(str_contains($html,'role="status">'.$message.'</p>')&&!str_contains($html,$other),'single appropriate success message');
 $check(!isset($_SESSION['job_posting_notice']),'flash consumed after rendering');
}
$_SESSION['job_posting_notice']='<script>untrusted flash</script>';
$check(!str_contains($c->show(['id'=>'9'])['body'],'untrusted flash'),'unknown flash values not rendered');
$html=$c->index()['body'];$check(str_contains($html,'No jobs yet')&&!str_contains($html,'aria-label="Job pages"')&&!str_contains($html,'Page 1'),'empty first page hides pagination');
$check(str_contains($c->index(['page'=>'2'])['body'],'Previous'),'empty later page retains recovery navigation');
$service->listRows=[['id'=>9,'title'=>'Synthetic listed job','status'=>'draft','budget'=>'100.00','deadline'=>'2026-11-02']];
$html=$c->index()['body'];$check(str_contains($html,'aria-label="Job pages"')&&str_contains($html,'Page 1'),'nonempty first page retains pagination');
$html=$c->index(['page'=>'2'])['body'];$check(str_contains($html,'Previous')&&str_contains($html,'Page 2'),'nonempty later page retains previous');
$service->listRows=array_fill(0,21,$service->listRows[0]);$check(str_contains($c->index()['body'],'Next'),'next-page presentation retained');$service->listRows=[];
$home=static function(string $role)use($root):string{$user=['role'=>$role,'display_name'=>'Synthetic account'];$token='synthetic';$escape=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');ob_start();require $root.'/app/Views/account-home.php';return ob_get_clean();};
$html=$home('client');$check(str_contains($html,'Manage your job drafts and published projects, or post a new job.')&&!str_contains($html,'workspace will be developed'),'accurate Client home copy');
$check(str_contains($html,'?route=/client/jobs')&&str_contains($html,'action="?route=/logout"'),'Client navigation and logout retained');
$check(str_contains($home('freelancer'),'Build your professional profile and explore available jobs.')&&str_contains($home('freelancer'),'?route=/freelancer/profile')&&str_contains($home('freelancer'),'?route=/freelancer/bids'),'Freelancer profile and bidding navigation');
$check(str_contains($home('admin'),'Access administrative tools and dispute mediation in the Admin Dispute Queue.')&&str_contains($home('admin'),'?route=/admin'),'Admin copy unaffected');
$service->failure=['title'=>'Use a longer title.'];$token=GigHub\Core\Csrf::token('job_posting');
$response=$c->store(['csrf_token'=>$token,'title'=>'<unsafe>']);
$check($response['status']===422&&str_contains($response['body'],'aria-describedby="title-hint title-error"'),'field association');
$check(str_contains($response['body'],'&lt;unsafe&gt;')&&!str_contains($response['body'],'value="<unsafe>"'),'safe retained values');
$service->failure=null;$service->unavailable=true;$response=$c->store(['csrf_token'=>$token]);
$check($response['status']===503&&!str_contains($response['body'],'secret driver'),'sanitized service failure');
$check($c->index()['status']===200,'owner list response');$check($c->index(['page'=>'-1'])['status']===400,'invalid list page');
$repo->status='suspended';$check($c->create()['status']===303,'live suspension');
echo "PASS: $count Job Posting controller/view checks; offline only.\n";
