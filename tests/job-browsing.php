<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');
set_exception_handler(static function(Throwable $e):void{while(ob_get_level()>0)ob_end_clean();fwrite(STDERR,'FAIL: public browsing offline assertion: '.$e->getMessage().PHP_EOL);exit(1);});
$root=dirname(__DIR__);$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);++$n;};
foreach(['Services/JobBrowseValidator','Repositories/PublicJobRepository','Repositories/SkillRepository','Controllers/PublicJobController'] as $f){
 if(!is_file($root.'/app/'.$f.'.php')){fwrite(STDERR,"FAIL: public browsing implementation missing.\n");exit(1);}require $root.'/app/'.$f.'.php';
}
use GigHub\Services\JobBrowseValidator as V;
$valid=V::validate([]);$check($valid['errors']===[]&&$valid['data']['page']===1&&$valid['data']['sort']==='newest','defaults');
foreach(['q'=>[], 'min_budget'=>[], 'max_budget'=>[], 'sort'=>[], 'page'=>[], 'skills'=>'1'] as $key=>$v)$check(V::validate([$key=>$v])['errors']!==[],'array/scalar rejection '.$key);
foreach(['q','min_budget','max_budget','sort','page','skills'] as $key)$check(isset(V::validate([$key=>null])['errors'][$key]),'explicit null rejected '.$key);
foreach([100=>true,101=>false] as $length=>$ok)$check((V::validate(['q'=>str_repeat('界',$length)])['errors']===[])===$ok,'Unicode query boundary');
foreach(["\xff","a\0b",str_repeat('x',401)] as $q)$check(isset(V::validate(['q'=>$q])['errors']['q']),'invalid keyword');
$check(V::validate(['q'=>'  café  '])['data']['q']==='café','trim query');
foreach(['100','100.00','99999999.99','100000000','250.3'] as $value)$check(V::validate(['min_budget'=>$value])['errors']===[],'valid money');
foreach(['0','-1','99.99','100000000.01','1.001','1e2','01','1,000'] as $value)$check(isset(V::validate(['min_budget'=>$value])['errors']['min_budget']),'bad money');
$check(V::validate(['min_budget'=>'102.30','max_budget'=>'102.3'])['errors']===[],'inclusive equal range');
$check(isset(V::validate(['min_budget'=>'500','max_budget'=>'200'])['errors']['max_budget']),'exact reversed range');
foreach(['0','-1','1.5','1e2','100001',PHP_INT_MAX] as $page)$check(isset(V::validate(['page'=>$page])['errors']['page']),'bad page');
$check(V::validate(['page'=>'100000'])['data']['page']===100000,'bounded page');
$check(V::validate(['skills'=>range(1,10)])['errors']===[],'ten skills');
foreach([[1,1],range(1,11),[0],['4294967296'],[[1]],[true],['01'],['x'=>1]] as $ids)$check(isset(V::validate(['skills'=>$ids])['errors']['skills']),'bad skills');
$check(isset(V::validate(['sort'=>'budget; DROP TABLE jobs'])['errors']['sort']),'sort allowlist');
foreach(['newest','oldest','budget_low','budget_high'] as $sort)$check(V::validate(['sort'=>$sort])['errors']===[],'valid sort');

// Execute actual repository methods against PDO doubles: no database connection.
class BrowseStatement extends PDOStatement {
 public array $bound=[];
 public function __construct(public array $rows=[]){}
 public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool{$this->bound[$param]=[$value,$type];return true;}
 public function execute(?array $params=null):bool{return true;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return array_shift($this->rows)??false;}
}
class BrowsePdo extends PDO {
 public array $queries=[];public array $queue=[];
 public function __construct(){}
 public function prepare(string $query,array $options=[]):PDOStatement|false{$s=new BrowseStatement(array_shift($this->queue)??[]);$this->queries[]=[$query,$s];return $s;}
}
$p=new BrowsePdo();$r=new GigHub\Repositories\PublicJobRepository($p);
$filters=V::validate(['q'=>'50%_!','min_budget'=>'100','max_budget'=>'900','skills'=>['2','3'],'sort'=>'budget_low','page'=>'2'])['data'];
$r->browse($filters);[$sql,$statement]=$p->queries[0];
foreach(["j.status='open'","u.role='client'","u.status='active'",'u.email_verified_at IS NOT NULL','JOIN client_profiles','EXISTS','skill_id IN (?,?)','j.budget >= ?','j.budget <= ?','j.budget ASC,j.id ASC'] as $clause)$check(str_contains($sql,$clause),'public SQL '.$clause);
$check(substr_count($sql,'LIKE ?')===5&&substr_count($sql,"ESCAPE '!'")===5,'keyword fields escaped');
$values=array_column($statement->bound,0);$check($values[0]==='%50!%!_!!%'&&$values[1]===$values[0],'literal wildcard escaping');
$check(end($statement->bound)===[20,PDO::PARAM_INT],'integer offset');
$check(!str_contains($sql,'SELECT *')&&!str_contains($sql,'password')&&!str_contains($sql,'u.email,'),'public projection');
$p->queries=[];$r->find(5);$check(str_contains($p->queries[0][0],"j.status='open'")&&str_contains($p->queries[0][0],'u.email_verified_at IS NOT NULL'),'same detail privacy');
$row=['id'=>5,'title'=>'<script>bad</script>','description'=>str_repeat('界',200),'budget'=>'1234.50','deadline'=>'2020-01-01','created_at'=>'2026-01-01 00:00:00'];
$p->queries=[];$p->queue=[[$row],[['job_id'=>5,'id'=>2,'name'=>'PHP']]];$result=$r->browse(V::validate([])['data']);
$check(count($p->queries)===2&&$result[0]['skills'][0]['name']==='PHP','batched skills');
$p->queries=[];$p->queue=[[]];$check($r->browse(V::validate([])['data'])===[]&&count($p->queries)===1,'empty skips skills query');
foreach(['newest'=>'j.created_at DESC,j.id DESC','oldest'=>'j.created_at ASC,j.id ASC','budget_high'=>'j.budget DESC,j.id DESC','budget_low'=>'j.budget ASC,j.id ASC'] as $sort=>$order){$p->queries=[];$r->browse(V::validate(['sort'=>$sort])['data']);$check(str_contains($p->queries[0][0],$order),'stable ordering');}

