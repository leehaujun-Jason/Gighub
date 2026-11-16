<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
foreach (['Core/Csrf','Core/AuthSession','Core/AuthGuard','Repositories/UserRepository','Services/LoginValidator','Services/AuthenticationService','Repositories/BidRepository','Services/BidValidator','Services/BidException','Services/BidService','Controllers/BidController'] as $class) require_once $root.'/app/'.$class.'.php';
use GigHub\Services\BidValidator as V;
use GigHub\Services\BidException;
use GigHub\Services\BidService;
use GigHub\Core\Csrf;
$checks=0;
function check(bool $ok,string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$name); ++$checks; }
function rejected(callable $call,int $status,string $name): void { try { $call(); check(false,$name); } catch (BidException $e) { check($e->status===$status,$name); } }
$good=['job_id'=>'10','amount'=>'120.50','delivery_days'=>'7','cover_letter'=>'A thoughtful proposal.'];
check(V::validate($good)['errors']===[],'valid bid');
foreach (['0.01','1','1.2','99999999.99'] as $amount) check(V::validate(array_replace($good,['amount'=>$amount]))['errors']===[],'valid money boundary');
check(V::validate(array_replace($good,['amount'=>'1.2']))['data']['amount']==='1.20','exact decimal normalization');
foreach (['0','0.00','-1','1.001','1e2','100000000','01','1,000',str_repeat('1',40)] as $amount) check(isset(V::validate(array_replace($good,['amount'=>$amount]))['errors']['amount']),'invalid amount');
foreach (['1','65535'] as $days) check(V::validate(array_replace($good,['delivery_days'=>$days]))['errors']===[],'valid duration boundary');
foreach (['0','65536','-1','1.5','01',[],true] as $days) check(isset(V::validate(array_replace($good,['delivery_days'=>$days]))['errors']['delivery_days']),'invalid duration');
check(V::validate(array_replace($good,['cover_letter'=>str_repeat('界',10000)]))['errors']===[],'Unicode proposal');
foreach ([''," \n ",str_repeat('a',10001),"\xff","a\0b",[]] as $proposal) check(isset(V::validate(array_replace($good,['cover_letter'=>$proposal]))['errors']['cover_letter']),'invalid proposal');
foreach ([null,[],true,'0','4294967296','1 OR 1=1'] as $id) check(V::id($id)===null,'invalid identifier');
check(V::id('4294967295')===4294967295,'unsigned identifier boundary');

