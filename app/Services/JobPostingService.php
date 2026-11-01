<?php
declare(strict_types=1);
namespace GigHub\Services;
use GigHub\Repositories\JobRepository;
use GigHub\Repositories\SkillRepository;
use PDO;
use Throwable;
class JobPostingService
{
    public function __construct(private readonly PDO $pdo,private readonly JobRepository $jobs,private readonly SkillRepository $skills,private readonly ?\Closure $clock=null) {}
    public function catalogue(): array { return $this->skills->all(); }
    public function listing(int $clientId,int $page): array { return $this->jobs->listOwned($clientId,$page); }
    public function owned(int $id,int $clientId): array
    {
        $job=$this->jobs->findOwned($id,$clientId);
        if($job===false)throw new JobPostingException(['form'=>'Job not found.'],404);
        $job['skills']=$this->jobs->skillsFor($id);return $job;
    }
    public function save(int $clientId,array $input,?int $id=null): int
    {
        $intent=$input['intent']??'save_draft';
        if(!in_array($intent,['save_draft','publish'],true))throw new JobPostingException(['form'=>'Choose Save Draft or Publish Job.']);
        return $this->write($clientId,$id,$input,$intent==='publish',false);
    }
    public function publish(int $clientId,int $id): int { return $this->write($clientId,$id,[],true,true); }
    private function write(int $clientId,?int $id,array $input,bool $publish,bool $stored): int
    {
        $owns=!$this->pdo->inTransaction();$started=false;
        try {
            if($owns)$this->pdo->beginTransaction();else $this->pdo->exec('SAVEPOINT gighub_job_posting');$started=true;
            // Existing job first, matching the documented job-workflow lock order.
            if($id!==null) {
                $job=$this->jobs->findOwned($id,$clientId,true);
                if($job===false)throw new JobPostingException(['form'=>'Job not found.'],404);
                if($job['status']!=='draft')throw new JobPostingException(['form'=>'Published jobs are read-only. Only drafts can be changed.'],409);
                if($stored) { $input=$job;$input['skills']=array_column($this->jobs->skillsFor($id),'id'); }
            }
            if(!$this->jobs->eligibleClient($clientId))throw new JobPostingException(['form'=>'Your account cannot post jobs right now.'],403);
            $validation=JobPostingValidator::validate($input,$publish,$this->clock!==null?($this->clock)():null);
            if($validation['errors']!==[])throw new JobPostingException($validation['errors']);
            $data=$validation['data'];$found=$this->skills->existingIds($data['skills']);
            if(count($found)!==count($data['skills']))throw new JobPostingException(['skills'=>'One or more skills are unavailable. Choose skills from the current catalogue.']);
            $status=$publish?'open':'draft';
            if($id===null)$id=$this->jobs->insert($clientId,$data,$status);
            else $this->jobs->updateDraft($id,$clientId,$data,$status);
            $this->jobs->replaceSkills($id,$data['skills']);
            if($owns)$this->pdo->commit();else $this->pdo->exec('RELEASE SAVEPOINT gighub_job_posting');
            return $id;
        } catch(Throwable $error) {
            if($started&&$this->pdo->inTransaction()) {
                if($owns)$this->pdo->rollBack();
                else {$this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_job_posting');$this->pdo->exec('RELEASE SAVEPOINT gighub_job_posting');}
            }
            if($error instanceof JobPostingException)throw $error;
            throw new \RuntimeException('Job Posting is temporarily unavailable.');
        }
    }
}