class PublicBrowseDouble extends GigHub\Repositories\PublicJobRepository {
 public array $rows=[];public array|false $detail=false;public bool $fail=false;public int $reads=0;
 public function __construct(){}
 public function browse(array $filters):array{++$this->reads;if($this->fail)throw new RuntimeException('private driver details');return $this->rows;}
 public function find(int $id):array|false{++$this->reads;if($this->fail)throw new RuntimeException('private driver details');return $this->detail;}
}
class BrowseSkillsDouble extends GigHub\Repositories\SkillRepository {
 public function __construct(){}
 public function all():array{return [['id'=>2,'name'=>'PHP <unsafe>']];}
 public function existingIds(array $ids):array{return array_values(array_intersect($ids,[2]));}
}
$repo=new PublicBrowseDouble();$skills=new BrowseSkillsDouble();$controller=new GigHub\Controllers\PublicJobController(static fn()=>[$repo,$skills],static fn()=>new DateTimeImmutable('2026-11-01',new DateTimeZone('Asia/Kuala_Lumpur')));
$response=$controller->index([]);$check($response['status']===200&&str_contains($response['body'],'No jobs found'),'guest empty page');
$check(!str_contains($response['body'],'Page 1'),'empty pager hidden');
$check($controller->index(['skills'=>['999']])['status']===422,'nonexistent skill rejected');
$before=$repo->reads;$check($controller->index(['sort'=>[]])['status']===422&&$repo->reads===$before,'invalid query never searches');
$row['skills']=[['id'=>2,'name'=>'PHP <unsafe>']];$repo->rows=array_fill(0,21,$row);
$response=$controller->index(['q'=>'<unsafe>','skills'=>['2'],'min_budget'=>'100','sort'=>'budget_high']);$html=$response['body'];
$check($response['status']===200&&!str_contains($html,'<script>bad</script>')&&str_contains($html,'&lt;script&gt;bad&lt;/script&gt;'),'escaped title');
$check(str_contains($html,'&lt;unsafe&gt;')&&!str_contains($html,'PHP <unsafe>'),'escaped query/skills');
$check(substr_count($html,'gh-browse-card')===20&&str_contains($html,'card jobs-card gh-job-card'),'twenty cards');
$check(str_contains($html,'Delivery deadline has passed'),'overdue visible');
$check(str_contains($html,'page=2')&&str_contains($html,'budget_high')&&str_contains($html,'skills%5B0%5D=2')&&str_contains($html,'min_budget=100.00'),'preserved next filters');
$check(str_contains($html,'assets/browse-jobs.js')&&str_contains($html,'method="get"'),'browse jobs script and GET fallback');
$html=$controller->index(['page'=>'100000'])['body'];$check(!str_contains($html,'page=100001'),'no link beyond page bound');
$repo->rows=[$row];$check(str_contains($controller->index(['page'=>'2'])['body'],'Previous'),'previous page');
foreach([null,[],0,'0','1 OR 1=1','4294967296'] as $id)$check($controller->show(['id'=>$id])['status']===404,'uniform invalid ID 404');
$check($controller->show(['id'=>'5'])['status']===404,'uniform missing/nonpublic response');
$repo->detail=$row;$html=$controller->show(['id'=>'5'])['body'];
$check(str_contains($html,str_repeat('界',200))&&str_contains($html,'&lt;script&gt;bad&lt;/script&gt;'),'full escaped details');
$check(!str_contains($html,'Publish Draft')&&!str_contains($html,'Edit Draft')&&!str_contains($html,'type="submit"'),'no public mutation controls');
$repo->fail=true;$response=$controller->show(['id'=>'5']);$check($response['status']===503&&!str_contains($response['body'],'private driver'),'sanitized failure');
$repo->fail=false;$repo->detail=false;$missing=$controller->show(['id'=>'5']);
$check($missing===$controller->show(['id'=>'4294967296']),'identical hidden/missing response');
$repo->rows=[$row];$html=$controller->index([])['body'];
$check(str_contains($html,str_repeat('界',180).'…')&&!str_contains($html,str_repeat('界',200)),'Unicode-safe summary');
$check(str_contains($html,'1234.50')===false&&str_contains($html,'1,234.50'),'RM display');
$repo->fail=true;$check($controller->index([])['status']===503,'list unavailable safely');$repo->fail=false;
$routes=file_get_contents($root.'/bootstrap/app.php');
foreach(['/jobs','/jobs/show'] as $route)$check(str_contains($routes,"\$router->get('".$route."'"),'public GET route registered');
$check(!str_contains($routes,"\$router->post('/jobs',")&&!str_contains($routes,"\$router->post('/jobs/show',"),'no public write routes for jobs or show');
$check(str_contains(file_get_contents($root.'/app/Views/home.php'),'?route=/jobs'),'guest navigation');
$check(str_contains(file_get_contents($root.'/app/Views/account-home.php'),'?route=/jobs'),'signed-in navigation');
echo "PASS: $n public Job Browsing offline checks; no database/network.\n";