class BidTestPDO extends PDO {
    public bool $active=false; public array $events=[];
    public function __construct() {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->active=true;$this->events[]='begin';return true; }
    public function commit(): bool { $this->active=false;$this->events[]='commit';return true; }
    public function rollBack(): bool { $this->active=false;$this->events[]='rollback';return true; }
    public function exec(string $statement): int|false { $this->events[]=$statement;return 0; }
}
$pdo=new BidTestPDO();
$repo=new class extends GigHub\Repositories\BidRepository {
    public array|false $jobRow=['id'=>10,'client_id'=>9,'title'=>'<Project>','status'=>'open','awarded_bid_id'=>null,'deadline'=>'2030-01-01'];
    public array|false $bid=false; public bool $client=true; public bool $freelancer=true; public bool $migration=true; public bool $failure=false; public bool $duplicate=false; public array $events=[];
    public function __construct() {}
    public function job(int $id,bool $lock=false): array|false { $this->events[]=$lock?'lock job':'read job';return $id===10?$this->jobRow:false; }
    public function eligible(int $id,string $role,bool $lock=false): bool { $this->events[]=($lock?'lock ':'read ').$role;return $role==='client'?$this->client:$this->freelancer; }
    public function owned(int $id,int $owner,bool $lock=false): array|false { return $this->bid!==false && $id===$this->bid['id'] && $owner===$this->bid['freelancer_id']?$this->bid:false; }
    public function forJob(int $job,int $owner,bool $lock=false): array|false { $this->events[]=$lock?'lock bid':'read bid';return $this->bid!==false && $job===$this->bid['job_id'] && $owner===$this->bid['freelancer_id']?$this->bid:false; }
    public function insert(int $job,int $owner,array $data): int {
        if ($this->duplicate) { $e=new PDOException('SQL detail');$e->errorInfo=['23000',1062];throw $e; }
        if ($this->failure) throw new RuntimeException('Private database failure');
        $this->events[]='insert';$this->bid=['id'=>21,'job_id'=>$job,'freelancer_id'=>$owner,'status'=>'pending']+$data;return 21;
    }
    public function update(int $id,int $owner,array $data,string $from): void { $this->events[]='update';$this->bid=array_replace($this->bid,$data,['status'=>'pending']); }
    public function withdraw(int $id,int $owner): void { $this->events[]='withdraw';$this->bid['status']='withdrawn'; }
    public function supportsWithdrawal(): bool { return $this->migration; }
    public function mine(int $owner,int $page, ?string $status = null): array { return $this->bid===false?[]:[$this->bid+['title'=>'<Project>','job_status'=>'open','awarded_bid_id'=>null,'created_at'=>'2030-01-01']]; }
    public function clientBids(int $job,int $client,int $page): array { return $this->bid===false?[]:[$this->bid+['display_name'=>'<Freelancer>','created_at'=>'2030-01-01']]; }
};
$service=new BidService($pdo,$repo);
check($service->save(7,$good+['freelancer_id'=>999])===21 && $repo->bid['freelancer_id']===7,'create uses authenticated owner');
check($pdo->events===['begin','commit'],'transaction committed');
check(array_slice($repo->events,0,4)===['lock job','lock client','lock freelancer','lock bid'],'parent and identity locks before bid lock');
rejected(fn()=>$service->save(7,$good),409,'duplicate pending bid');
check(end($pdo->events)==='rollback','duplicate rollback');
rejected(fn()=>$service->edit(8,21),404,'wrong freelancer read');
rejected(fn()=>$service->save(8,$good,21),404,'wrong freelancer write');
rejected(fn()=>$service->withdraw(8,21),404,'wrong freelancer withdrawal');
rejected(fn()=>$service->clientListing(8,10,1),404,'wrong Client cannot read bids');
check(count($service->clientListing(9,10,1)['bids'])===1,'owner Client can read bids');
$service->save(7,array_replace($good,['amount'=>'80']),21);
check($repo->bid['amount']==='80.00' && $repo->bid['id']===21,'edit pending bid');
$repo->migration=false; rejected(fn()=>$service->withdraw(7,21),503,'withdrawal fails closed before migration');
check($repo->bid['status']==='pending','no withdrawal write without migration');$repo->migration=true;
$service->withdraw(7,21);check($repo->bid['status']==='withdrawn','withdraw pending bid');
rejected(fn()=>$service->withdraw(7,21),409,'repeat withdrawal rejected');
$service->save(7,$good,21);check($repo->bid['status']==='pending' && $repo->bid['id']===21,'resubmit reuses unique historical row');
foreach (['accepted','rejected'] as $status) {
    $repo->bid['status']=$status;
    rejected(fn()=>$service->save(7,$good,21),409,'locked bid cannot edit');
    rejected(fn()=>$service->withdraw(7,21),409,'locked bid cannot withdraw');
}
$repo->bid['status']='pending';
foreach (['draft','awarded','completed','cancelled'] as $status) {
    $repo->jobRow['status']=$status;
    rejected(fn()=>$service->save(7,$good,21),409,'non-open edit blocked');
    rejected(fn()=>$service->withdraw(7,21),409,'non-open withdrawal blocked');
}
$repo->jobRow['status']='open';$repo->jobRow['awarded_bid_id']=21;
rejected(fn()=>$service->save(7,$good,21),409,'canonical selection locks bid');$repo->jobRow['awarded_bid_id']=null;
$repo->client=false;rejected(fn()=>$service->save(7,$good,21),404,'ineligible Client hidden');$repo->client=true;
$repo->freelancer=false;rejected(fn()=>$service->save(7,$good,21),403,'ineligible Freelancer blocked');$repo->freelancer=true;
$repo->jobRow['client_id']=7;rejected(fn()=>$service->save(7,$good,21),403,'own job denied');$repo->jobRow['client_id']=9;
$repo->bid=false;$repo->duplicate=true;rejected(fn()=>$service->save(7,$good),409,'database unique race sanitized');$repo->duplicate=false;
$repo->failure=true;
try {$service->save(7,$good);check(false,'failure ignored');}catch(RuntimeException $e){check(!str_contains($e->getMessage(),'Private')&&end($pdo->events)==='rollback','unexpected error sanitized with rollback');}$repo->failure=false;
$pdo->active=true;$pdo->events=[];$service->save(7,$good);
check($pdo->active&&$pdo->events===['SAVEPOINT gighub_bid','RELEASE SAVEPOINT gighub_bid'],'savepoint preserves outer transaction');
rejected(fn()=>$service->save(7,$good),409,'nested duplicate');
check($pdo->active&&in_array('ROLLBACK TO SAVEPOINT gighub_bid',$pdo->events,true),'nested failure rolled back to savepoint');$pdo->active=false;

