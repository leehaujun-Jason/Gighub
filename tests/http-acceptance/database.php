<?php
declare(strict_types=1);
namespace GigHub\Tests\Http;

use PDO;
use RuntimeException;
use Throwable;

/** Test-only CGI connection. The existing CLI guard and production Database are unchanged. */
final class TestDatabase
{
    public static function validate(#[\SensitiveParameter] array $s): void
    {
        if(($s['enabled']??null)!==true||($s['host']??null)!=='127.0.0.1'||($s['port']??null)!==3309
            ||($s['name']??null)!=='gighub_schema_test'||($s['username']??null)!=='gighub_test'
            ||!is_string($s['password']??null)||!preg_match('/\A[0-9a-f]{64}\z/D',$s['password'])) {
            throw new RuntimeException('Isolated HTTP database unavailable.');
        }
    }
    public static function identity(array $id): void
    {
        if(($id['db']??null)!=='gighub_schema_test'||(int)($id['port']??0)!==3309
            ||strtolower(str_replace('\\','/',(string)($id['datadir']??'')))!=='c:/mariadb_recovery/gighub_clean_test/data/'
            ||($id['account']??null)!=='gighub_test@127.0.0.1'||!str_contains($id['version']??'','10.4.32-MariaDB')) {
            throw new RuntimeException('Isolated HTTP database unavailable.');
        }
    }
    public static function connection(): PDO
    {
        try {
            if(!in_array(PHP_SAPI,['cli','cgi-fcgi'],true)){throw new RuntimeException();}
            ob_start();try{$config=require 'C:/MariaDB_Recovery/GigHub_Clean_Test/private/gighub-test.local.php';}finally{ob_end_clean();}
            $s=$config['database']??[];self::validate($s);
            // Constants only: no DSN/environment override or development-config fallback.
            $p=new PDO('mysql:host=127.0.0.1;port=3309;dbname=gighub_schema_test;charset=utf8mb4',$s['username'],$s['password'],[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>false,PDO::ATTR_TIMEOUT=>3]);unset($s,$config);
            self::identity($p->query('SELECT DATABASE() db,@@port port,@@datadir datadir,VERSION() version,CURRENT_USER() account')->fetch());
            $p->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $p->exec("SET SESSION time_zone='+00:00'");return $p;
        }catch(Throwable){throw new RuntimeException('Isolated HTTP database unavailable.');}
    }
}

function runtime(string $run): string
{
    if(!preg_match('/\Aaccept-[0-9a-f]{16}\z/D',$run)){throw new RuntimeException('Invalid isolated run.');}
    return 'C:/MariaDB_Recovery/GigHub_Clean_Test/http-acceptance/'.$run;
}
