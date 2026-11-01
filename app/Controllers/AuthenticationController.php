<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthSession;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\LoginThrottle;
use GigHub\Services\LoginValidator;
use RuntimeException;

final class AuthenticationController
{
    public function __construct(private readonly Closure $serviceFactory,private readonly AuthSession $session) {}
    public function show(): array
    {
        if($this->session->userId()!==null) {
            $access=(new AuthGuard($this->serviceFactory,$this->session))->check();
            if(isset($access['user'])) { return self::redirect(AuthSession::route($access['user']['role'])); }
            if($access['status']===503) { return $access; }
        }
        return $this->form();
    }
    public function login(#[\SensitiveParameter] array $input): array
    {
        $data=LoginValidator::validate($input);
        // Bounded safe email only; passwords never enter view data or session storage.
        $email=mb_check_encoding($data['email'],'UTF-8')?substr($data['email'],0,254):'';
        if(!Csrf::valid($input['csrf_token']??null,'login')) { return $this->form($email,['form'=>'Your form session could not be verified. Enter your password again and retry.'],403); }
        $limit=LoginThrottle::check();
        if(!$limit['allowed']) {
            return $this->form($email,['form'=>'Too many sign-in attempts. Try again in about '.(int)ceil($limit['retry_after']/60).' minutes.'],429)+['retry_after'=>$limit['retry_after']];
        }
        if($data['errors']!==[]) { LoginThrottle::failure();return $this->form($email,$data['errors'],422); }
        try {
            $user=($this->serviceFactory)()->authenticate($data['email'],$data['password']);
            if($user===null) { LoginThrottle::failure();return $this->form($email,['form'=>'Please check your email address and password, then try again.'],422); }
            $this->session->establish($user['id']);
            return self::redirect(AuthSession::route($user['role']));
        } catch(RuntimeException) { return $this->form($email,['form'=>'Sign-in is temporarily unavailable. Please try again later.'],503); }
    }
    public function logout(array $input): array
    {
        if(!Csrf::valid($input['csrf_token']??null,'logout')) { return ['status'=>403,'type'=>'text/plain; charset=UTF-8','body'=>'Your form session could not be verified. Reload the page and retry.']; }
        try { $this->session->logout(); }
        catch(RuntimeException) { return ['status'=>503,'type'=>'text/plain; charset=UTF-8','body'=>'Session cleanup could not be confirmed. Close this browser window.']; }
        return self::redirect('/login');
    }
    private static function redirect(string $route): array { return ['status'=>303,'type'=>'text/plain; charset=UTF-8','body'=>'','location'=>'?route='.$route]; }
    private function form(string $email='',array $errors=[],int $status=200): array
    {
        $token=Csrf::token('login');$escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ob_start();require dirname(__DIR__).'/Views/login.php';$body=ob_get_clean();
        return ['status'=>$status,'type'=>'text/html; charset=UTF-8','body'=>$body];
    }
}
