<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');$p=null;$stage='arguments';
try {
    $action=$argv[1]??'';$run=$argv[2]??'';
    if(count($argv)!==3||!in_array($action,['preflight','seed','inspect','cleanup'],true))throw new RuntimeException();
    require __DIR__.'/database.php';require dirname(__DIR__,2).'/app/Repositories/JobRepository.php';
    require __DIR__.'/job-fixtures.php';require __DIR__.'/browse-policy.php';require __DIR__.'/browse-fixtures.php';require __DIR__.'/browse-cleanup-safety.php';require dirname(__DIR__).'/integration-bootstrap.php';
    $dir=GigHub\Tests\Http\runtime($run);$state=json_decode(file_get_contents($dir.'/run.json'),true,512,JSON_THROW_ON_ERROR);
    GigHub\Tests\Http\BrowsePolicy::validateState($state,$run,$action==='seed');
    $cleanupEvidence=$action==='cleanup'?GigHub\Tests\Http\BrowseCleanupSafety::evidence($dir,$run,$state):null;
    $p=GigHub\Tests\IntegrationDatabase::connection(['browse-control','--run-isolated']);$stage=$action;
    $denied=false;try{$p->query('SELECT COUNT(*) FROM gighub_dev.users');}catch(PDOException $e){$denied=(int)($e->errorInfo[1]??0)===1142;}
    if(!$denied)throw new RuntimeException();
    if($action==='preflight') {
        if(file_exists($dir.'/job-fixtures.json'))GigHub\Tests\Http\BrowseFixtures::assertInventory($p,$dir,$run);else GigHub\Tests\Http\JobFixtures::assertEmpty($p);
        echo 'PASS';exit;
    }
    if($action==='seed') {
        if($state['browse_fixture_writes_enabled']!==true||$state['auth_requests_enabled']!==false||file_exists($dir.'/server-ready.json')||file_exists($dir.'/job-fixtures.json')||file_exists($dir.'/browse-startup.json')||file_exists($dir.'/browse-cleanup-claimed.json'))throw new RuntimeException();
        GigHub\Tests\Http\JobFixtures::assertEmpty($p);
        $handle=fopen($dir.'/job-fixtures.json','x');if($handle===false)throw new RuntimeException();fclose($handle);
        GigHub\Tests\Http\JobFixtures::save($dir,$run,['run'=>$run,'users'=>[],'skills'=>[],'jobs'=>[]]);
        $p->beginTransaction();
        GigHub\Tests\Http\BrowseFixtures::populate($p,$run,static fn($m)=>GigHub\Tests\Http\JobFixtures::save($dir,$run,$m));
        GigHub\Tests\Http\BrowseFixtures::assertInventory($p,$dir,$run);$p->commit();echo 'PASS';exit;
    }
    if($action==='inspect') {
        $m=GigHub\Tests\Http\BrowseFixtures::assertInventory($p,$dir,$run);
        echo json_encode(['users'=>$m['users'],'skills'=>$m['skills'],'jobs'=>$m['jobs']],JSON_THROW_ON_ERROR);exit;
    }
    if($state['auth_requests_enabled']!==false)throw new RuntimeException();
    // Normal startup still requires server-ready.json/server-stopped.json and no unfinished_children;
    // the signed failed-before-ready path is independently verified before any database connection.
    GigHub\Tests\Http\BrowseCleanupSafety::assertComplete($p,$dir,$run,$cleanupEvidence);
    GigHub\Tests\Http\BrowseCleanupSafety::claim($dir,$cleanupEvidence);
    GigHub\Tests\Http\BrowseCleanupSafety::quiescent(GigHub\Tests\Http\BrowseCleanupSafety::observe($run,$cleanupEvidence['pid'],$cleanupEvidence['children']));
    GigHub\Tests\Http\BrowseFixtures::cleanup($p,$dir,$run);echo 'PASS: exact browsing fixtures removed; all 20 tables empty.';
}catch(Throwable){if($p instanceof PDO&&$p->inTransaction())$p->rollBack();fwrite(STDERR,'FAIL: browsing fixture '.$stage.'; preserve inventory; no automatic retry.'.PHP_EOL);exit(1);}
