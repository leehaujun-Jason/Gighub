<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
if(count($argv)!==2||$argv[1]!=='--run-isolated'){fwrite(STDERR,"Explicit opt-in required: --run-isolated. No connection attempted.\n");exit(2);}
$p=null;$n=0;$stage='isolation';
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;};
try {
 require __DIR__.'/integration-bootstrap.php';
 foreach(['Repositories/JobRepository','Repositories/SkillRepository','Services/JobPostingException','Services/JobPostingValidator','Services/JobPostingService'] as $f)require dirname(__DIR__).'/app/'.$f.'.php';
 // ONLY connection: all fixture, setup, service, constraints and rollback checks use this PDO.
 $p=GigHub\Tests\IntegrationDatabase::connection($argv);
 $check(!$p->inTransaction(),'no prior transaction');
 $denied=false;try{$p->query('SELECT COUNT(*) FROM gighub_dev.users');}catch(PDOException $e){$denied=($e->errorInfo[1]??0)===1142;}$check($denied,'development access denied');
 $tables=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);$check(count($tables)===20,'twenty tables');
 $snapshot=static function()use($p,$tables):array{$out=[];foreach($tables as $t){if(!preg_match('/\A[a-z_]+\z/D',$t))throw new RuntimeException();$rows=$p->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_NUM);$encoded=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows);sort($encoded,SORT_STRING);$out[$t]=[count($rows),hash('sha256',implode("\n",$encoded))];}return $out;};
 $before=$snapshot();$p->beginTransaction();$stage='fixtures';$marker='dbj'.bin2hex(random_bytes(7)).'-';$ids=['users'=>[],'skills'=>[],'jobs'=>[]];
 $user=$p->prepare("INSERT INTO users (email,password_hash,display_name,role,status,email_verified_at) VALUES (?,?,?,'client','active',UTC_TIMESTAMP())");
 foreach(['owner','other'] as $label){$user->execute([$marker.$label.'@example.invalid',password_hash('Synthetic job fixture passphrase',PASSWORD_ARGON2ID),'Synthetic job fixture']);$id=(int)$p->lastInsertId();$ids['users'][]=$id;$q=$p->prepare('INSERT INTO client_profiles(user_id) VALUES (?)');$q->execute([$id]);}
 foreach(['PHP','CSS'] as $label){$q=$p->prepare('INSERT INTO skills(name) VALUES (?)');$q->execute([$marker.$label]);$ids['skills'][]=(int)$p->lastInsertId();}
 [$owner,$other]=$ids['users'];$jobs=new GigHub\Repositories\JobRepository($p);$skills=new GigHub\Repositories\SkillRepository($p);
 $now=new DateTimeImmutable('2030-01-01',new DateTimeZone('Asia/Kuala_Lumpur'));$service=new GigHub\Services\JobPostingService($p,$jobs,$skills,static fn()=>$now);
 $base=['title'=>$marker.'A useful project','description'=>str_repeat('a',60),'budget'=>'99999999.99','deadline'=>'2030-01-02','skills'=>$ids['skills'],'intent'=>'save_draft','client_id'=>$other,'status'=>'completed'];
 $reject=static function(callable $f,int $status)use($check):void{try{$f();}catch(GigHub\Services\JobPostingException $e){$check($e->status===$status,'expected domain response');return;}$check(false,'domain rejection missing');};
 $stage='service';$draft=$service->save($owner,array_replace($base,['skills'=>[]]));$ids['jobs'][]=$draft;
 $row=$service->owned($draft,$owner);$check($row['status']==='draft'&&(int)$row['client_id']===$owner,'draft owner derives from session identity');$check($row['budget']==='99999999.99'&&$row['skills']===[],'money and zero draft skills');
 $reject(static fn()=>$service->owned($draft,$other),404);$check($service->listing($other,1)===[],'other owner list private');
 $service->save($owner,$base,$draft);$check(count($service->owned($draft,$owner)['skills'])===2,'draft skills edited');
 $service->save($owner,array_replace($base,['skills'=>[$ids['skills'][1]]]),$draft);$check(array_column($service->owned($draft,$owner)['skills'],'id')==[$ids['skills'][1]],'skills replaced');
 $service->publish($owner,$draft);$check($service->owned($draft,$owner)['status']==='open','saved draft published');
 $reject(static fn()=>$service->publish($owner,$draft),409);$reject(static fn()=>$service->save($owner,$base,$draft),409);$reject(static fn()=>$service->save($other,$base,$draft),404);
 $open=$service->save($owner,array_replace($base,['intent'=>'publish']));$ids['jobs'][]=$open;$check($service->owned($open,$owner)['status']==='open','direct publication');
 $old=$service->save($owner,array_replace($base,['deadline'=>'2020-01-01']));$ids['jobs'][]=$old;$reject(static fn()=>$service->publish($owner,$old),422);$check($service->owned($old,$owner)['status']==='draft','expired publication unchanged');
 $stage='rollback';$failureRepo=new class($p) extends GigHub\Repositories\JobRepository {public function replaceSkills(int $id,array $skills):void{parent::replaceSkills($id,$skills);throw new RuntimeException('Synthetic failure after link insert');}};
 $failureService=new GigHub\Services\JobPostingService($p,$failureRepo,$skills,static fn()=>$now);$atomicBefore=$snapshot();
 try{$failureService->save($owner,$base);$check(false,'failure expected');}catch(RuntimeException $e){$check($e->getMessage()==='Job Posting is temporarily unavailable.','sanitized failed creation');}$check($snapshot()===$atomicBefore,'job and links rollback together');
 try{$failureService->save($owner,array_replace($base,['title'=>$marker.'Changed title']),$old);$check(false,'edit failure expected');}catch(RuntimeException){}$check($snapshot()===$atomicBefore,'failed edit preserves details and links');
 $stage='constraints';$constraint=static function(callable $f,int $expected)use($p,$check):void{$p->exec('SAVEPOINT fixture_constraint');try{$f();$check(false,'constraint accepted invalid row');}catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===$expected,'expected database constraint rejection');}finally{$p->exec('ROLLBACK TO SAVEPOINT fixture_constraint');$p->exec('RELEASE SAVEPOINT fixture_constraint');}};
 $q=$p->prepare('INSERT INTO job_skills(job_id,skill_id) VALUES (?,?)');$constraint(static fn()=>$q->execute([$open,$ids['skills'][0]]),1062);
 $constraint(static fn()=>$q->execute([$open,0]),1452);
 $q=$p->prepare('UPDATE jobs SET budget=0 WHERE id=?');$constraint(static fn()=>$q->execute([$open]),4025);
 $q=$p->prepare("UPDATE jobs SET title='' WHERE id=?");$constraint(static fn()=>$q->execute([$open]),4025);
 $q=$p->prepare("UPDATE jobs SET status='awarded' WHERE id=?");$constraint(static fn()=>$q->execute([$open]),4025);
 $check($p->inTransaction(),'service preserves outer fixture transaction');$p->rollBack();$stage='cleanup';$check($snapshot()===$before,'all test rows/digests restored');
 echo "PASS: $n isolated Job Posting database checks; fixtures rolled back.\n";
}catch(Throwable){if($p instanceof PDO&&$p->inTransaction())$p->rollBack();fwrite(STDERR,'FAIL: Job Posting database stage '.$stage.'; raw details withheld.'.PHP_EOL);exit(1);}
finally{if($p instanceof PDO&&$p->inTransaction())$p->rollBack();}
