<?php
declare(strict_types=1);
namespace GigHub\Core;

use Closure;
use RuntimeException;

final class AuthGuard
{
    public function __construct(private readonly Closure $serviceFactory,private readonly AuthSession $session) {}
    public function check(?string $role=null, bool $allowOnboarding=false): array
    {
        $id=$this->session->userId();
        if($id===null) { return self::login(); }
        try { $user=($this->serviceFactory)()->identity($id); }
        catch(RuntimeException) { return ['status'=>503,'type'=>'text/plain; charset=UTF-8','body'=>'Account access is temporarily unavailable.']; }
        if($user===null||!$this->session->matchesCredential($user)) { $this->session->forget();return self::login(); }
        if($role!==null&&$user['role']!==$role) { return ['status'=>403,'type'=>'text/plain; charset=UTF-8','body'=>'You do not have access to this page.']; }
        if (!$allowOnboarding && ($user['onboarding_pending'] ?? false)) {
            return ['status'=>303,'type'=>'text/plain; charset=UTF-8','body'=>'','location'=>'?route=/profile/setup'];
        }
        unset($user['credential_stamp']);
        $this->session->touch();return ['user'=>$user];
    }
    private static function login(): array { return ['status'=>303,'type'=>'text/plain; charset=UTF-8','body'=>'','location'=>'?route=/login']; }
}
