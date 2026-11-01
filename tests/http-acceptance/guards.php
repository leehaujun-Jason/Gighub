<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require __DIR__.'/database.php';
$count=0;$reject=static function(callable $f)use(&$count):void{try{$f();}catch(RuntimeException){++$count;return;}throw new RuntimeException('Guard did not reject invalid input.');};
$s=['enabled'=>true,'host'=>'127.0.0.1','port'=>3309,'name'=>'gighub_schema_test','username'=>'gighub_test','password'=>str_repeat('a',64)];
foreach(['enabled'=>false,'host'=>'localhost','port'=>3308,'name'=>'gighub_dev','username'=>'root','password'=>'invalid'] as $key=>$value){$reject(static fn()=>GigHub\Tests\Http\TestDatabase::validate(array_replace($s,[$key=>$value])));}
$id=['db'=>'gighub_schema_test','port'=>3309,'datadir'=>'C:/MariaDB_Recovery/GigHub_Clean_Test/data/','account'=>'gighub_test@127.0.0.1','version'=>'10.4.32-MariaDB'];
foreach(['db'=>'gighub_dev','port'=>3308,'datadir'=>'C:/xampp/mysql/data/','account'=>'root@localhost','version'=>'unexpected'] as $key=>$value){$reject(static fn()=>GigHub\Tests\Http\TestDatabase::identity(array_replace($id,[$key=>$value])));}
$reject(static fn()=>GigHub\Tests\Http\runtime('../other'));
echo "PASS: $count fail-closed HTTP startup guard checks; no database connection.\n";
