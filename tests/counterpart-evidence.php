<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
foreach (['Repositories/DisputeRepository','Repositories/ProgressiveEscrowRepository','Services/PaymentMoney','Services/ProgressiveEscrowService','Services/ChatAttachmentStorage','Services/DisputeException','Services/DisputeService'] as $file) require_once $root.'/app/'.$file.'.php';

final class EvidencePDO extends PDO
{
    public bool $active = false;
    public bool $failCommit = false;
    public array $snapshot = [];
    public EvidenceRepository $repository;
    public function __construct() {}
    public function beginTransaction(): bool { $this->active=true; $this->snapshot=[$this->repository->response,$this->repository->notifications]; return true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool { if($this->failCommit) throw new RuntimeException('Synthetic commit failure'); $this->active=false; return true; }
    public function rollBack(): bool { [$this->repository->response,$this->repository->notifications]=$this->snapshot; $this->active=false; return true; }
}
final class EvidenceRepository extends \GigHub\Repositories\DisputeRepository
{
    public ?string $response = null;
    public int $notifications = 0;
    public int $raiser = 11;
    public string $status = 'open';
    public bool $deployed = true;
    public function supportsCounterpartEvidence(): bool { return $this->deployed; }
    public function getJob(int $jobId, bool $lock=false): ?array { return ['id'=>1,'client_id'=>11,'awarded_bid_id'=>33,'status'=>'disputed','title'=>'Synthetic contract','financial_policy'=>'legacy_full_v1']; }
    public function getBid(int $bidId, bool $lock=false): ?array { return ['id'=>33,'job_id'=>1,'freelancer_id'=>22]; }
    public function getDisputeById(int $id, bool $lock=false): ?array { return ['id'=>44,'job_id'=>1,'raised_by'=>$this->raiser,'status'=>$this->status,'response'=>$this->response]; }
    public function submitDisputeResponse(int $id,string $response): bool { if($this->response!==null)return false; $this->response=$response; return true; }
    public function createNotification(int $recipientId,?int $jobId,string $type,string $message): int { $this->notifications++; return 1; }
}
$count=0;
function checkEvidence(bool $ok,string $name):void { global $count; if(!$ok)throw new RuntimeException('FAIL '.$name);$count++; }
function evidenceFixture():array { $pdo=new EvidencePDO();$repo=new EvidenceRepository($pdo);$pdo->repository=$repo;return [$pdo,$repo,new \GigHub\Services\DisputeService($pdo,$repo)]; }
function rejectsEvidence(callable $call,int $status,string $name):void { try{$call();throw new RuntimeException('FAIL '.$name);}catch(\GigHub\Services\DisputeException $e){checkEvidence($e->status===$status,$name);} }

[$pdo,$repo,$service]=evidenceFixture();
$service->submitDisputeResponse(22,44,'Valid synthetic counterpart response');
checkEvidence($repo->response==='Valid synthetic counterpart response'&&!$pdo->active,'text-only response commits');
checkEvidence($repo->notifications===1,'one notification');
rejectsEvidence(fn()=>$service->submitDisputeResponse(22,44,'Attempt replacement response'),409,'submitted response immutable');
checkEvidence($repo->notifications===1,'repeat does not notify again');
[$pdo,$repo,$service]=evidenceFixture();$repo->raiser=22;
$service->submitDisputeResponse(11,44,'Client counterpart response');
checkEvidence($repo->response==='Client counterpart response','Client counterpart supported');
foreach([11,99] as $id){[$pdo,$repo,$service]=evidenceFixture();rejectsEvidence(fn()=>$service->submitDisputeResponse($id,44,'Invalid participant response'),403,'initiator/unrelated denied');checkEvidence($repo->response===null&&!$pdo->active,'unauthorized response rolled back');}
[$pdo,$repo,$service]=evidenceFixture();$repo->status='resolved';rejectsEvidence(fn()=>$service->submitDisputeResponse(22,44,'Resolved response attempt'),409,'resolved dispute read-only');
[$pdo,$repo,$service]=evidenceFixture();rejectsEvidence(fn()=>$service->submitDisputeResponse(22,44,'short'),422,'response length validated');
rejectsEvidence(fn()=>$service->submitDisputeResponse(22,44,'Valid response',array_fill(0,7,[])),422,'seven images denied');
checkEvidence($repo->response===null&&$repo->notifications===0,'limit rejection has no response effects');
foreach([['name'=>'active.svg'],['name'=>'fake.png','error'=>UPLOAD_ERR_NO_FILE]] as $file){[$pdo,$repo,$service]=evidenceFixture();rejectsEvidence(fn()=>$service->submitDisputeResponse(22,44,'Valid response',[$file]),422,'invalid evidence rolls back response');checkEvidence($repo->response===null&&$repo->notifications===0&&!$pdo->active,'invalid evidence atomicity');}
[$pdo,$repo,$service]=evidenceFixture();$repo->deployed=false;
rejectsEvidence(fn()=>$service->submitDisputeResponse(22,44,'Valid response',[['name'=>'fake.png']]),422,'unapplied schema fails closed for images');
$service->submitDisputeResponse(22,44,'Old-schema text response');checkEvidence($repo->response!==null,'old-schema text-only compatibility');
[$pdo,$repo,$service]=evidenceFixture();$pdo->failCommit=true;
rejectsEvidence(fn()=>$service->submitDisputeResponse(22,44,'Valid response'),500,'commit error sanitized');
checkEvidence($repo->response===null&&$repo->notifications===0&&!$pdo->active,'commit failure rolls back response/notification');

$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$dispute=['id'=>44];
$evidenceImages=[['id'=>1,'evidence_side'=>'initiator','uploader_id'=>11,'uploader_name'=>'<Initiator>','original_name'=>'initiator.png'],['id'=>2,'evidence_side'=>'counterpart','uploader_id'=>22,'uploader_name'=>'<Counterpart>','original_name'=>'<script>alert(1)</script>.png']];
ob_start();require $root.'/app/Views/partials/dispute-evidence-groups.php';$html=ob_get_clean();
checkEvidence(str_contains($html,'Initiator Evidence')&&str_contains($html,'Counterpart Evidence'),'separate galleries');
checkEvidence(str_contains($html,'User #11')&&str_contains($html,'User #22'),'uploader identity shown');
checkEvidence(!str_contains($html,'<script>alert(1)')&&str_contains($html,'&lt;Counterpart&gt;'),'filename/uploader escaped');
checkEvidence(substr_count($html,'data-evidence-gallery')===2&&substr_count($html,'data-evidence-viewer>')===2,'each gallery has modal');
checkEvidence(count($evidenceImages)===2,'shared view restores caller evidence');
$evidenceImages=[];ob_start();require $root.'/app/Views/partials/dispute-evidence-groups.php';$html=ob_get_clean();
checkEvidence(substr_count($html,'No supporting images were uploaded.')===2,'legacy empty-gallery compatibility');
echo 'PASS: '.$count." counterpart evidence offline checks; no database connections or uploads.\n";
