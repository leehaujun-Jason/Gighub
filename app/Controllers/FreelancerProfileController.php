<?php
declare(strict_types=1);
namespace GigHub\Controllers;
use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\PublicIdentity;
use GigHub\Services\FreelancerProfileException;
use GigHub\Services\FreelancerProfileValidator;
use Throwable;

final class FreelancerProfileController
{
    public function __construct(private readonly AuthGuard $guard,private readonly Closure $serviceFactory,private readonly ?PublicIdentity $identity=null) {}
    public function own(bool $edit=false): array
    {
        $access=$this->guard->check('freelancer'); if (!isset($access['user'])) return $access;
        try {
            $service=($this->serviceFactory)(); $profile=$service->profile((int)$access['user']['id']);
            if ($profile===false) return self::notFound();
            $data=['profile'=>$profile,'owner'=>true,'navigationUser'=>$access['user']];
            if ($edit) $data+=['skills'=>$service->catalogue(),'values'=>['headline'=>$profile['headline']??'','bio'=>$profile['bio']??'','skills'=>array_map('intval',array_column($profile['skills'],'id'))],'errors'=>[]];
            $data['saved']=!$edit && ($_SESSION['profile_saved']??false)===true;
            if (!$edit) unset($_SESSION['profile_saved']);
            return $this->render($edit?'edit':'show',$data);
        } catch (Throwable) { return self::unavailable(); }
    }
    public function update(array $input): array
    {
        $access=$this->guard->check('freelancer'); if (!isset($access['user'])) return $access;
        if (!Csrf::valid($input['csrf_token']??null,'freelancer_profile')) return ['status'=>403,'type'=>'text/plain; charset=UTF-8','body'=>'Your form expired. Reload the page and try again.'];
        try {
            $service=($this->serviceFactory)();
            try { $service->save((int)$access['user']['id'],$input); }
            catch (FreelancerProfileException $e) {
                if ($e->status!==422) return ['status'=>$e->status,'type'=>'text/plain; charset=UTF-8','body'=>'Your profile is unavailable.'];
                $values=FreelancerProfileValidator::validate($input)['data'];
                return $this->render('edit',['values'=>$values,'errors'=>$e->errors,'skills'=>$service->catalogue(),'navigationUser'=>$access['user']],422);
            }
            Csrf::rotate('freelancer_profile'); $_SESSION['profile_saved']=true;
            return ['status'=>303,'type'=>'text/plain; charset=UTF-8','body'=>'','location'=>'?route=/freelancer/profile'];
        } catch (Throwable) { return self::unavailable(); }
    }
    public function show(array $input): array
    {
        $id=$input['id']??null;
        if (!is_string($id) || !preg_match('/\A[1-9][0-9]{0,9}\z/D',$id) || (int)$id>4294967295) return self::notFound();
        try {
            $profile=($this->serviceFactory)()->profile((int)$id);
            return $profile===false?self::notFound():$this->render('show',['profile'=>$profile,'owner'=>false,'saved'=>false,'navigationUser'=>$this->identity?->current()]);
        } catch (Throwable) { return self::unavailable(); }
    }
    private function render(string $view,array $data,int $status=200): array
    {
        extract($data,EXTR_SKIP);
        $escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ob_start(); try { require dirname(__DIR__).'/Views/freelancer-profile/'.$view.'.php'; $body=ob_get_contents(); } finally { ob_end_clean(); }
        return ['status'=>$status,'type'=>'text/html; charset=UTF-8','body'=>$body];
    }
    private static function notFound(): array { return ['status'=>404,'type'=>'text/plain; charset=UTF-8','body'=>'Freelancer profile not found.']; }
    private static function unavailable(): array { return ['status'=>503,'type'=>'text/plain; charset=UTF-8','body'=>'Profiles are temporarily unavailable. Please try again later.']; }
}
