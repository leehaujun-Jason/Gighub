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
        if ($profile!==false) {
            $profile['skills']=$this->profiles->skills($userId);
            $profile['reputation']=method_exists($this->profiles, 'reputation') ? $this->profiles->reputation($userId) : ['count' => 0, 'average' => null];
            $profile['reviews']=method_exists($this->profiles, 'reviews') ? $this->profiles->reviews($userId) : [];
        }
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

    public function saveSkills(int $userId, array $input): void
    {
        $ids = $input['skills'] ?? [];
        if (!is_array($ids) || count($ids) > 1000) {
            throw new FreelancerProfileException(['skills' => 'Choose skills from the catalogue.']);
        }
        $cleanIds = [];
        foreach ($ids as $id) {
            if ((!is_string($id) && !is_int($id)) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', (string)$id) || (int)$id > 4294967295) {
                throw new FreelancerProfileException(['skills' => 'Choose skills from the catalogue.']);
            }
            $cleanIds[] = (int)$id;
        }
        $cleanIds = array_values(array_unique($cleanIds));
        sort($cleanIds, SORT_NUMERIC);

        $owns = !$this->pdo->inTransaction();
        $started = false;
        try {
            if ($owns) $this->pdo->beginTransaction(); else $this->pdo->exec('SAVEPOINT gighub_skills');
            $started = true;
            if (!$this->profiles->lockEligible($userId)) {
                throw new FreelancerProfileException(['form' => 'Your profile is unavailable.'], 403);
            }
            if (count($this->skills->existingIds($cleanIds)) !== count($cleanIds)) {
                throw new FreelancerProfileException(['skills' => 'Choose skills from the current catalogue.']);
            }
            $this->profiles->updateSkills($userId, $cleanIds);
            if ($owns) $this->pdo->commit(); else $this->pdo->exec('RELEASE SAVEPOINT gighub_skills');
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                if ($owns) $this->pdo->rollBack();
                else { $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_skills'); $this->pdo->exec('RELEASE SAVEPOINT gighub_skills'); }
            }
            if ($error instanceof FreelancerProfileException) throw $error;
            throw new \RuntimeException('Skills are temporarily unavailable.');
        }
    }
}
