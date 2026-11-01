<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
$p=null;$stage='arguments';
try{
    require __DIR__.'/database.php';
    $command=$argv[1]??'';$run=$argv[2]??'';$dir=GigHub\Tests\Http\runtime($run);
    $state=json_decode(file_get_contents($dir.'/run.json'),true,512,JSON_THROW_ON_ERROR);
    require dirname(__DIR__).'/integration-bootstrap.php';
    $p=GigHub\Tests\IntegrationDatabase::connection(['http-control','--run-isolated']);
    $prefix='http'.$run.'-';$pattern=$prefix.'%@example.invalid';
    $payload=json_decode(stream_get_contents(STDIN)?:'{}',true,512,JSON_THROW_ON_ERROR);
    $label=$payload['label']??'';if($label!==''&&!preg_match('/\A[a-z0-9-]{1,40}\z/D',$label))throw new RuntimeException();
    $email=$prefix.$label.'@example.invalid';
    $user=static function()use($p,$email):array|false{$q=$p->prepare('SELECT id,email,role,status,email_verified_at FROM users WHERE email=?');$q->execute([$email]);return $q->fetch();};
    $clock=static fn()=>new DateTimeImmutable('@'.$state['clock']);
    $stage=$command;
    if($command==='preflight'){
        $other=GigHub\Tests\Http\TestDatabase::connection();
        $denied=false;try{$other->query('SELECT COUNT(*) FROM gighub_dev.users');}catch(PDOException $e){$denied=($e->errorInfo[1]??0)===1142;}
        if(!$denied||(int)$other->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND ENGINE='InnoDB'")->fetchColumn()!==20)throw new RuntimeException();
        echo 'PASS';exit;
    }
    if($command==='cleanup'){
        $state['mutations_enabled']=false;file_put_contents($dir.'/run.json',json_encode($state));
        $q=$p->prepare('SELECT id FROM users WHERE email LIKE ?');$q->execute([$pattern]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        $tables=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach($tables as $table){if(!in_array($table,['users','client_profiles','freelancer_profiles','email_verification_tokens'],true)&&(int)$p->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()!==0)throw new RuntimeException('Unexpected dependency records');}
        $deleted=[];$p->beginTransaction();
        if($ids!==[]){$marks=implode(',',array_fill(0,count($ids),'?'));foreach(['email_verification_tokens','freelancer_profiles','client_profiles','users'] as $table){$column=$table==='users'?'id':'user_id';$q=$p->prepare('DELETE FROM `'.$table.'` WHERE `'.$column.'` IN ('.$marks.')'.($table==='users'?' AND email LIKE ?':''));$q->execute($table==='users'?[...$ids,$pattern]:$ids);$deleted[$table]=$q->rowCount();}}
        $q=$p->prepare('SELECT COUNT(*) FROM users WHERE email LIKE ?');$q->execute([$pattern]);if((int)$q->fetchColumn()!==0)throw new RuntimeException();
        $p->commit();$counts=[];foreach($tables as $table){$counts[$table]=(int)$p->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();}
        $result=['deleted'=>$deleted,'all_20_tables_empty'=>array_sum($counts)===0,'remaining_run_users'=>0];
        file_put_contents($dir.'/cleanup.json',json_encode($result,JSON_PRETTY_PRINT));foreach(glob($dir.'/key-*.json') as $file){unlink($file);}echo json_encode($result);exit;
    }
    if($command==='inspect'){
        $u=$user();if($u===false)throw new RuntimeException();$q=$p->prepare('SELECT COUNT(*) total,SUM(used_at IS NOT NULL) used,SUM(revoked_at IS NOT NULL) revoked,MIN(TIMESTAMPDIFF(SECOND,created_at,expires_at)) lifetime FROM email_verification_tokens WHERE user_id=?');$q->execute([$u['id']]);
        echo json_encode(['status'=>$u['status'],'role'=>$u['role'],'verified'=>$u['email_verified_at']!==null,'tokens'=>$q->fetch()]);exit;
    }
    if(($state['mutations_enabled']??false)!==true)throw new RuntimeException();
    if($command==='seed'){
        $password='Synthetic acceptance passphrase 2026!';$hash=password_hash($password,PASSWORD_ARGON2ID);$p->beginTransaction();
        $q=$p->prepare('INSERT INTO users (email,password_hash,display_name,role,status,email_verified_at) VALUES (?,?,?,?,?,?)');
        foreach(['client'=>['client','active'],'freelancer'=>['freelancer','active'],'admin'=>['admin','active'],'pending'=>['client','pending_verification'],'suspended'=>['client','suspended']] as $name=>[$role,$status]){
            $q->execute([$prefix.$name.'@example.invalid',$hash,'Synthetic Acceptance '.$name,$role,$status,$status==='pending_verification'?null:$clock()->format('Y-m-d H:i:s')]);$id=(int)$p->lastInsertId();
            if($role==='client'){$s=$p->prepare('INSERT INTO client_profiles (user_id) VALUES (?)');$s->execute([$id]);}elseif($role==='freelancer'){$s=$p->prepare('INSERT INTO freelancer_profiles (user_id) VALUES (?)');$s->execute([$id]);}
        }$p->commit();echo 'PASS';exit;
    }
    if($command==='issue'){
        foreach(['Mail/MailTransport','Mail/VerificationMail','Mail/DisabledMailTransport','Repositories/VerificationTokenRepository','Services/EmailVerificationService'] as $class)require_once dirname(__DIR__,2).'/app/'.$class.'.php';
        $service=new GigHub\Services\EmailVerificationService($p,new GigHub\Mail\DisabledMailTransport(),'http://127.0.0.1:8765/',$clock);
        $mail=$service->issue($email);if($mail===null)throw new RuntimeException();
        file_put_contents($dir.'/key-'.$label.'.json',json_encode(['key'=>$mail->key]));echo 'PASS';exit;
    }
    if($command==='clock'){
        if(!is_int($payload['time']??null)||$payload['time']<1)throw new RuntimeException();
        $state['clock']=$payload['time'];file_put_contents($dir.'/run.json',json_encode($state));echo 'PASS';exit;
    }
    if($command==='session'){
        $id=$payload['id']??'';if(!is_string($id)||!preg_match('/\A[a-zA-Z0-9,-]{16,128}\z/D',$id)||!is_file($dir.'/sessions/sess_'.$id))throw new RuntimeException();
        session_save_path($dir.'/sessions');session_name($state['cookie']);ini_set('session.use_cookies','0');session_id($id);session_start();
        foreach(['issued_at','last_activity'] as $field){if(isset($payload[$field])){if(!is_int($payload[$field])||!isset($_SESSION['auth']))throw new RuntimeException();$_SESSION['auth'][$field]=$payload[$field];}}
        if(isset($payload['login_failures'])){if(!is_array($payload['login_failures'])||count($payload['login_failures'])>5)throw new RuntimeException();foreach($payload['login_failures'] as $v)if(!is_int($v))throw new RuntimeException();$_SESSION['login_failures']=$payload['login_failures'];}
        session_write_close();echo 'PASS';exit;
    }
    throw new RuntimeException();
}catch(Throwable){if($p instanceof PDO&&$p->inTransaction())$p->rollBack();fwrite(STDERR,'FAIL: isolated control stage '.$stage.'; sensitive details withheld.'.PHP_EOL);exit(1);}
