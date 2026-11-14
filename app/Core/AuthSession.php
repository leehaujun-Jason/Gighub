<?php
declare(strict_types=1);
namespace GigHub\Core;

use Closure;
use RuntimeException;
use Throwable;

final class AuthSession
{
    public function __construct(private readonly ?Closure $clock=null,private readonly ?Closure $regenerate=null,
        private readonly ?Closure $destroy=null,private readonly ?Closure $expireCookie=null) {}

    private function now(): int { return $this->clock!==null?($this->clock)():time(); }

    public function establish(int $id, ?string $credentialStamp = null): void
    {
        unset($_SESSION['auth']);
        try {
            if($id<1||!($this->regenerate!==null?($this->regenerate)():session_regenerate_id(true))) { throw new RuntimeException(); }
        } catch(Throwable) { throw new RuntimeException('Session initialization unavailable.'); }
        $now=$this->now();
        $_SESSION=['_initialized'=>true,'auth'=>['user_id'=>$id,'issued_at'=>$now,'last_activity'=>$now]];
        if ($credentialStamp !== null) { $_SESSION['auth']['credential_stamp'] = $credentialStamp; }
        Csrf::rotate('login');Csrf::rotate('logout');
    }

    public function userId(): ?int
    {
        $a=$_SESSION['auth']??null;$now=$this->now();
        if(!is_array($a)||!is_int($a['user_id']??null)||$a['user_id']<1||!is_int($a['issued_at']??null)||!is_int($a['last_activity']??null)
            ||$a['issued_at']>$now||$a['last_activity']>$now||$a['last_activity']<$a['issued_at']
            ||$now-$a['issued_at']>=28800||$now-$a['last_activity']>=1800) { $this->forget();return null; }
        return $a['user_id'];
    }

    public function matchesCredential(array $user): bool
    {
        $current = $user['credential_stamp'] ?? null;
        $stored = $_SESSION['auth']['credential_stamp'] ?? null;
        return $current === null ? $stored === null : is_string($stored) && hash_equals($current, $stored);
    }

    public function touch(): void { if(isset($_SESSION['auth'])) { $_SESSION['auth']['last_activity']=$this->now(); } }
    public function forget(): void { unset($_SESSION['auth']); }

    public function logout(): void
    {
        $_SESSION=[];
        $expired=false;$destroyed=false;
        try {
            if($this->expireCookie!==null) { $expired=($this->expireCookie)(); }
            else {
                $params=session_get_cookie_params();
                $expired=setcookie(session_name(),'',[
                    'expires'=>time()-3600,'path'=>$params['path'],'domain'=>$params['domain'],
                    'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>$params['samesite']??'Lax',
                ]);
            }
        } catch(Throwable) { /* Always attempt server-side destruction, even if cookie expiry fails. */ }
        try { $destroyed=$this->destroy!==null?($this->destroy)():session_destroy(); }
        catch(Throwable) { /* Return only the generic cleanup failure below. */ }
        if(!$expired||!$destroyed) { throw new RuntimeException('Session cleanup unavailable.'); }
    }

    public static function route(string $role): string
    {
        return match($role) {'client'=>'/client','freelancer'=>'/freelancer','admin'=>'/admin',default=>throw new RuntimeException('Account unavailable.')};
    }
}
