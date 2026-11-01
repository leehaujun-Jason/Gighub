<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
require dirname(__DIR__).'/app/Core/SecureSession.php';
require dirname(__DIR__).'/app/Core/AuthSession.php';
require dirname(__DIR__).'/app/Core/Csrf.php';
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);++$checks;};
$dir=sys_get_temp_dir().'/gighub-session-'.bin2hex(random_bytes(12));
if(!mkdir($dir,0700))exit(1);
try{
    session_save_path($dir);$_SERVER['HTTPS']='on';
    GigHub\Core\SecureSession::start(['name'=>'GIGHUBTESTSESSION','path'=>'/GigHub/public/']);
    $old=session_id();$auth=new GigHub\Core\AuthSession();$auth->establish(7);$current=session_id();
    $check($old!==$current&&$auth->userId()===7,'native ID rotation');
    $check(!is_file($dir.'/sess_'.$old),'old session file deleted');
    $params=session_get_cookie_params();$check($params['secure']&&$params['httponly']&&$params['samesite']==='Lax'&&$params['path']==='/GigHub/public/','cookie settings');
    $check(ini_get('session.use_strict_mode')==='1'&&ini_get('session.use_only_cookies')==='1'&&ini_get('session.use_trans_sid')==='0','strict cookie-only session');
    $auth->logout();$check($_SESSION===[]&&session_status()===PHP_SESSION_NONE&&!is_file($dir.'/sess_'.$current),'native logout destruction');
    session_id($current);GigHub\Core\SecureSession::start(['name'=>'GIGHUBTESTSESSION','path'=>'/GigHub/public/']);
    $check(session_id()!==$current&&$auth->userId()===null,'old ID cannot recover login');
    $auth->logout();echo "PASS: $checks native CLI session checks; no HTTP or database requests.\n";
}finally{
    if(session_status()===PHP_SESSION_ACTIVE){$_SESSION=[];session_destroy();}
    foreach(glob($dir.'/sess_*') as $file){unlink($file);}rmdir($dir);
}
