<?php
declare(strict_types=1);
namespace GigHub\Controllers;
use Closure;
use GigHub\Services\JobBrowseValidator;
use Throwable;

final class PublicJobController
{
    /** Factory lazily supplies [PublicJobRepository, SkillRepository], without SMTP. */
    public function __construct(private readonly Closure $repositoryFactory,private readonly ?Closure $clock=null) {}
    public function index(array $input=[]): array
    {
        $result=JobBrowseValidator::validate($input);$filters=$result['data'];$errors=$result['errors'];
        try {
            [$repository,$catalogue]=($this->repositoryFactory)();$skills=$catalogue->all();
            if(!isset($errors['skills'])&&count($catalogue->existingIds($filters['skills']))!==count($filters['skills']))$errors['skills']='Choose skills from the current catalogue.';
            $rows=$errors===[]?$repository->browse($filters):[];
            $hasNext=count($rows)>20&&$filters['page']<100000;$jobs=array_slice($rows,0,20);
            return $this->render('index',compact('filters','errors','skills','jobs','hasNext'),$errors===[]?200:422);
        }catch(Throwable){return self::unavailable();}
    }
    public function show(array $input): array
    {
        $id=$input['id']??null;
        if(!is_string($id)||!preg_match('/\A[1-9][0-9]{0,9}\z/D',$id)||(int)$id>4294967295)return self::notFound();
        try {
            [$repository]=($this->repositoryFactory)();$job=$repository->find((int)$id);
            return $job===false?self::notFound():$this->render('show',compact('job'));
        }catch(Throwable){return self::unavailable();}
    }
    private function render(string $view,array $data,int $status=200): array
    {
        extract($data,EXTR_SKIP);$escape=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $today=($this->clock!==null?($this->clock)():new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Asia/Kuala_Lumpur'))->format('Y-m-d');
        $pageUrl=static function(int $page)use($data):string {
            $f=$data['filters'];$f['page']=$page;
            return '?'.http_build_query(['route'=>'/jobs']+$f,'','&',PHP_QUERY_RFC3986);
        };
        ob_start();try{require dirname(__DIR__).'/Views/jobs/'.$view.'.php';$body=ob_get_contents();}finally{ob_end_clean();}
        return ['status'=>$status,'type'=>'text/html; charset=UTF-8','body'=>$body];
    }
    private static function notFound(): array{return ['status'=>404,'type'=>'text/plain; charset=UTF-8','body'=>'Job not found.'];}
    private static function unavailable(): array{return ['status'=>503,'type'=>'text/plain; charset=UTF-8','body'=>'Job browsing is temporarily unavailable. Please try again later.'];}
}
