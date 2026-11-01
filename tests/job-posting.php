<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
$root=dirname(__DIR__);$count=0;
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);++$count;};
if(!is_file($root.'/app/Services/JobPostingValidator.php')){fwrite(STDERR,"FAIL: Job Posting validator is not implemented.\n");exit(1);}
require $root.'/app/Services/JobPostingValidator.php';
use GigHub\Services\JobPostingValidator as V;
$today=new DateTimeImmutable('2026-11-01',new DateTimeZone('Asia/Kuala_Lumpur'));
$base=['title'=>'A useful project','description'=>str_repeat('a',50),'budget'=>'1500.00','deadline'=>'2026-11-02','skills'=>['1'],'intent'=>'publish'];
$valid=static fn(array $change=[],bool $publish=true)=>V::validate(array_replace($base,$change),$publish,$today);
$check($valid()['errors']===[],'valid publication');
foreach([4=>false,5=>true,150=>true,151=>false] as $n=>$ok){$check(!isset($valid(['title'=>str_repeat('界',$n)])['errors']['title'])===$ok,'Unicode title '.$n);}
foreach([49=>false,50=>true,10000=>true,10001=>false] as $n=>$ok){$check(!isset($valid(['description'=>str_repeat('🌏',$n)])['errors']['description'])===$ok,'Unicode description '.$n);}
foreach(['0.01','99999999.99','1','12.3'] as $money){$check(!isset($valid(['budget'=>$money])['errors']['budget']),'valid decimal '.$money);}
foreach(['0','-1','100000000','1.001','1e2','1,000','NaN','+1',[],1.5] as $money){$check(isset($valid(['budget'=>$money])['errors']['budget']),'invalid money');}
$check($valid(['budget'=>'12.3'])['data']['budget']==='12.30','canonical decimal string');
foreach(['2026-02-30','2026-2-02','bad','',[],"2026-11-02\0"] as $date){$check(isset($valid(['deadline'=>$date])['errors']['deadline']),'invalid date');}
$check(isset($valid(['deadline'=>'2026-11-01'])['errors']['deadline']),'today cannot publish');
$check(!isset($valid(['deadline'=>'2020-01-01'],false)['errors']['deadline']),'draft historical date allowed');
$check(isset($valid(['deadline'=>'1000-01-01'],true)['errors']['deadline']),'past publication rejected');
$check(isset($valid(['skills'=>[]])['errors']['skills']),'publish requires skills');
$check($valid(['skills'=>[]],false)['errors']===[],'draft permits no skills');
$check(!isset($valid(['skills'=>range(1,10)])['errors']['skills']),'ten skills');
foreach([range(1,11),['1','1'],['0'],['-1'],['1e2'],[['1']],['4294967296'],'1', [true]] as $skills){$check(isset($valid(['skills'=>$skills])['errors']['skills']),'malformed skills');}
foreach(['title','description','budget','deadline'] as $field){$check(isset($valid([$field=>[]])['errors'][$field]),'array rejected '.$field);}
$check(isset($valid(['title'=>"\xff"])['errors']['title']),'invalid UTF8');
$check(isset($valid(['description'=>"abc\0".str_repeat('a',50)])['errors']['description']),'NUL rejected');
$check(V::validate(array_replace($base,['deadline'=>'2026-11-02']),true,new DateTimeImmutable('2026-11-01 16:01:00',new DateTimeZone('UTC')))['errors']!==[],'Malaysia date boundary');
echo "PASS: $count Job Posting validator checks; offline only.\n";