$users=new class extends GigHub\Repositories\UserRepository {
    public array $row=['id'=>7,'display_name'=>'Freelancer','role'=>'freelancer','status'=>'active','email_verified_at'=>'2030-01-01'];
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false { return $this->row; }
};
$_SESSION=[];$session=new GigHub\Core\AuthSession(null,static fn()=>true);$auth=new GigHub\Services\AuthenticationService($users);
$guard=new GigHub\Core\AuthGuard(static fn()=>$auth,$session);$controller=new GigHub\Controllers\BidController($guard,static fn()=>$service);
check($controller->index()['status']===303 && $controller->save([])['status']===303,'anonymous bidding denied');
$session->establish(7);$users->row['role']='admin';
check($controller->index()['status']===403 && $controller->save([])['status']===403,'Admin bidding denied');
$users->row['role']='client';check($controller->index()['status']===403,'Client cannot submit bids');
$users->row['role']='freelancer';check($controller->client(['id'=>'10'])['status']===403,'Freelancer cannot inspect Client bid inbox');
check($controller->save(['csrf_token'=>'bad'])['status']===403,'bid CSRF required');
check(!Csrf::valid(Csrf::token('logout'),'bidding'),'bid purpose separate');
check($controller->index(['page'=>[]])['status']===400,'invalid pagination');
$form=$controller->form(['id'=>'21'],true);
check($form['status']===200 && str_contains($form['body'],'&lt;Project&gt;'),'escaped form job title');
check(str_contains($form['body'],'Withdraw bid') && substr_count($form['body'],'<form ' )===3,'separate editor, withdrawal and logout forms');
check(!str_contains($form['body'],'name="freelancer_id"'),'no submitted owner');
$invalid=$controller->save(['id'=>'21','csrf_token'=>Csrf::token('bidding')]+array_replace($good,['amount'=>'0']),true);
check($invalid['status']===422 && str_contains($invalid['body'],'aria-invalid="true"'),'validation form feedback');
$response=$controller->save(['id'=>'21','csrf_token'=>Csrf::token('bidding')]+$good,true);
check($response['status']===303 && $response['location']==='?route=/freelancer/bids','fixed PRG');
check(str_contains($controller->index()['body'],'Your bid has been saved.'),'success notice');
$users->row['role']='client';$users->row['id']=9;$session->establish(9);
$repo->bid['cover_letter']='<script>private proposal</script>';
$client=$controller->client(['id'=>'10']);
check($client['status']===200 && str_contains($client['body'],'&lt;script&gt;private proposal&lt;/script&gt;'),'Client proposal escaped');
check(str_contains($client['body'],'?route=/freelancers/show&amp;id=7'),'public Freelancer profile link');
$users->row['id']=8;$session->establish(8);check($controller->client(['id'=>'10'])['status']===404,'cross-Client controller privacy');
$sql=file_get_contents($root.'/app/Repositories/BidRepository.php');
check(str_contains($sql,'WHERE j.id=? AND j.client_id=?'),'owner-scoped Client SQL');
check(str_contains($sql,'WHERE id=? AND freelancer_id=? AND status='),'owner-scoped update SQL');
$biddingSource=file_get_contents($root.'/app/Services/BidService.php');
check(!str_contains($sql,'DELETE FROM') && !str_contains($biddingSource,'selectAward(') && !str_contains($biddingSource,'UPDATE jobs') && !str_contains($sql,'wallet'),'bidding path has no deletion or award/payment mutation');
$users->row['role']='freelancer';$users->row['status']='pending_verification';$users->row['email_verified_at']=null;
check($controller->index()['status']===303,'unverified Freelancer cannot access bidding');
$renderPublic=static function(?array $navigationUser)use($root):string {
    $job=['id'=>10,'title'=>'Project','budget'=>'100.00','deadline'=>'2030-01-01','created_at'=>'2030-01-01','description'=>'Description','skills'=>[]];
    $today='2029-01-01';$logoutToken=$navigationUser===null?null:Csrf::token('logout');
    $assetVersion=static fn(string $file):string=>substr(hash_file('sha256',$root.'/public/assets/'.$file),0,12);
    $escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    ob_start();try{require $root.'/app/Views/jobs/show.php';return ob_get_contents();}finally{ob_end_clean();}
};
foreach(['client','admin',null] as $role) {
    $identity=$role===null?null:['id'=>7,'role'=>$role,'display_name'=>'Name'];
    check(!str_contains($renderPublic($identity),'Submit Bid'),'non-Freelancer public action excluded');
}
check(str_contains($renderPublic(['id'=>7,'role'=>'freelancer','display_name'=>'Name']),'?route=/freelancer/bids/create&amp;job_id=10'),'eligible Freelancer Submit Bid route');
echo "PASS: $checks bidding checks (offline; no database connections).\n";
