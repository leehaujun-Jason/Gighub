<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
foreach (['Core/Csrf','Core/AuthSession','Core/AuthGuard','Core/Router','Repositories/UserRepository','Services/LoginValidator','Services/AuthenticationService','Repositories/BidRepository','Services/BidValidator','Services/BidException','Services/BidService','Controllers/BidController','Services/AwardService','Controllers/AwardController'] as $class) require_once $root.'/app/'.$class.'.php';
use GigHub\Services\BidException;
use GigHub\Services\AwardService;
use GigHub\Core\Csrf;
$count=0;
function check(bool $ok,string $label): void { global $count; if (!$ok) throw new RuntimeException('FAIL: '.$label); ++$count; }
function denied(callable $call,int $status,string $label): void { try { $call();check(false,$label); } catch (BidException $e) { check($e->status===$status,$label); } }
class AwardTestPDO extends PDO {
    public bool $active=false;public bool $failCommit=false;public array $events=[];
    public function __construct() {}
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->events[]='begin';$this->active=true;return true; }
    public function commit(): bool { if($this->failCommit)throw new RuntimeException('private commit detail');$this->events[]='commit';$this->active=false;return true; }
    public function rollBack(): bool { $this->events[]='rollback';$this->active=false;return true; }
    public function exec(string $statement): int|false { $this->events[]=$statement;return 0; }
}
$pdo=new AwardTestPDO();
$repo=new class extends GigHub\Repositories\BidRepository {
    public array|false $jobRow=['id'=>2,'client_id'=>6,'title'=>'<Job>','status'=>'open','awarded_bid_id'=>null,'awarded_at'=>null];
    public array|false $bid=['id'=>1,'job_id'=>2,'freelancer_id'=>116,'status'=>'pending'];
    public bool $client=true;public bool $freelancer=true;public bool $failUpdate=false;public bool $conflict=false;public bool $changedBid=false;public ?string $lockedStatus=null;
    public int $updates=0;public array $events=[];
    public function __construct() {}
    public function job(int $id,bool $lock=false): array|false { $this->events[]='job:'.($lock?'lock':'read');return $id===2?$this->jobRow:false; }
    public function eligible(int $id,string $role,bool $lock=false): bool { $this->events[]=$role.':'.($lock?'lock':'read');return $role==='client'?$this->client:$this->freelancer; }
    public function bidOnJob(int $job,int $id,bool $lock=false): array|false {
        $this->events[]='bid:'.($lock?'lock':'read');
        if ($this->bid===false||$job!==$this->bid['job_id']||$id!==$this->bid['id']) return false;
        $row=$this->bid;if($lock&&$this->lockedStatus!==null)$row['status']=$this->lockedStatus;
        if($lock&&$this->changedBid)$row['freelancer_id']=999;return $row;
    }
    public function selectAward(int $job,int $client,int $bid): bool {
        if ($this->failUpdate) throw new RuntimeException('private SQL detail');
        if ($this->conflict) return false;
        $this->events[]='select';++$this->updates;$this->jobRow['status']='awarded';$this->jobRow['awarded_bid_id']=$bid;$this->jobRow['awarded_at']='2030-01-01 00:00:00';return true;
    }
};
$initialJob=$repo->jobRow;$initialBid=$repo->bid;$service=new AwardService($pdo,$repo);
$service->award(6,2,1);
check($repo->jobRow['status']==='awarded'&&$repo->jobRow['awarded_bid_id']===1&&$repo->jobRow['awarded_at']!==null,'job status pointer and time set together');
check($repo->bid===$initialBid,'selection preserves pending bid mirror until funding');
check($pdo->events===['begin','commit'],'transaction committed');
check($repo->events===['job:lock','client:lock','bid:read','freelancer:lock','bid:lock','select'],'consistent lock order and locked bid reread');
denied(fn()=>$service->award(6,2,1),409,'same award cannot repeat');
denied(fn()=>$service->award(6,2,99),409,'second selection blocked after first award');
check($repo->updates===1,'exactly one award update');
$repo->jobRow=$initialJob;
denied(fn()=>$service->award(8,2,1),404,'other Client cannot award');
denied(fn()=>$service->award(6,99,1),404,'missing job');
denied(fn()=>$service->award(6,2,99),404,'wrong job bid or nonexistent bid');
foreach (['withdrawn','accepted','rejected'] as $state) {$repo->bid['status']=$state;denied(fn()=>$service->award(6,2,1),409,'non-pending bid denied');}
$repo->bid=$initialBid;
foreach (['draft','awarded','in_progress','completed','cancelled'] as $state) {$repo->jobRow['status']=$state;denied(fn()=>$service->award(6,2,1),409,'non-open job denied');}
$repo->jobRow=$initialJob;$repo->jobRow['awarded_bid_id']=1;denied(fn()=>$service->award(6,2,1),409,'existing pointer fails closed');
$repo->jobRow=$initialJob;$repo->jobRow['awarded_at']='2030-01-01';denied(fn()=>$service->award(6,2,1),409,'unexpected award timestamp fails closed');
$repo->jobRow=$initialJob;$repo->client=false;denied(fn()=>$service->award(6,2,1),403,'ineligible Client denied');$repo->client=true;
$repo->freelancer=false;denied(fn()=>$service->award(6,2,1),409,'ineligible Freelancer denied');$repo->freelancer=true;
$repo->bid['freelancer_id']=6;denied(fn()=>$service->award(6,2,1),409,'own bid denied');$repo->bid=$initialBid;
$repo->lockedStatus='withdrawn';denied(fn()=>$service->award(6,2,1),409,'withdrawal race uses locked current status');$repo->lockedStatus=null;
$repo->changedBid=true;denied(fn()=>$service->award(6,2,1),404,'changed bidder fails closed');$repo->changedBid=false;
$repo->conflict=true;denied(fn()=>$service->award(6,2,1),409,'conditional update conflict');$repo->conflict=false;
check(end($pdo->events)==='rollback','failed requests roll back');
$repo->failUpdate=true;
try{$service->award(6,2,1);check(false,'SQL error ignored');}catch(RuntimeException $e){check(!str_contains($e->getMessage(),'private')&&end($pdo->events)==='rollback','sanitized database failure rolls back');}$repo->failUpdate=false;
$pdo->failCommit=true;
try{$service->award(6,2,1);check(false,'commit failure ignored');}catch(RuntimeException){check(end($pdo->events)==='rollback','commit failure requests rollback');}$pdo->failCommit=false;$repo->jobRow=$initialJob;
$pdo->active=true;$pdo->events=[];$service->award(6,2,1);
check($pdo->active&&$pdo->events===['SAVEPOINT gighub_award','RELEASE SAVEPOINT gighub_award'],'outer transaction preserved');
denied(fn()=>$service->award(6,2,1),409,'nested repeat rejected');
check(in_array('ROLLBACK TO SAVEPOINT gighub_award',$pdo->events,true),'nested failure isolated by savepoint');$pdo->active=false;$repo->jobRow=$initialJob;

