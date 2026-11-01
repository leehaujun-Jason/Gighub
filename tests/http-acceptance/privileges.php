<?php
declare(strict_types=1);
// Administrative lifecycle only. Never loaded by the HTTP host or front controller.
if(PHP_SAPI!=='cli')exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');
$stage='identity';$root='C:/MariaDB_Recovery/GigHub_Clean_Test';
try{
    require __DIR__.'/database.php';$dir=GigHub\Tests\Http\runtime($argv[2]??'');$action=$argv[1]??'';
    ob_start();try{$config=require $root.'/private/administrator.local.php';}finally{ob_end_clean();}
    $s=$config['connection'];if($s['host']!=='127.0.0.1'||$s['port']!==3309||$s['username']!=='root')throw new RuntimeException();
    $p=new PDO('mysql:host=127.0.0.1;port=3309;dbname=gighub_schema_test;charset=utf8mb4',$s['username'],$s['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);unset($s,$config);
    $id=$p->query('SELECT @@port port,@@datadir datadir,VERSION() version')->fetch();
    if((int)$id['port']!==3309||strtolower(str_replace('\\','/',$id['datadir']))!==strtolower($root.'/data/')||!str_contains($id['version'],'10.4.32-MariaDB'))throw new RuntimeException();
    $grants=static function()use($p):array{
        $result=[];$grantee="'gighub_test'@'127.0.0.1'";
        foreach(['USER_PRIVILEGES'=>'PRIVILEGE_TYPE,IS_GRANTABLE','SCHEMA_PRIVILEGES'=>'TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE','TABLE_PRIVILEGES'=>'TABLE_SCHEMA,TABLE_NAME,PRIVILEGE_TYPE,IS_GRANTABLE'] as $view=>$columns){$q=$p->prepare('SELECT '.$columns.' FROM information_schema.'.$view.' WHERE GRANTEE=? ORDER BY '.$columns);$q->execute([$grantee]);$result[$view]=$q->fetchAll();}return $result;
    };
    $snapshot=static function()use($p):array{
        $p->exec('SET TRANSACTION READ ONLY');$p->beginTransaction();$out=[];
        foreach($p->query('SHOW TABLES FROM gighub_dev')->fetchAll(PDO::FETCH_COLUMN) as $table){if(!preg_match('/\A[a-z_]+\z/',$table))throw new RuntimeException();$rows=$p->query('SELECT * FROM gighub_dev.`'.$table.'`')->fetchAll(PDO::FETCH_NUM);$e=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($e,SORT_STRING);$out[$table]=['count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$e))];}$p->rollBack();return $out;
    };
    $tables=['users','client_profiles','freelancer_profiles','email_verification_tokens'];
    if($action==='before'){
        $stage='baseline';if(file_exists($dir.'/before.json'))throw new RuntimeException();
        foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t){if((int)$p->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn()!==0)throw new RuntimeException();}
        $g=$grants();foreach($g as $rows){foreach($rows as $row){if($row['PRIVILEGE_TYPE']==='DELETE')throw new RuntimeException();}}
        $fk=$p->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA='gighub_schema_test' AND REFERENCED_TABLE_NAME IN ('users','client_profiles','freelancer_profiles','email_verification_tokens') ORDER BY TABLE_NAME,COLUMN_NAME")->fetchAll();
        $triggers=$p->query("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE FROM information_schema.triggers WHERE TRIGGER_SCHEMA='gighub_schema_test' AND EVENT_OBJECT_TABLE IN ('users','client_profiles','freelancer_profiles','email_verification_tokens')")->fetchAll();if($triggers!==[])throw new RuntimeException();
        require dirname(__DIR__,2).'/app/Core/Config.php';$app=GigHub\Core\Config::load(dirname(__DIR__,2).'/config');if(($app['database']['port']??null)!==3309||($app['database']['name']??null)!=='gighub_dev'||($app['mail']['enabled']??null)!==false||($app['mail']['diagnostics']??false)!==false)throw new RuntimeException();
        $b=['development'=>$snapshot(),'grants'=>$g,'foreign_keys'=>$fk,'fixture_table_triggers'=>$triggers,'config_hash'=>hash_file('sha256',dirname(__DIR__,2).'/config/local.php'),'schema_hash'=>hash_file('sha256',dirname(__DIR__,2).'/database/schema.sql')];
        if(count($b['development'])!==20)throw new RuntimeException();file_put_contents($dir.'/before.json',json_encode($b,JSON_PRETTY_PRINT));echo 'PASS: baseline, empty isolated schema, FK dependencies, no fixture triggers.';exit;
    }
    $before=json_decode(file_get_contents($dir.'/before.json'),true,512,JSON_THROW_ON_ERROR);
    if($action==='grant'){
        $stage='grant';if($grants()!==$before['grants']||file_exists($dir.'/grants-added.json'))throw new RuntimeException();
        $added=[];foreach($tables as $table){$p->exec('GRANT DELETE ON `gighub_schema_test`.`'.$table.'` TO \'gighub_test\'@\'127.0.0.1\'');$added[]=$table;file_put_contents($dir.'/grants-added.json',json_encode($added));}
        $after=$grants();$deletes=[];foreach($after['TABLE_PRIVILEGES'] as $g){if($g['PRIVILEGE_TYPE']==='DELETE'){if($g['TABLE_SCHEMA']!=='gighub_schema_test')throw new RuntimeException();$deletes[]=$g['TABLE_NAME'];}}
        sort($deletes);$expected=$tables;sort($expected);if($deletes!==$expected||$after['USER_PRIVILEGES']!==$before['grants']['USER_PRIVILEGES']||$after['SCHEMA_PRIVILEGES']!==$before['grants']['SCHEMA_PRIVILEGES'])throw new RuntimeException();
        file_put_contents($dir.'/grants-during.json',json_encode($after,JSON_PRETTY_PRINT));echo 'PASS: exactly four table DELETE grants; global/schema grants unchanged.';exit;
    }
    if($action==='revoke'){
        $stage='revoke';$added=is_file($dir.'/grants-added.json')?json_decode(file_get_contents($dir.'/grants-added.json'),true):[];$removed=[];$failed=[];
        foreach($added as $table){if(!in_array($table,$tables,true))throw new RuntimeException();try{$p->exec('REVOKE DELETE ON `gighub_schema_test`.`'.$table.'` FROM \'gighub_test\'@\'127.0.0.1\'');$removed[]=$table;}catch(Throwable){$failed[]=$table;}}
        $after=$grants();$result=['removed'=>$removed,'failed'=>$failed,'grants_restored_exactly'=>$after===$before['grants']];file_put_contents($dir.'/revocation.json',json_encode($result,JSON_PRETTY_PRINT));if($failed!==[]||!$result['grants_restored_exactly'])throw new RuntimeException();echo json_encode($result);exit;
    }
    if($action==='after'){
        $stage='integrity';$result=['all_20_development_tables_readable'=>count($snapshot())===20,'development_counts_and_content_digests_unchanged'=>$snapshot()===$before['development'],'private_configuration_unchanged'=>hash_file('sha256',dirname(__DIR__,2).'/config/local.php')===$before['config_hash'],'schema_unchanged'=>hash_file('sha256',dirname(__DIR__,2).'/database/schema.sql')===$before['schema_hash'],'original_grants_restored'=>$grants()===$before['grants']];file_put_contents($dir.'/after.json',json_encode($result,JSON_PRETTY_PRINT));echo json_encode($result);if(in_array(false,$result,true))exit(1);exit;
    }
    throw new RuntimeException();
}catch(Throwable){fwrite(STDERR,'FAIL: administrative lifecycle stage '.$stage.'; raw details withheld. Revoke any recorded temporary grants before further tests.'.PHP_EOL);exit(1);}
