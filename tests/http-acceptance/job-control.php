<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');
$p=null;$stage='arguments';
try {
 $action=$argv[1]??'';$run=$argv[2]??'';
 if(count($argv)!==3||!in_array($action,['preflight','seed','inspect','cleanup'],true))throw new RuntimeException();
 require __DIR__.'/database.php';require dirname(__DIR__,2).'/app/Repositories/JobRepository.php';require __DIR__.'/job-fixtures.php';require dirname(__DIR__).'/integration-bootstrap.php';
 $dir=GigHub\Tests\Http\runtime($run);$state=json_decode(file_get_contents($dir.'/run.json'),true,512,JSON_THROW_ON_ERROR);
 if(array_key_exists('job_browsing_enabled',$state)&&$state['job_browsing_enabled']!==false)throw new RuntimeException('Browsing fixtures require browse-control.');
 GigHub\Tests\Http\JobFixtures::validateState($state,$run);$prefix=GigHub\Tests\Http\JobFixtures::prefix($run);
 $p=GigHub\Tests\IntegrationDatabase::connection(['job-control','--run-isolated']);$stage=$action;
 if($action==='preflight') {
  $denied=false;try{$p->query('SELECT COUNT(*) FROM gighub_dev.users');}catch(PDOException $e){$denied=($e->errorInfo[1]??0)===1142;}if(!$denied)throw new RuntimeException();
  if(!file_exists($dir.'/job-fixtures.json'))GigHub\Tests\Http\JobFixtures::assertEmpty($p);else GigHub\Tests\Http\JobFixtures::assertInventory($p,$dir,$run);
  echo 'PASS';exit;
 }
 if($action==='seed') {
  if(($state['mutations_enabled']??null)!==true||file_exists($dir.'/job-fixtures.json'))throw new RuntimeException();
  GigHub\Tests\Http\JobFixtures::assertEmpty($p);
  $m=['run'=>$run,'users'=>[],'skills'=>[],'jobs'=>[]];
  // Exclusive creation: never overwrite a previous fixture inventory.
  $handle=fopen($dir.'/job-fixtures.json','x');if($handle===false)throw new RuntimeException();fclose($handle);
  GigHub\Tests\Http\JobFixtures::save($dir,$run,$m);$p->beginTransaction();
  $hash=password_hash('Synthetic job HTTP passphrase',PASSWORD_ARGON2ID);
  $q=$p->prepare("INSERT INTO users(email,password_hash,display_name,role,status,email_verified_at) VALUES (?,?,?,?,'active',UTC_TIMESTAMP())");
  foreach(['client'=>'client','other'=>'client','freelancer'=>'freelancer','admin'=>'admin'] as $label=>$role) {
   $q->execute([$prefix.$label.'@example.invalid',$hash,'Synthetic job '.$label,$role]);$id=(int)$p->lastInsertId();$m['users'][]=$id;GigHub\Tests\Http\JobFixtures::save($dir,$run,$m);
   if($role!=='admin'){$t=$role==='client'?'client_profiles':'freelancer_profiles';$s=$p->prepare('INSERT INTO '.$t.'(user_id) VALUES (?)');$s->execute([$id]);}
  }
  foreach(['PHP','HTML','CSS'] as $name){$s=$p->prepare('INSERT INTO skills(name) VALUES (?)');$s->execute([$prefix.$name]);$m['skills'][]=(int)$p->lastInsertId();GigHub\Tests\Http\JobFixtures::save($dir,$run,$m);}
  $p->commit();echo 'PASS';exit;
 }
 if($action==='inspect') {
  $m=GigHub\Tests\Http\JobFixtures::assertInventory($p,$dir,$run);$jobs=[];
  foreach($m['jobs'] as $id){$q=$p->prepare('SELECT id,client_id,status,budget,deadline FROM jobs WHERE id=?');$q->execute([$id]);$row=$q->fetch();if($row!==false)$jobs[]=$row;}
  echo json_encode(['users'=>$m['users'],'skills'=>$m['skills'],'jobs'=>$jobs],JSON_THROW_ON_ERROR);exit;
 }
 if(($state['mutations_enabled']??null)!==false)throw new RuntimeException();
 // Only cleanup after the reviewed host stopped, never while HTTP requests can mutate.
 $stopped=$dir.'/server-stopped.json';$ready=$dir.'/server-ready.json';
 if(!is_file($stopped)||!is_file($ready)||filemtime($stopped)<filemtime($ready))throw new RuntimeException();
 $stop=json_decode(file_get_contents($stopped),true,512,JSON_THROW_ON_ERROR);if(($stop['graceful']??null)!==true||($stop['unfinished_children']??null)!==[])throw new RuntimeException();
 GigHub\Tests\Http\JobFixtures::cleanup($p,$dir,$run);echo 'PASS: exact run fixtures removed; all 20 test tables empty.';
}catch(Throwable){if($p instanceof PDO&&$p->inTransaction())$p->rollBack();fwrite(STDERR,'FAIL: job fixture control '.$stage.'; preserve inventory and revoke approved temporary grants separately.'.PHP_EOL);exit(1);}