$users=new class extends GigHub\Repositories\UserRepository {
    public array $row=['id'=>6,'role'=>'client','display_name'=>'Client','status'=>'active','email_verified_at'=>'2030-01-01'];
    public function __construct() {}
    public function findAuthIdentity(int $id): array|false { return $this->row; }
};
$_SESSION=[];$session=new GigHub\Core\AuthSession(null,static fn()=>true);$auth=new GigHub\Services\AuthenticationService($users);$guard=new GigHub\Core\AuthGuard(static fn()=>$auth,$session);
$controller=new GigHub\Controllers\AwardController($guard,static fn()=>$service);
check($controller->award([])['status']===303,'anonymous award redirects');
$session->establish(6);
foreach(['freelancer','admin'] as $role){$users->row['role']=$role;check($controller->award([])['status']===403,'non-Client award denied');}$users->row['role']='client';
check($controller->award(['csrf_token'=>Csrf::token('bidding')])['status']===403,'separate award CSRF purpose');
foreach ([['job_id'=>[],'bid_id'=>'1'],['job_id'=>'2','bid_id'=>'0'],['job_id'=>'1 OR 1=1','bid_id'=>'1']] as $bad) check($controller->award($bad+['csrf_token'=>Csrf::token('bid_award')])['status']===400,'invalid request identifiers');
$repo->jobRow=$initialJob;$token=Csrf::token('bid_award');
$response=$controller->award(['job_id'=>'2','bid_id'=>'1','client_id'=>'999','csrf_token'=>$token,'next'=>'https://example.invalid']);
check($response['status']===303&&$response['location']==='?route=/client/jobs/bids&id=2','fixed PRG and session-derived Client');
check(!Csrf::valid($token,'bid_award')&&($_SESSION['award_notice']??false)===true,'CSRF rotation and success notice');
$users->row['status']='suspended';check($controller->award([])['status']===303,'suspended session denied');$users->row['status']='active';$session->establish(6);
$router=new GigHub\Core\Router();$router->post('/client/jobs/award',static fn()=>$controller->award([]));
check($router->dispatch('GET','/client/jobs/award')['status']===405,'GET cannot award');

