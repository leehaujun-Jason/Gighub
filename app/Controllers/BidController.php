<?php
declare(strict_types=1);
namespace GigHub\Controllers;
use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Services\BidException;
use GigHub\Services\BidValidator;
use Throwable;

final class BidController
{
    public function __construct(private readonly AuthGuard $guard,private readonly Closure $serviceFactory) {}
    public function index(array $query=[]): array
    {
        $access=$this->guard->check('freelancer'); if (!isset($access['user'])) return $access;
        $page=self::page($query); if ($page===null) return self::text(400,'Invalid page.');
        try {
            $rows=($this->serviceFactory)()->listing((int)$access['user']['id'],$page);
            $hasNext=count($rows)>20; $bids=array_slice($rows,0,20);
            $notice=$_SESSION['bid_notice']??null; unset($_SESSION['bid_notice']);
            return $this->render('index',compact('bids','hasNext','page','notice'),$access['user']);
        } catch (Throwable) { return self::unavailable(); }
    }
    public function form(array $query,bool $editing=false): array
    {
        $access=$this->guard->check('freelancer'); if (!isset($access['user'])) return $access;
        $id=BidValidator::id($query[$editing?'id':'job_id']??null); if ($id===null) return self::text(400,'Invalid request.');
        try {
            $service=($this->serviceFactory)();
            $context=$editing?$service->edit((int)$access['user']['id'],$id):$service->context((int)$access['user']['id'],$id);
            if (!$editing && $context['bid']!==false) return self::redirect('/freelancer/bids/edit&id='.(int)$context['bid']['id']);
            $values=$context['bid']===false?['amount'=>'','cover_letter'=>'','delivery_days'=>'']:$context['bid'];
            $values['delivery_days']=(string)$values['delivery_days'];
            return $this->render('form',$context+['values'=>$values,'errors'=>[]],$access['user']);
        } catch (BidException $e) { return self::failure($e); } catch (Throwable) { return self::unavailable(); }
    }
    public function save(array $input,bool $editing=false): array { return $this->write($input,$editing,false); }
    public function withdraw(array $input): array { return $this->write($input,true,true); }
    private function write(array $input,bool $editing,bool $withdraw): array
    {
        $access=$this->guard->check('freelancer'); if (!isset($access['user'])) return $access;
        if (!Csrf::valid($input['csrf_token']??null,'bidding')) return self::text(403,'Your form expired. Reload the page and try again.');
        $id=$editing?BidValidator::id($input['id']??null):BidValidator::id($input['job_id']??null);
        if ($id===null) return self::text(400,'Invalid request.');
        try {
            $service=($this->serviceFactory)(); $userId=(int)$access['user']['id'];
            try {
                if ($withdraw) $service->withdraw($userId,$id); else $service->save($userId,$input,$editing?$id:null);
            } catch (BidException $e) {
                if ($e->status!==422 || $withdraw) throw $e;
                $context=$editing?$service->edit($userId,$id):$service->context($userId,$id);
                return $this->render('form',$context+['values'=>BidValidator::validate($input)['data'],'errors'=>$e->errors],$access['user'],422);
            }
            Csrf::rotate('bidding');
            $_SESSION['bid_notice']=$withdraw?'Your bid has been withdrawn.':($editing?'Your bid has been saved.':'Your bid has been submitted.');
            return self::redirect('/freelancer/bids');
        } catch (BidException $e) { return self::failure($e); } catch (Throwable) { return self::unavailable(); }
    }
    public function client(array $query): array
    {
        $access=$this->guard->check('client'); if (!isset($access['user'])) return $access;
        $id=BidValidator::id($query['id']??null); $page=self::page($query);
        if ($id===null || $page===null) return self::text(400,'Invalid request.');
        try {
            $data=($this->serviceFactory)()->clientListing((int)$access['user']['id'],$id,$page);
            $data['hasNext']=count($data['bids'])>20; $data['bids']=array_slice($data['bids'],0,20); $data['page']=$page;
            $data['awardNotice']=($_SESSION['award_notice']??false)===true; unset($_SESSION['award_notice']);
            return $this->render('client',$data,$access['user']);
        } catch (BidException $e) { return self::failure($e); } catch (Throwable) { return self::unavailable(); }
    }
    private function render(string $view,array $data,array $navigationUser,int $status=200): array
    {
        extract($data,EXTR_SKIP); $token=Csrf::token('bidding');
        $escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ob_start(); try { require dirname(__DIR__).'/Views/bids/'.$view.'.php'; $body=ob_get_contents(); } finally { ob_end_clean(); }
        return ['status'=>$status,'type'=>'text/html; charset=UTF-8','body'=>$body];
    }
    private static function page(array $query): ?int { $p=$query['page']??'1'; return is_string($p)&&preg_match('/\A[1-9][0-9]{0,4}\z/D',$p)?(int)$p:null; }
    private static function text(int $status,string $body): array { return ['status'=>$status,'type'=>'text/plain; charset=UTF-8','body'=>$body]; }
    private static function failure(BidException $e): array { return self::text($e->status,$e->errors['form']??'Bid request could not be completed.'); }
    private static function unavailable(): array { return self::text(503,'Bidding is temporarily unavailable. Please try again later.'); }
    private static function redirect(string $route): array { return self::text(303,'')+['location'=>'?route='.$route]; }
}
