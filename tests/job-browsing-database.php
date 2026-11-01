<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');
if(count($argv)!==2||$argv[1]!=='--run-isolated'){fwrite(STDERR,"Explicit isolated opt-in required.\n");exit(2);}
$p=null;$n=0;$stage='isolation';
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;};
try {
    require __DIR__.'/integration-bootstrap.php';require __DIR__.'/http-acceptance/database.php';
    foreach(['Repositories/JobRepository','Repositories/SkillRepository','Repositories/PublicJobRepository','Services/JobBrowseValidator','Controllers/PublicJobController'] as $f)require dirname(__DIR__).'/app/'.$f.'.php';
    require __DIR__.'/http-acceptance/job-fixtures.php';require __DIR__.'/http-acceptance/browse-fixtures.php';
    // The only connection: fixtures, repository, controller, counters and rollback all use this PDO.
    $p=GigHub\Tests\IntegrationDatabase::connection($argv);
    $denied=false;try{$p->query('SELECT COUNT(*) FROM gighub_dev.users');}catch(PDOException $e){$denied=(int)($e->errorInfo[1]??0)===1142;}$check($denied,'development access denied');
    GigHub\Tests\Http\JobFixtures::assertEmpty($p);$check(!$p->inTransaction(),'no inherited transaction');
    $tables=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables,SORT_STRING);
    $snapshot=static function()use($p,$tables):array{$out=[];foreach($tables as $t){if(!preg_match('/\A[a-z_]+\z/D',$t))throw new RuntimeException('table inventory');$rows=$p->query('SELECT * FROM `'.$t.'`')->fetchAll(PDO::FETCH_NUM);$encoded=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($encoded,SORT_STRING);$out[$t]=[count($rows),hash('sha256',implode("\n",$encoded))];}return $out;};
    $before=$snapshot();$p->beginTransaction();$stage='fixtures';$run='accept-'.bin2hex(random_bytes(8));$tracked=[];
    $m=GigHub\Tests\Http\BrowseFixtures::populate($p,$run,static function($ids)use(&$tracked){$tracked=$ids;});
    $check($m===$tracked&&count($m['jobs'])===28,'all fixture IDs tracked');
    $plan=GigHub\Tests\Http\BrowseFixtures::plan($run);$repo=new GigHub\Repositories\PublicJobRepository($p);$skills=new GigHub\Repositories\SkillRepository($p);
    $controller=new GigHub\Controllers\PublicJobController(static fn()=>[$repo,$skills],static fn()=>new DateTimeImmutable('2030-01-01',new DateTimeZone('UTC')));
    $filters=static fn(array $input=[])=>GigHub\Services\JobBrowseValidator::validate($input)['data'];
    $ids=static fn(array $rows):array=>array_map('intval',array_column($rows,'id'));
    $expected=[];foreach(array_slice($plan['jobs'],0,24) as $i=>$job){$job['id']=$m['jobs'][$i];$expected[]=$job;}
    $ordered=static function(string $sort,array $rows)use($ids):array{usort($rows,static function($a,$b)use($sort){$money=strcmp(str_pad($a['budget'],11,'0',STR_PAD_LEFT),str_pad($b['budget'],11,'0',STR_PAD_LEFT));return match($sort){'budget_low'=>$money?:$a['id']<=>$b['id'],'budget_high'=>-$money?:$b['id']<=>$a['id'],default=>strcmp($b['created_at'],$a['created_at'])?:$b['id']<=>$a['id']};});return $ids($rows);};
    $stage='visibility';$first=$repo->browse($filters());$check(count($first)===21,'bounded next-page probe');
    $all=array_merge(array_slice($first,0,20),$repo->browse($filters(['page'=>'2'])));$check(count($all)===24&&count(array_unique($ids($all)))===24,'only eligible open jobs across pages');
    $missing=$controller->show(['id'=>'4294967295']);$check($missing['status']===404,'missing detail 404');
    foreach(array_slice($m['jobs'],24) as $id){$check($repo->find($id)===false,'hidden repository detail');$check($controller->show(['id'=>(string)$id])===$missing,'identical hidden/missing response');}
    $detail=$repo->find($m['jobs'][0]);$check($detail!==false&&$detail['deadline']==='2029-12-31','overdue job remains public');
    $check(array_keys($detail)===['id','title','description','budget','deadline','created_at','skills'],'public projection excludes contact/workflow fields');
    $stage='search';foreach(['%','_','!','%_!','literal','café','界'] as $q)$check($ids($repo->browse($filters(['q'=>$q])))===[$m['jobs'][0]],'literal or Unicode keyword');
    $check($repo->browse($filters(['q'=>"' OR 1=1 --"]))===[],'SQL-shaped keyword is literal');
    $stage='budget';$check($ids($repo->browse($filters(['min_budget'=>'0.01','max_budget'=>'0.01'])))===[$m['jobs'][0]],'inclusive lower monetary boundary');
    $check($ids($repo->browse($filters(['min_budget'=>'99999999.99','max_budget'=>'99999999.99'])))===[$m['jobs'][23]],'inclusive upper monetary boundary');
    $range=array_values(array_filter($expected,static fn($j)=>in_array($j['budget'],['10.00','20.00'],true)));
    $check($ids($repo->browse($filters(['min_budget'=>'10','max_budget'=>'20'])))===$ordered('newest',$range),'inclusive range edges');
    $stage='skills';$match=array_values(array_filter($expected,static fn($j)=>array_intersect($j['skills'],[0,1])!==[]));
    $rows=$repo->browse($filters(['skills'=>array_slice($m['skills'],0,2)]));$check($ids($rows)===$ordered('newest',$match),'ANY skills and no duplicate jobs');
    foreach($all as $row){$index=array_search((int)$row['id'],$m['jobs'],true);$want=array_map(static fn($i)=>$m['skills'][$i],$plan['jobs'][$index]['skills']);sort($want);$actual=array_map('intval',array_column($row['skills'],'id'));sort($actual);$check($want===$actual,'complete batched skills');}
    $check($ids($repo->browse($filters(['q'=>'Literal','skills'=>[$m['skills'][1]],'min_budget'=>'0.01','max_budget'=>'0.01'])))===[$m['jobs'][0]],'combined filters');
    $check($repo->browse($filters(['q'=>'Literal','skills'=>[$m['skills'][2]]]))===[],'filters combine with AND');
    $stage='pagination';foreach(['newest','budget_low','budget_high'] as $sort){$want=$ordered($sort,$expected);$one=$repo->browse($filters(['sort'=>$sort]));$two=$repo->browse($filters(['sort'=>$sort,'page'=>'2']));$check($ids($one)===array_slice($want,0,21),'deterministic first page');$check($ids($two)===array_slice($want,20,20),'stable second page and tie-breaking');}
    $check($repo->browse($filters(['page'=>'3']))===[],'empty later page');
    $stage='query_count';$counter=static fn()=>(int)$p->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
    $start=$counter();$repo->browse($filters());$check($counter()-$start===2,'one list query plus one batched skills query');
    $start=$counter();$repo->browse($filters(['q'=>'no matching synthetic record']));$check($counter()-$start===1,'empty list skips skills query');
    $stage='controller';$response=$controller->index([]);$check($response['status']===200&&substr_count($response['body'],'class="card jobs-card"')===20,'real controller displays twenty jobs');
    $html=$controller->show(['id'=>(string)$m['jobs'][0]])['body'];$check(str_contains($html,'&lt;unsafe&gt;')&&str_contains($html,'&lt;script&gt;fixture&lt;/script&gt;')&&!str_contains($html,'<script>fixture</script>'),'real detail output escaped');
    $check(str_contains($html,'Delivery deadline has passed'),'overdue warning');
    $check($controller->index(['skills'=>['4294967295']])['status']===422,'nonexistent catalogue filter rejected');
    $check($p->inTransaction(),'outer fixture transaction retained');$p->rollBack();$stage='rollback';$check($snapshot()===$before,'all twenty tables restored after rollback');
    echo json_encode(['status'=>'PASS','passed'=>$n,'failed'=>0,'fixtures_rolled_back'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){if($p instanceof PDO&&$p->inTransaction())$p->rollBack();fwrite(STDERR,json_encode(['status'=>'FAIL','stage'=>$stage,'passed'=>$n,'sql_code'=>$e instanceof PDOException?(int)($e->errorInfo[1]??0):null,'details_withheld'=>true]).PHP_EOL);exit(1);}
finally{if($p instanceof PDO&&$p->inTransaction())$p->rollBack();}
