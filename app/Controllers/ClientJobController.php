<?php
declare(strict_types=1);
namespace GigHub\Controllers;
use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Services\JobPostingException;
use GigHub\Services\JobPostingValidator;
use Throwable;
final class ClientJobController
{
    private ?array $navigationUser = null;
    public function __construct(private readonly AuthGuard $guard,private readonly Closure $serviceFactory) {}
    public function index(array $query=[]): array
    {
        $access=$this->guard->check('client');if(!isset($access['user']))return $access;
        $this->navigationUser=$access['user'];
        $raw=$query['page']??'1';
        if(!is_string($raw)||!preg_match('/\A[1-9][0-9]{0,4}\z/D',$raw))return self::text(400,'Invalid page.');
        try {
            $page=(int)$raw;$rows=($this->serviceFactory)()->listing($access['user']['id'],$page);
            $hasNext=count($rows)>20;$jobs=array_slice($rows,0,20);
            return $this->render('index',compact('jobs','page','hasNext'));
        }catch(Throwable){return self::unavailable();}
    }
    public function create(): array
    {
        $access=$this->guard->check('client');if(!isset($access['user']))return $access;
        $this->navigationUser=$access['user'];
        try{return $this->form(($this->serviceFactory)(),null,[],[]);}catch(Throwable){return self::unavailable();}
    }
    public function show(array $query): array { return $this->read($query,false); }
    public function edit(array $query): array { return $this->read($query,true); }
    private function read(array $query,bool $editing): array
    {
        $access=$this->guard->check('client');if(!isset($access['user']))return $access;
        $this->navigationUser=$access['user'];
        $id=self::id($query['id']??null);if($id===null)return self::text(400,'Invalid job ID.');
        try {
            $service=($this->serviceFactory)();$job=$service->owned($id,$access['user']['id']);
            if($editing) {
                if($job['status']!=='draft')return self::text(409,'Published jobs are read-only.');
                $old=$job;$old['skills']=array_column($job['skills'],'id');return $this->form($service,$id,$old,[]);
            }
            $rawNotice = $_SESSION['job_posting_notice'] ?? null;
            $notice = in_array($rawNotice, ['Your job has been saved.', 'Your job has been published.'], true) ? $rawNotice : null;
            unset($_SESSION['job_posting_notice']);
            return $this->render('show',compact('job','notice'));
        }catch(JobPostingException $e){return self::text($e->status,$e->status===404?'Job not found.':'Job request unavailable.');}
        catch(Throwable){return self::unavailable();}
    }
    public function store(array $input): array { return $this->write($input,false,false); }
    public function update(array $input): array { return $this->write($input,true,false); }
    public function publish(array $input): array { return $this->write($input,true,true); }
    private function write(array $input,bool $existing,bool $publish): array
    {
        $access=$this->guard->check('client');if(!isset($access['user']))return $access;
        $this->navigationUser=$access['user'];
        $id=$existing?self::id($input['id']??null):null;
        if($existing&&$id===null)return self::text(400,'Invalid job ID.');
        try {
            $service=($this->serviceFactory)();
            if(!Csrf::valid($input['csrf_token']??null,'job_posting')) {
                // No ownership information is disclosed before a valid owner-scoped read.
                if($id!==null)$service->owned($id,$access['user']['id']);
                return $this->form($service,$id,JobPostingValidator::oldValues($input),['form'=>'Your form session could not be verified. Review your details and try again.'],403);
            }
            try {
                $saved=$publish?$service->publish($access['user']['id'],$id):$service->save($access['user']['id'],$input,$id);
            }catch(JobPostingException $e) {
                if($e->status!==422)throw $e;
                $old=JobPostingValidator::oldValues($input);
                if($publish) { $job=$service->owned($id,$access['user']['id']);$old=$job;$old['skills']=array_column($job['skills'],'id'); }
                return $this->form($service,$id,$old,$e->errors,422);
            }
            Csrf::rotate('job_posting');
            $published=$publish||($input['intent']??'save_draft')==='publish';
            $_SESSION['job_posting_notice']=$published?'Your job has been published.':'Your job has been saved.';
            return ['status'=>303,'type'=>'text/plain; charset=UTF-8','body'=>'','location'=>'?route=/client/jobs/show&id='.$saved];
        }catch(JobPostingException $e){return self::text($e->status,$e->status===404?'Job not found.':($e->status===409?'Published jobs are read-only.':'Your account cannot post jobs right now.'));}
        catch(Throwable){return self::unavailable();}
    }
    private function form(object $service,?int $id,array $old,array $errors,int $status=200): array
    {
        $skills=$service->catalogue();return $this->render('form',compact('id','old','errors','skills'),$status);
    }
    private function render(string $view,array $data,int $status=200): array
    {
        extract($data,EXTR_SKIP);$navigationUser=$this->navigationUser;$token=Csrf::token('job_posting');
        $escape=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        ob_start();try{require dirname(__DIR__).'/Views/client/jobs/'.$view.'.php';$body=ob_get_contents();}finally{ob_end_clean();}
        return ['status'=>$status,'type'=>'text/html; charset=UTF-8','body'=>$body];
    }
    private static function id(mixed $value): ?int
    {
        return is_string($value)&&preg_match('/\A[1-9][0-9]{0,9}\z/D',$value)&&(int)$value<=4294967295?(int)$value:null;
    }
    private static function text(int $status,string $body): array{return ['status'=>$status,'type'=>'text/plain; charset=UTF-8','body'=>$body];}
    private static function unavailable(): array{return self::text(503,'Job Posting is temporarily unavailable. Please try again later.');}
}
