<?php
declare(strict_types=1);
namespace GigHub\Controllers;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;

final class AccountHomeController
{
    public function __construct(private readonly AuthGuard $guard) {}
    public function show(string $role): array
    {
        $access=$this->guard->check($role);if(!isset($access['user'])) { return $access; }
        $user=$access['user'];$token=Csrf::token('logout');$escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ob_start();require dirname(__DIR__).'/Views/account-home.php';$body=ob_get_clean();
        return ['status'=>200,'type'=>'text/html; charset=UTF-8','body'=>$body];
    }
}