$render=static function(string $view,array $job,array $rows)use($root):string {
    $navigationUser=['id'=>6,'role'=>'client','display_name'=>'Client'];$bids=$rows;$page=1;$hasNext=false;$awardNotice=false;$notice=null;
    $escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    ob_start();try{require $root.'/app/Views/bids/'.$view.'.php';return ob_get_contents();}finally{ob_end_clean();}
};
$row=['id'=>1,'freelancer_id'=>116,'display_name'=>'<Freelancer>','amount'=>'100.00','delivery_days'=>7,'cover_letter'=>'<Proposal>','status'=>'pending','created_at'=>'2030-01-01','bidder_eligible'=>1];
$html=$render('client',$initialJob,[$row]);
check(str_contains($html,'action="?route=/client/jobs/award"')&&str_contains($html,'method="post"'),'eligible award POST form');
check(str_contains($html,'&lt;Freelancer&gt;')&&str_contains($html,'&lt;Proposal&gt;'),'escaped award view');
foreach(['withdrawn','accepted','rejected'] as $state)check(!str_contains($render('client',$initialJob,[array_replace($row,['status'=>$state])]),'action="?route=/client/jobs/award"'),'locked bid has no award form');
check(!str_contains($render('client',$initialJob,[array_replace($row,['bidder_eligible'=>0])]),'action="?route=/client/jobs/award"'),'ineligible bidder has no award form');
$awarded=array_replace($initialJob,['status'=>'awarded','awarded_bid_id'=>1,'awarded_at'=>'2030-01-01 00:00:00']);
$html=$render('client',$awarded,[$row]);
check(str_contains($html,'Selected — awaiting funding')&&!str_contains($html,'action="?route=/client/jobs/award"'),'Client sees selection without repeat action');
$freelancerRow=$row+['job_id'=>2,'title'=>'Job','job_status'=>'awarded','awarded_bid_id'=>1];
$html=$render('index',$awarded,[$freelancerRow]);
check(str_contains($html,'Your bid was selected.')&&!str_contains($html,'Manage bid'),'Freelancer sees award and locked bid');
$freelancerRow['awarded_bid_id']=2;$html=$render('index',$awarded,[$freelancerRow]);
check(str_contains($html,'Another proposal has been selected.')&&!str_contains($html,'Your bid was selected.'),'non-selected bidder sees accurate state');
$source=file_get_contents($root.'/app/Repositories/BidRepository.php');
check(str_contains($source,"WHERE id=? AND client_id=? AND status='open' AND awarded_bid_id IS NULL AND awarded_at IS NULL"),'conditional owner-scoped award SQL');
check(str_contains($source,'awarded_at=UTC_TIMESTAMP()'),'UTC award timestamp');
$awardSource=file_get_contents($root.'/app/Services/AwardService.php');
check(!str_contains($awardSource,'wallet')&&!str_contains($awardSource,'escrow')&&!str_contains($awardSource,'INSERT INTO'),'no funding or notification writes');
echo "PASS: $count awarding checks (offline; no database connections).\n";
