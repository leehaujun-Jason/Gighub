<?php
declare(strict_types=1);
namespace GigHub\Services;
use GigHub\Repositories\FreelancerProfileRepository;
use GigHub\Repositories\SkillRepository;
use PDO;
use Throwable;

class FreelancerProfileService
{
    public function __construct(private readonly PDO $pdo,private readonly FreelancerProfileRepository $profiles,private readonly SkillRepository $skills) {}
    public function catalogue(): array { return $this->skills->all(); }
    public function profile(int $userId): array|false
    {
        $profile=$this->profiles->find($userId);
        if ($profile!==false) $profile['skills']=$this->profiles->skills($userId);
        return $profile;
    }
    public function save(int $userId,array $input): void
    {
        $result=FreelancerProfileValidator::validate($input);
        if ($result['errors']!==[]) throw new FreelancerProfileException($result['errors']);
        $owns=!$this->pdo->inTransaction(); $started=false;
        try {
            if ($owns) $this->pdo->beginTransaction(); else $this->pdo->exec('SAVEPOINT gighub_profile');
            $started=true;
            if (!$this->profiles->lockEligible($userId)) throw new FreelancerProfileException(['form'=>'Your profile is unavailable.'],403);
            if (count($this->skills->existingIds($result['data']['skills']))!==count($result['data']['skills'])) {
                throw new FreelancerProfileException(['skills'=>'Choose skills from the current catalogue.']);
            }
            $this->profiles->update($userId,$result['data']);
            if ($owns) $this->pdo->commit(); else $this->pdo->exec('RELEASE SAVEPOINT gighub_profile');
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                if ($owns) $this->pdo->rollBack();
                else { $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_profile'); $this->pdo->exec('RELEASE SAVEPOINT gighub_profile'); }
            }
            if ($error instanceof FreelancerProfileException) throw $error;
            throw new \RuntimeException('Profile is temporarily unavailable.');
        }
    }
}
