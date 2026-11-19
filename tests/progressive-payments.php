<?php
declare(strict_types=1);
// Explicitly authorized disposable rehearsal only. Never load application local.php.
if(PHP_SAPI!=='cli'||!in_array('--run-isolated',$argv,true)) { fwrite(STDERR,"Use --run-isolated with GIGHUB_PROGRESSIVE_TEST_CONFIG.\n");exit(2); }
foreach(['Repositories/EscrowRepository','Repositories/SubmissionRepository','Repositories/DisputeRepository','Repositories/NotificationRepository','Repositories/ProgressiveEscrowRepository','Repositories/MilestoneRepository','Services/MilestoneValidator','Services/MilestoneException','Services/MilestoneService','Services/ChatAttachmentStorage','Services/PaymentMoney','Services/ProgressiveEscrowService','Services/EscrowException','Services/EscrowService','Services/SubmissionException','Services/ReviewValidator','Services/SubmissionService','Services/DisputeException','Services/DisputeService'] as $f)require_once dirname(__DIR__).'/app/'.$f.'.php';
use GigHub\Services\PaymentMoney;
use GigHub\Services\SubmissionService;
use GigHub\Repositories\SubmissionRepository;
use GigHub\Services\EscrowService;
use GigHub\Repositories\EscrowRepository;
use GigHub\Services\DisputeService;
use GigHub\Repositories\DisputeRepository;
$config=getenv('GIGHUB_PROGRESSIVE_TEST_CONFIG');
if(!$config||!is_file($config))throw new RuntimeException('Private isolated test config required.');
$s=json_decode(file_get_contents($config),true,512,JSON_THROW_ON_ERROR);
if($s['host']!=='127.0.0.1'||$s['port']!==3309||!preg_match('/\Agighub_restore_[0-9a-f]+\z/D',$s['name']))throw new RuntimeException('Unsafe test target.');
$p=new PDO('mysql:host=127.0.0.1;port=3309;dbname='.$s['name'].';charset=utf8mb4',$s['username'],$s['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($p->query('SELECT DATABASE()')->fetchColumn()!==$s['name']||(int)$p->query('SELECT @@port')->fetchColumn()!==3309)throw new RuntimeException('Target mismatch.');
if($p->query('SELECT CURRENT_USER()')->fetchColumn()!==$s['username'].'@127.0.0.1'||!str_starts_with($s['username'],'gh_restore_'))throw new RuntimeException('Restricted disposable runtime account required.');
$p->exec("SET SESSION time_zone='+00:00'");
$subs=new SubmissionService($p,new SubmissionRepository($p));
$esc=new EscrowService($p,new EscrowRepository($p));
$dis=new DisputeService($p,new DisputeRepository($p));
function sql(string $q,array $a=[]):PDOStatement{global $p;$s=$p->prepare($q);$s->execute($a);return $s;}
function ok(bool $v,string $name):void{global $passed;if(!$v)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name."\n";}
function user(string $role):int{global $p;sql("INSERT INTO users (email,password_hash,display_name,role,status,email_verified_at) VALUES (?,?,?,?,'active',UTC_TIMESTAMP())",['progressive-'.bin2hex(random_bytes(10)).'@example.invalid',password_hash(bin2hex(random_bytes(16)),PASSWORD_ARGON2ID),'Synthetic progressive test',$role]);$id=(int)$p->lastInsertId();if($role!=='admin')sql('INSERT INTO '.$role.'_profiles (user_id) VALUES (?)',[$id]);return $id;}
function fixture(int $count,string $amount='10.01',string $policy='progressive_v1',bool $fund=true):array{
global $p,$esc;
$c=user('client');$f=user('freelancer');
sql('INSERT INTO wallets(user_id,balance) VALUES (?,10000.00)',[$c]);
sql("INSERT INTO wallet_entries(wallet_user_id,entry_type,amount_delta,source,actor_id,operation_key) VALUES (?,'demo_credit',10000.00,'demo_seed',?,?)",[$c,$c,'fixture:credit:'.$c]);
sql("INSERT INTO jobs(client_id,title,description,budget,deadline,status,financial_policy) VALUES (?,'Synthetic progressive fixture','Disposable isolated financial acceptance fixture',?,'2030-12-31','draft',?)",[$c,$amount,$policy]);$j=(int)$p->lastInsertId();
sql("INSERT INTO bids(job_id,freelancer_id,amount,delivery_days,cover_letter,status) VALUES (?,?,?,10,'Synthetic proposal','accepted')",[$j,$f,$amount]);$b=(int)$p->lastInsertId();
sql("UPDATE jobs SET status='awarded',awarded_bid_id=?,awarded_at=UTC_TIMESTAMP() WHERE id=?",[$b,$j]);
$m=[];for($i=1;$i<=$count;$i++){sql("INSERT INTO milestones(job_id,title,description,due_date,position,status) VALUES (?,?,?,'2030-12-01',?,'pending')",[$j,'Checkpoint '.$i,'Synthetic checkpoint',$i]);$m[]=(int)$p->lastInsertId();}
if($fund)$esc->fundJob($c,$j);
return compact('c','f','j','b','m','amount','policy');
}
function attempt(array $x,int $i):int{global $p;$m=$x['m'][$i];sql("UPDATE milestones SET status='submitted' WHERE id=?",[$m]);sql("INSERT INTO work_submissions(job_id,milestone_id,submitted_by,submission_type,notes,status,submitted_at) VALUES (?,?,?,'milestone','Synthetic work','submitted',UTC_TIMESTAMP())",[$x['j'],$m,$x['f']]);return (int)$p->lastInsertId();}
function approve(array $x,int $i):int{global $subs;$sid=attempt($x,$i);$subs->approveMilestone($x['c'],$x['j'],$x['m'][$i],$sid,[]);return $sid;}
function finalApproval(array $x):int{global $p,$subs;sql("UPDATE jobs SET status='under_review' WHERE id=?",[$x['j']]);sql("INSERT INTO work_submissions(job_id,submitted_by,submission_type,notes,status,submitted_at) VALUES (?,?,'final','Synthetic final work','submitted',UTC_TIMESTAMP())",[$x['j'],$x['f']]);$id=(int)$p->lastInsertId();$subs->approveFinalWork($x['c'],$x['j'],$id,[]);return $id;}
function balance(array $x):string{return sql('SELECT balance FROM wallets WHERE user_id=?',[$x['f']])->fetchColumn()?:'0.00';}
function conserve(array $x):bool{$e=sql('SELECT * FROM escrows WHERE job_id=?',[$x['j']])->fetch();return PaymentMoney::sen($e['amount'])===PaymentMoney::sen($e['released_amount'])+PaymentMoney::sen($e['refunded_amount'])+PaymentMoney::sen($e['remaining_amount']);}
if(in_array('--cancel-worker',$argv,true)||in_array('--dispute-worker',$argv,true)){
    $cancel=in_array('--cancel-worker',$argv,true);$i=array_search($cancel?'--cancel-worker':'--dispute-worker',$argv,true);
    try{if($cancel)$dis->respondCancellation((int)$argv[$i+1],(int)$argv[$i+2],'accept');
        else $dis->raiseDispute((int)$argv[$i+1],(int)$argv[$i+2],'Synthetic concurrent dispute request');
        echo 'SUCCEEDED';exit(0);}catch(Throwable){echo 'REJECTED';exit(0);}
}
if(in_array('--worker',$argv,true)){
$i=array_search('--worker',$argv,true);
try{$subs->approveMilestone((int)$argv[$i+1],(int)$argv[$i+2],(int)$argv[$i+3],(int)$argv[$i+4],[]);echo 'APPROVED';exit(0);}catch(Throwable){echo 'REJECTED';exit(0);}
}
if(in_array('--http-fixture',$argv,true)){
    $main=fixture(2);$main['submission']=attempt($main,0);
    $order=fixture(2);$order['submission']=attempt($order,1);
    $final=fixture(1);sql("UPDATE jobs SET status='under_review' WHERE id=?",[$final['j']]);
    sql("INSERT INTO work_submissions(job_id,submitted_by,submission_type,notes,status,submitted_at) VALUES (?,?,'final','Synthetic premature final work','submitted',UTC_TIMESTAMP())",[$final['j'],$final['f']]);$final['submission']=(int)$p->lastInsertId();
    $legacy=fixture(1,'10.00','legacy_full_v1');$legacy['submission']=attempt($legacy,0);
    $dispute=fixture(2,'1000.00');approve($dispute,0);
    $roles=['client'=>$main['c'],'freelancer'=>$main['f'],'unrelated'=>user('client'),'admin'=>user('admin'),'order_client'=>$order['c'],'final_client'=>$final['c'],'legacy_client'=>$legacy['c'],'dispute_client'=>$dispute['c']];
    $accounts=[];foreach($roles as $label=>$id){$password=bin2hex(random_bytes(16));sql('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_ARGON2ID),$id]);$accounts[$label]=['email'=>sql('SELECT email FROM users WHERE id=?',[$id])->fetchColumn(),'password'=>$password];}
    $path=getenv('GIGHUB_PROGRESSIVE_HTTP_FIXTURE');if(!$path||file_exists($path))throw new RuntimeException('Fresh private fixture path required.');
    $h=fopen($path,'x');fwrite($h,json_encode(compact('main','order','final','legacy','dispute','accounts')));fclose($h);
    echo "Disposable HTTP fixtures prepared privately.\n";exit;
}
$passed=0;
try{
ok(PaymentMoney::target('10.01',30)===300&&PaymentMoney::target('10.01',50)===501,'RM10.01 HALF_UP cumulative rounding');
$x=fixture(1);$sid=approve($x,0);ok(balance($x)==='3.00','one milestone 30 percent');
try{$subs->approveMilestone($x['c'],$x['j'],$x['m'][0],$sid,[]);throw new RuntimeException('duplicate accepted');}catch(GigHub\Services\SubmissionException){ok(balance($x)==='3.00','duplicate approval no second credit');}
$final=finalApproval($x);ok(balance($x)==='10.01'&&conserve($x),'one milestone final exact remainder and conservation');
try{$subs->approveFinalWork($x['c'],$x['j'],$final,[]);throw new RuntimeException('duplicate final accepted');}catch(GigHub\Services\SubmissionException){ok(balance($x)==='10.01','duplicate final blocked');}
$x=fixture(2);approve($x,0);approve($x,1);ok(balance($x)==='5.01','second milestone cumulative 50 percent');finalApproval($x);ok(balance($x)==='10.01'&&conserve($x),'two milestone final conservation');
foreach([1,2] as $n){$x=fixture(2,'1000.00');approve($x,0);if($n===2)approve($x,1);$dis->requestCancellation($x['c'],$x['j'],'Synthetic mutual cancellation request');$dis->respondCancellation($x['f'],$x['j'],'accept');$e=sql('SELECT * FROM escrows WHERE job_id=?',[$x['j']])->fetch();ok($e['refunded_amount']===($n===1?'700.00':'500.00')&&conserve($x),'cancellation after '.($n===1?'30':'50').' percent');}
$admin=user('admin');
foreach(['refund_client','pay_freelancer'] as $decision){$x=fixture(2,'1000.00');approve($x,0);$d=$dis->raiseDispute($x['c'],$x['j'],'Synthetic formal dispute for isolated accounting test');$dis->resolveDispute($admin,$d['dispute_id'],$decision,'Synthetic decision for isolated accounting test');$e=sql('SELECT * FROM escrows WHERE job_id=?',[$x['j']])->fetch();ok(conserve($x)&&($decision==='refund_client'?$e['refunded_amount']==='700.00':$e['released_amount']==='1000.00'),'dispute remaining-only '.$decision);}
$x=fixture(3,'20.00','legacy_full_v1');foreach($x['m'] as $i=>$m)approve($x,$i);ok(balance($x)==='0.00','legacy 3 milestones no partial payments');finalApproval($x);ok(balance($x)==='0.00','legacy final retains separate release');sql('INSERT INTO wallets(user_id,balance) VALUES (?,0.00)',[$x['f']]);$esc->releaseEscrowAndCompleteJob($x['c'],$x['j']);ok(balance($x)==='20.00'&&conserve($x),'legacy full settlement compatible');
try{fixture(1,'9.99');throw new RuntimeException('below minimum accepted');}catch(GigHub\Services\EscrowException){ok(true,'progressive RM10 minimum enforced');}
$x=fixture(1);$sid=attempt($x,0);
$cmd=[PHP_BINARY,__FILE__,'--run-isolated','--worker',(string)$x['c'],(string)$x['j'],(string)$x['m'][0],(string)$sid];$workers=[];
for($i=0;$i<2;$i++){$proc=proc_open($cmd,[0=>['file','NUL','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('worker failed');$workers[]=[$proc,$pipes];}
$answers=[];foreach($workers as [$proc,$pipes]){$answers[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0||$err!=='')throw new RuntimeException('worker failed');}
sort($answers);ok($answers===['APPROVED','REJECTED']&&balance($x)==='3.00','concurrent same-milestone approvals release once');
foreach(['wallet_entries','escrow_disbursements'] as $table){try{sql('UPDATE '.$table.' SET id=id WHERE id=(SELECT id FROM (SELECT MIN(id) id FROM '.$table.') a)');throw new RuntimeException('history writable');}catch(PDOException $e){ok(in_array($e->errorInfo[1]??0,[1142,1644],true),'immutable '.$table);}}
$x=fixture(1,'10.00');approve($x,0);finalApproval($x);ok(balance($x)==='10.00'&&conserve($x),'RM10.00 boundary supported');
$management=new GigHub\Services\MilestoneService($p,new GigHub\Repositories\MilestoneRepository($p));
$x=fixture(2,'10.00','progressive_v1',false);
try{$management->createMilestone($x['c'],$x['j'],['title'=>'Third checkpoint','description'=>'Synthetic','due_date'=>'2030-11-30']);throw new RuntimeException('third milestone allowed');}
catch(GigHub\Services\MilestoneException $e){ok($e->status===422,'progressive third milestone rejected before funding');}
$esc->fundJob($x['c'],$x['j']);
try{$management->createMilestone($x['c'],$x['j'],['title'=>'Late checkpoint','due_date'=>'2030-11-30']);throw new RuntimeException('funded structure editable');}
catch(GigHub\Services\MilestoneException $e){ok($e->status===409,'funded milestone structure locked');}
$x=fixture(1,'0.01','legacy_full_v1');approve($x,0);ok(balance($x)==='0.00','legacy sub-RM10 remains compatible');
try{fixture(3);throw new RuntimeException('third milestone accepted');}catch(GigHub\Services\EscrowException){ok(true,'progressive more than two milestones blocked at funding');}
$x=fixture(1);$sid=attempt($x,0);
try{$subs->approveMilestone($x['f'],$x['j'],$x['m'][0],$sid,[]);throw new RuntimeException('unauthorized approval');}catch(GigHub\Services\SubmissionException){ok(balance($x)==='0.00','Freelancer cannot authorize wallet credit');}
sql('INSERT INTO wallets(user_id,balance) VALUES (?,99999999.99)',[$x['f']]);
sql("INSERT INTO wallet_entries(wallet_user_id,entry_type,amount_delta,source,actor_id,operation_key) VALUES (?,'demo_credit',99999999.99,'demo_seed',?,?)",[$x['f'],$x['f'],'fixture:overflow:'.$x['f']]);
try{$subs->approveMilestone($x['c'],$x['j'],$x['m'][0],$sid,[]);throw new RuntimeException('overflow accepted');}
catch(DomainException|GigHub\Services\SubmissionException){ok(sql('SELECT status FROM milestones WHERE id=?',[$x['m'][0]])->fetchColumn()==='submitted'&&sql('SELECT status FROM work_submissions WHERE id=?',[$sid])->fetchColumn()==='submitted','failed payout rolls back milestone and submission approval');}
foreach(['cancel','dispute'] as $race){
    $x=fixture(1,'1000.00');$sid=attempt($x,0);
    if($race==='cancel')$dis->requestCancellation($x['c'],$x['j'],'Synthetic concurrent cancellation request');
    $commands=[
        [PHP_BINARY,__FILE__,'--run-isolated','--worker',(string)$x['c'],(string)$x['j'],(string)$x['m'][0],(string)$sid],
        [PHP_BINARY,__FILE__,'--run-isolated',$race==='cancel'?'--cancel-worker':'--dispute-worker',(string)($race==='cancel'?$x['f']:$x['c']),(string)$x['j']]
    ];
    $workers=[];foreach($commands as $command){$proc=proc_open($command,[0=>['file','NUL','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('race worker failed');$workers[]=[$proc,$pipes];}
    foreach($workers as [$proc,$pipes]){$output=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0||$err!=='')throw new RuntimeException('race worker error');}
    if($race==='dispute'){$d=sql('SELECT id FROM disputes WHERE job_id=?',[$x['j']])->fetchColumn();if(!$d)throw new RuntimeException('dispute race failed');$dis->resolveDispute($admin,(int)$d,'refund_client','Synthetic residual race settlement');}
    $e=sql('SELECT * FROM escrows WHERE job_id=?',[$x['j']])->fetch();
    ok(conserve($x)&&$e['remaining_amount']==='0.00'&&in_array($e['released_amount'],['0.00','300.00'],true),'concurrent approval versus '.$race.' conserves funds');
}
ok((int)sql('SELECT COUNT(*) FROM wallets w LEFT JOIN (SELECT wallet_user_id,SUM(amount_delta) total FROM wallet_entries GROUP BY wallet_user_id) e ON e.wallet_user_id=w.user_id WHERE w.balance<>COALESCE(e.total,0)')->fetchColumn()===0,'global isolated ledger-wallet reconciliation');
echo "RESULT ".$passed." PASS\n";
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();echo 'FAIL '.get_class($e).' '.($e instanceof PDOException?'SQLSTATE '.$e->getCode().' driver '.($e->errorInfo[1]??''):$e->getMessage())."\n";exit(1);}
