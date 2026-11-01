<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
set_exception_handler(static function(Throwable $e):void{while(ob_get_level()>0)ob_end_clean();fwrite(STDERR,$e instanceof RuntimeException?$e->getMessage().PHP_EOL:'FAIL: test rendering dependency.'.PHP_EOL);exit(1);});
$root=dirname(__DIR__);foreach(['Core/Csrf','Core/RegistrationThrottle','Core/VerificationThrottle','Core/AuthSession','Core/LoginThrottle','Services/LoginValidator','Services/RegistrationValidator','Controllers/AuthenticationController','Controllers/RegistrationController','Controllers/EmailVerificationController'] as $class)require_once $root.'/app/'.$class.'.php';
$count=0;$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);++$count;};
$_SESSION=[];$never=static function(){throw new RuntimeException('Unexpected service invocation');};
$login=new GigHub\Controllers\AuthenticationController($never,new GigHub\Core\AuthSession());$html=$login->show()['body'];
$check(str_contains($html,'Forgot password?')&&str_contains($html,'(Coming soon)'),'honest reset placeholder');
$check(!preg_match('/<a\b[^>]*>[^<]*Forgot password/i',$html)&&!str_contains($html,'?route=/password'),'no fake reset link');
$check(!str_contains($html,'?route=/verify-email')&&!str_contains($html,'Resend email verification')&&!str_contains($html,'Need to verify your email?'),'login excludes verification navigation');
$check(str_contains($html,'New to GigHub?')&&str_contains($html,'href="?route=/register">Create an account</a>'),'login registration navigation');
$register=new GigHub\Controllers\RegistrationController($never);$html=$register->show()['body'];
$check(str_contains($html,'Already have an account?')&&str_contains($html,'href="?route=/login">Sign in</a>'),'registration sign-in navigation');
$verification=new GigHub\Controllers\EmailVerificationController($never);
foreach(['confirm','resend','verified','invalid','resent','unavailable'] as $mode){
    if($mode==='confirm')$response=$verification->show();elseif($mode==='resend')$response=$verification->resendForm();else{$_SESSION['verification_result_notice']=$mode;$response=$verification->result();}
    $check(str_contains($response['body'],'Already verified your email?')&&str_contains($response['body'],'href="?route=/login">Sign in</a>'),'verification navigation '.$mode);
}
echo "PASS: $count authentication navigation checks; no database or mail access.\n";
