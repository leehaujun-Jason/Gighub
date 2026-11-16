<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
foreach(['Repositories/JobRepository','Repositories/SkillRepository','Services/JobPostingValidator','Services/JobPostingException','Services/JobPostingService'] as $file)require dirname(__DIR__).'/app/'.$file.'.php';
$count=0;$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);++$count;};
class JobTestPdo extends PDO {
 public bool $active=false;public array $events=[];
 public function __construct(){}
 public function inTransaction():bool{return $this->active;}
 public function beginTransaction():bool{$this->active=true;$this->events[]='begin';return true;}
 public function commit():bool{$this->active=false;$this->events[]='commit';return true;}
 public function rollBack():bool{$this->active=false;$this->events[]='rollback';return true;}
 public function exec(string $s):int|false{$this->events[]=$s;return 0;}
}
class JobTestRepository extends GigHub\Repositories\JobRepository {
 public array|false $row=false;public bool $eligible=true;public bool $fail=false;public array $writes=[];public array $selected=[['id'=>1,'name'=>'PHP']];
 public function __construct(){}
 public function eligibleClient(int $id):bool{return $this->eligible&&$id===7;}
 public function findOwned(int $id,int $owner,bool $lock=false):array|false{return $owner===7&&$id===9?$this->row:false;}
 public function skillsFor(int $id):array{return $this->selected;}
 public function insert(int $owner,array $data,string $status):int{$this->writes[]=['owner'=>$owner,'data'=>$data,'status'=>$status];return 9;}
 public function updateDraft(int $id,int $owner,array $data,string $status):void{$this->writes[]=['owner'=>$owner,'data'=>$data,'status'=>$status];}
 public function replaceSkills(int $id,array $ids):void{if($this->fail)throw new RuntimeException('secret SQL detail');$this->writes[]=['skills'=>$ids];}
 public function listOwned(int $owner,int $page, ?string $status = null):array{return [];}
}
class JobTestSkills extends GigHub\Repositories\SkillRepository {
 public function __construct(){}
 public function existingIds(array $ids):array{return array_values(array_intersect($ids,[1,2]));}
 public function all():array{return [['id'=>1,'name'=>'PHP']];}
}
$p=new JobTestPdo();$r=new JobTestRepository();$skills=new JobTestSkills();$now=new DateTimeImmutable('2026-11-01',new DateTimeZone('Asia/Kuala_Lumpur'));
$s=new GigHub\Services\JobPostingService($p,$r,$skills,static fn()=>$now);
$base=['title'=>'Valid job title','description'=>str_repeat('a',50),'budget'=>'100','deadline'=>'2026-11-02','skills'=>[1],'intent'=>'publish','client_id'=>88,'status'=>'completed'];
$reject=static function(callable $f,int $status)use($check):void{try{$f();}catch(GigHub\Services\JobPostingException $e){$check($e->status===$status,'domain rejection '.$status);return;}$check(false,'expected domain rejection');};
$check($s->save(7,$base)===9,'create');$check($r->writes[0]['owner']===7&&$r->writes[0]['status']==='open','owner/status never submitted');$check($r->writes[0]['data']['budget']==='100.00','decimal retained');$check($p->events===['begin','commit'],'atomic successful create');
$r->writes=[];$s->save(7,array_replace($base,['intent'=>'save_draft','skills'=>[]]));$check($r->writes[0]['status']==='draft','draft created');
$r->row=['id'=>9,'client_id'=>7,'title'=>'Saved draft title','description'=>str_repeat('b',50),'budget'=>'50.00','deadline'=>'2026-11-02','status'=>'draft'];
$check($s->publish(7,9)===9,'saved draft publish');$check($r->writes[count($r->writes)-2]['status']==='open','draft becomes open');
$reject(static fn()=>$s->publish(8,9),404);$reject(static fn()=>$s->save(8,$base,9),404);$reject(static fn()=>$s->owned(9,8),404);
$r->row['status']='open';$reject(static fn()=>$s->publish(7,9),409);$reject(static fn()=>$s->save(7,$base,9),409);
$r->row['status']='draft';$r->row['deadline']='2026-11-01';$reject(static fn()=>$s->publish(7,9),422);
$r->row['deadline']='2026-11-02';$r->selected=[];$reject(static fn()=>$s->publish(7,9),422);$r->selected=[['id'=>1,'name'=>'PHP']];
$r->eligible=false;$reject(static fn()=>$s->save(7,$base),403);$r->eligible=true;
$reject(static fn()=>$s->save(7,array_replace($base,['skills'=>[3]])),422);$reject(static fn()=>$s->save(7,array_replace($base,['intent'=>'cancel'])),422);
$r->fail=true;$p->events=[];try{$s->save(7,$base);$check(false,'expected persistence failure');}catch(RuntimeException $e){$check($e->getMessage()==='Job Posting is temporarily unavailable.'&&$e->getPrevious()===null,'sanitized persistence');}$check($p->events===['begin','rollback'],'rollback when skills fail');
$p->active=true;$p->events=[];try{$s->save(7,$base);}catch(RuntimeException){}$check($p->active&&in_array('ROLLBACK TO SAVEPOINT gighub_job_posting',$p->events,true),'nested rollback preserves outer transaction');
$r->fail=false;$p->events=[];$s->save(7,$base);$check($p->events===['SAVEPOINT gighub_job_posting','RELEASE SAVEPOINT gighub_job_posting'],'nested success preserves outer transaction');
echo "PASS: $count offline Job Posting service checks; no PDO connection.\n";
