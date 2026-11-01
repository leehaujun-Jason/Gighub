<?php
declare(strict_types=1);
namespace GigHub\Tests\Http;
use InvalidArgumentException;

/** Pure request policy: no configuration loading, connections or filesystem access. */
final class BrowsePolicy
{
    public static function validateState(array $s,string $run,bool $allowFixtureWrites=false): void
    {
        if(!preg_match('/\Aaccept-[0-9a-f]{16}\z/D',$run)||($s['run']??null)!==$run||($s['port']??null)!==8765
            ||($s['job_browsing_enabled']??null)!==true||($s['job_posting_enabled']??false)!==false
            ||($s['mutations_enabled']??null)!==false||!is_bool($s['auth_requests_enabled']??null)
            ||!is_bool($s['browse_fixture_writes_enabled']??null)||!is_int($s['clock']??null)
            ||(!$allowFixtureWrites&&$s['browse_fixture_writes_enabled']!==false)) {
            throw new InvalidArgumentException('Invalid isolated browsing mode.');
        }
    }
    public static function allows(string $method,mixed $route,array $s): bool
    {
        if(!is_string($route)||($s['browse_fixture_writes_enabled']??null)!==false)return false;
        return ($method==='GET'&&in_array($route,['/jobs','/jobs/show','/login'],true))
            ||($method==='POST'&&($s['auth_requests_enabled']??false)===true&&in_array($route,['/login','/logout'],true));
    }
    public static function allowsLogin(mixed $email,string $run): bool
    {
        if(!is_string($email)||strlen($email)>254||!preg_match('/\Aaccept-[0-9a-f]{16}\z/D',$run))return false;
        $prefix='j'.substr($run,7).'-';
        return in_array(strtolower(trim($email)),[$prefix.'client@example.invalid',$prefix.'freelancer@example.invalid'],true);
    }
}
