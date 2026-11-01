<?php
declare(strict_types=1);
namespace GigHub\Tests\Http;
use PDO;
use RuntimeException;

/** Browsing-only cleanup gate. No work occurs on include; pure validators are tested offline. */
final class BrowseCleanupSafety
{
    public static function unseal(array $record,string $key): array
    {
        $raw=$record['payload_json']??null;$mac=$record['mac']??null;
        if(!preg_match('/\A[0-9a-f]{64}\z/D',$key)||!is_string($raw)||strlen($raw)>65536||!is_string($mac)
            ||!preg_match('/\A[0-9a-f]{64}\z/D',$mac)||!hash_equals(hash_hmac('sha256',$raw,$key),$mac))throw new RuntimeException('Untrusted lifecycle evidence.');
        $payload=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($payload))throw new RuntimeException('Invalid lifecycle evidence.');return $payload;
    }
    public static function validate(array $state,string $run,string $hash,array $start,array $end,?array $ready,?array $stop,array $os,bool $claimed): string
    {
        BrowsePolicy::validateState($state,$run);
        if($state['auth_requests_enabled']!==false||$claimed||!preg_match('/\A[0-9a-f]{64}\z/D',$hash))throw new RuntimeException('Cleanup unavailable.');
        $script=strtolower(str_replace('\\','/',__DIR__.'/server.py'));
        if(($start['version']??null)!==1||($start['phase']??null)!=='attempted'||($start['run']??null)!==$run
            ||!is_string($start['attempt']??null)||!preg_match('/\A[0-9a-f]{32}\z/D',$start['attempt'])
            ||!is_int($start['pid']??null)||$start['pid']<1||$start['pid']>4294967295
            ||strtolower(str_replace('\\','/',$start['script']??''))!==$script
            ||($start['database']??null)!=='gighub_schema_test'||($start['bind']??null)!=='127.0.0.1:8765'
            ||($start['fixture_sha256']??null)!==$hash||!is_int($start['started_ms']??null)||$start['started_ms']<1)throw new RuntimeException('Invalid startup evidence.');
        foreach(['version','run','attempt','pid','script','database','bind','fixture_sha256','started_ms'] as $field)if(($end[$field]??null)!==$start[$field])throw new RuntimeException('Inconsistent lifecycle evidence.');
        if(!is_int($end['ended_ms']??null)||$end['ended_ms']<$start['started_ms']||!is_array($end['children']??null)
            ||!array_is_list($end['children'])||count($end['children'])>256)throw new RuntimeException('Incomplete terminal evidence.');
        $seen=[];
        foreach($end['children'] as $child) {
            if(!is_array($child)||!is_int($child['pid']??null)||$child['pid']<1||$child['pid']>4294967295||$child['pid']===$start['pid']
                ||in_array($child['pid'],$seen,true)||!is_int($child['returncode']??null))throw new RuntimeException('Unfinished or uncertain child.');
            $seen[]=$child['pid'];
        }
        self::quiescent($os);
        if(($end['phase']??null)==='failed_before_ready'&&($end['ready_reached']??null)===false&&$ready===null&&$stop===null)return 'failed_before_ready';
        if(($end['phase']??null)==='stopped'&&($end['ready_reached']??null)===true&&$ready!==null&&$stop!==null
            &&($ready['pid']??null)===$start['pid']&&($ready['bind']??null)===$start['bind']&&($ready['database']??null)===$start['database']
            &&($stop['graceful']??null)===true&&($stop['unfinished_children']??null)===[])return 'stopped';
        throw new RuntimeException('Readiness evidence is missing or contradictory.');
    }
    public static function quiescent(array $os): void
    {
        foreach(['runtime_absent','children_absent','php_workers_absent','matching_python_absent','listener_absent','complete'] as $key)if(($os[$key]??null)!==true)throw new RuntimeException('Runtime termination is not proven.');
    }
    public static function inventory(array $manifest,string $run,array $actual): void
    {
        JobFixtures::validateManifest($manifest,$run);
        foreach(['users','skills','jobs'] as $table) {
            if(!is_array($actual[$table]??null))throw new RuntimeException('Missing fixture inventory.');
            $expected=$manifest[$table];$ids=array_map('intval',$actual[$table]);sort($expected,SORT_NUMERIC);sort($ids,SORT_NUMERIC);
            if($ids!==$expected)throw new RuntimeException('Fixture IDs do not match.');
        }
    }
    private static function read(string $file): array
    {
        if(!is_file($file)||is_link($file)||filesize($file)>131072)throw new RuntimeException('Missing lifecycle evidence.');
        $data=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Invalid evidence.');return $data;
    }
    public static function evidence(string $dir,string $run,array $state): array
    {
        if(strtolower(str_replace('\\','/',realpath($dir)?:''))!==strtolower(runtime($run)))throw new RuntimeException('Unexpected runtime directory.');
        $key=trim(file_get_contents($dir.'/stop.key'));
        $start=self::unseal(self::read($dir.'/browse-startup.json'),$key);
        $end=self::unseal(self::read($dir.'/browse-terminal.json'),$key);
        $hash=hash_file('sha256',$dir.'/job-fixtures.json');
        $ready=is_file($dir.'/server-ready.json')?self::read($dir.'/server-ready.json'):null;
        $stop=is_file($dir.'/server-stopped.json')?self::read($dir.'/server-stopped.json'):null;
        if($ready!==null&&($stop===null||filemtime($dir.'/server-stopped.json')<filemtime($dir.'/server-ready.json')))throw new RuntimeException('Stale normal shutdown evidence.');
        // Validate evidence before using any PID in a local OS observation.
        $proof=array_fill_keys(['runtime_absent','children_absent','php_workers_absent','matching_python_absent','listener_absent','complete'],true);
        $mode=self::validate($state,$run,$hash,$start,$end,$ready,$stop,$proof,file_exists($dir.'/browse-cleanup-claimed.json'));
        $os=self::observe($run,$start['pid'],array_column($end['children'],'pid'));self::quiescent($os);
        return ['run'=>$run,'attempt'=>$start['attempt'],'fixture_sha256'=>$hash,'pid'=>$start['pid'],'children'=>array_column($end['children'],'pid'),'mode'=>$mode];
    }
    public static function observe(string $run,int $pid,array $children): array
    {
        JobFixtures::prefix($run);
        if($pid<1||count($children)>256)throw new RuntimeException('Invalid process evidence.');
        foreach($children as $id)if(!is_int($id)||$id<1)throw new RuntimeException('Invalid child evidence.');
        $command=['C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe','-NoProfile','-NonInteractive','-File',__DIR__.'/browse-process-observation.ps1','-Run',$run,'-RuntimePid',(string)$pid,'-CleanupPid',(string)getmypid(),'-ChildPids',$children===[]?'none':implode(',',$children)];
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Process inspection unavailable.');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$output='';$deadline=microtime(true)+25;
        do {
            $output.=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);$status=proc_get_status($process);
            if(strlen($output)>8192)throw new RuntimeException('Invalid process observation.');
            if(!$status['running'])break;
            if(microtime(true)>$deadline)throw new RuntimeException('Process inspection timed out; cleanup refused.');
            usleep(20000);
        }while(true);
        $output.=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        if($status['exitcode']!==0)throw new RuntimeException('Process inspection failed.');
        $data=json_decode(trim($output),true,16,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Invalid process observation.');return $data;
    }
    public static function assertComplete(PDO $p,string $dir,string $run,array $evidence): void
    {
        TestDatabase::identity($p->query('SELECT DATABASE() db,@@port port,@@datadir datadir,VERSION() version,CURRENT_USER() account')->fetch());
        if(!hash_equals($evidence['fixture_sha256'],hash_file('sha256',$dir.'/job-fixtures.json')))throw new RuntimeException('Fixture inventory changed.');
        $m=BrowseFixtures::assertInventory($p,$dir,$run);$actual=[];
        foreach(['users','skills','jobs'] as $table)$actual[$table]=$p->query('SELECT id FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
        self::inventory($m,$run,$actual);
    }
    public static function claim(string $dir,array $evidence): void
    {
        $handle=fopen($dir.'/browse-cleanup-claimed.json','x');if($handle===false)throw new RuntimeException('Cleanup already claimed.');
        try{$json=json_encode($evidence,JSON_THROW_ON_ERROR);if(fwrite($handle,$json)!==strlen($json))throw new RuntimeException('Cleanup claim failed.');}finally{fclose($handle);}
    }
}
