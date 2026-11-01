<?php
declare(strict_types=1);
namespace GigHub\Core;

/** Local-development safeguard only: new anonymous sessions bypass this limit. */
final class LoginThrottle
{
    public static function check(?int $now=null): array
    {
        $now??=time();$attempts=$_SESSION['login_failures']??[];
        $attempts=is_array($attempts)?array_values(array_filter($attempts,static fn($at)=>is_int($at)&&$at>$now-900&&$at<=$now)):[];
        $_SESSION['login_failures']=$attempts;
        return count($attempts)>=5?['allowed'=>false,'retry_after'=>max(1,min($attempts)+900-$now)]:['allowed'=>true,'retry_after'=>0];
    }
    public static function failure(?int $now=null): void
    {
        $now??=time();self::check($now);$_SESSION['login_failures'][]=$now;
    }
}
