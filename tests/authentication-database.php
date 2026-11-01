<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
if(count($argv)!==2||($argv[1]??'')!=='--run-isolated'){fwrite(STDERR,"Explicit opt-in required: --run-isolated\n");exit(2);}
$pdo=null;$checks=0;$phase='isolation';
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);++$checks;};
try{
    require_once __DIR__.'/integration-bootstrap.php';
    foreach(['Repositories/UserRepository','Services/AuthenticationService','Core/Csrf','Core/AuthSession','Core/AuthGuard'] as $class){require_once dirname(__DIR__).'/app/'.$class.'.php';}
    // The only connection. Main, fixtures, repositories, guard and cleanup share this PDO.
    $pdo=GigHub\Tests\IntegrationDatabase::connection($argv);
    $check($pdo->query('SELECT DATABASE()')->fetchColumn()==='gighub_schema_test','database guard');
    $check($pdo->query('SELECT CURRENT_USER()')->fetchColumn()==='gighub_test@127.0.0.1','account guard');
    $denied=false;try{$pdo->query('SELECT COUNT(*) FROM gighub_dev.users');}catch(PDOException $e){$denied=($e->errorInfo[1]??null)===1142;}
    $check($denied,'development access must be denied');
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables,SORT_STRING);
    $check(count($tables)===20&&!$pdo->inTransaction(),'test schema and transaction preflight');
    $snapshot=static function()use($pdo,$tables):array{
        $result=[];foreach($tables as $table){if(!preg_match('/\A[a-z_]+\z/',$table))throw new RuntimeException('unsafe table');
            $rows=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM);
            $encoded=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows);sort($encoded,SORT_STRING);
            $result[$table]=['count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$encoded))];}return $result;
    };
    $before=$snapshot();$pdo->beginTransaction();$phase='fixtures';
    $password=' Synthetic integration passphrase 🌏 ';$hash=password_hash($password,PASSWORD_ARGON2ID);
    $prefix='gighub-auth-'.bin2hex(random_bytes(8));
    $users=new GigHub\Repositories\UserRepository($pdo);$service=new GigHub\Services\AuthenticationService($users);
    $now=2000000000;$session=new GigHub\Core\AuthSession(static fn():int=>$now,static fn():bool=>true);
    $guard=new GigHub\Core\AuthGuard(static fn()=>$service,$session);
    $insert=$pdo->prepare('INSERT INTO users (email,password_hash,display_name,role,status,email_verified_at) VALUES (?,?,?,?,?,?)');
    foreach(['client','freelancer','admin'] as $role){foreach(['active','pending_verification','suspended'] as $status){
        $email=$prefix.'-'.$role.'-'.$status.'@example.invalid';
        $insert->execute([$email,$hash,'Synthetic Authentication Fixture',$role,$status,$status==='pending_verification'?null:'2030-01-01 00:00:00']);$id=(int)$pdo->lastInsertId();
        $identity=$service->authenticate($email,$password);
        $check(($identity!==null)===($status==='active'),'eligibility matrix');
        $check($service->authenticate($email,'Wrong synthetic password')===null,'wrong password rejected');
        $check($service->authenticate($email,trim($password))===null,'exact password preserved');
        if($status==='active'){
            $check($identity['id']===$id&&$identity['role']===$role&&!isset($identity['password_hash']),'safe identity');
            $_SESSION=[];$session->establish($id);$check(isset($guard->check($role)['user']),'role access');
            $check($guard->check($role==='admin'?'client':'admin')['status']===403,'cross-role denial');
            $q=$pdo->prepare("UPDATE users SET status='suspended' WHERE id=?");$q->execute([$id]);
            $check($guard->check($role)['status']===303&&!isset($_SESSION['auth']),'fresh suspension check');
        }
    }}
    $check($service->authenticate($prefix.'-absent@example.invalid',$password)===null,'unknown account rejected');
    $check($service->authenticate("' OR 1=1 --",$password)===null,'prepared email lookup');
    $_SESSION=[];$check($guard->check('client')['status']===303,'anonymous redirect');
    $check($pdo->inTransaction(),'authentication must not end fixture transaction');
    $pdo->rollBack();$phase='cleanup';$check($snapshot()===$before,'all 20 row counts and content digests restored');
    echo "PASS: $checks authentication isolated database checks; all fixtures rolled back; no SMTP.\n";
}catch(Throwable){if($pdo instanceof PDO&&$pdo->inTransaction()){$pdo->rollBack();}fwrite(STDERR,"FAIL: authentication database stage $phase; raw details withheld.\n");exit(1);}
finally{if($pdo instanceof PDO&&$pdo->inTransaction()){$pdo->rollBack();}$_SESSION=[];}
