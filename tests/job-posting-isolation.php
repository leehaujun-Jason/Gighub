<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');
$f=__DIR__.'/http-acceptance/job-fixtures.php';
if(!is_file($f)){fwrite(STDERR,"FAIL: Job Posting fixture guards not implemented.\n");exit(1);}
require __DIR__.'/http-acceptance/database.php';require dirname(__DIR__).'/app/Repositories/JobRepository.php';require $f;
use GigHub\Tests\Http\JobFixtures as F;
$n=0;$check=static function(bool $v)use(&$n){if(!$v)throw new RuntimeException('Fixture guard assertion failed.');++$n;};
$run='accept-0123456789abcdef';$m=['run'=>$run,'users'=>[1,2],'skills'=>[3,4],'jobs'=>[5]];
F::validateManifest($m,$run);$check(true);
foreach(['run'=>'other','users'=>['1'],'skills'=>[0],'jobs'=>[5,5]] as $key=>$value){try{F::validateManifest(array_replace($m,[$key=>$value]),$run);$check(false);}catch(InvalidArgumentException){$check(true);}}
foreach([[],['run'=>$run,'users'=>[],'skills'=>[],'jobs'=>[],'extra'=>1]] as $v){try{F::validateManifest($v,$run);$check(false);}catch(InvalidArgumentException){$check(true);}}
F::validateState(['run'=>$run,'port'=>8765,'job_posting_enabled'=>true],$run);$check(true);
foreach(['run'=>'other','port'=>3309,'job_posting_enabled'=>false] as $key=>$value){try{F::validateState(array_replace(['run'=>$run,'port'=>8765,'job_posting_enabled'=>true],[$key=>$value]),$run);$check(false);}catch(InvalidArgumentException){$check(true);}}
$check(strlen(F::prefix($run))<50);$check(F::tables()===['users','client_profiles','freelancer_profiles','email_verification_tokens','skills','jobs','job_skills']);
$source=file_get_contents(__DIR__.'/http-acceptance/front.php');
$check(!str_contains($source,'Config::load(')&&!str_contains($source,'config/local.php')&&!str_contains($source,'administrator.local.php'));
$check(str_contains($source,'TrackedJobRepository')&&str_contains($source,'assertInventory($pdo,$dir,$run)'));
$control=file_get_contents(__DIR__.'/http-acceptance/job-control.php');
$check(str_contains($control,"IntegrationDatabase::connection(['job-control','--run-isolated'])"));
$check(!preg_match('/\b(?:GRANT|REVOKE|TRUNCATE)\b/',$control));
$old=file_get_contents(__DIR__.'/http-acceptance/control.php');$check(str_contains($old,"Use the separate reviewed job fixture lifecycle."));
$db=file_get_contents(__DIR__.'/job-posting-database.php');$check(substr_count($db,'IntegrationDatabase::connection(')===1&&!str_contains($db,'Config::load('));
echo "PASS: $n Job Posting fixture isolation guards; no database or runtime access.\n";
