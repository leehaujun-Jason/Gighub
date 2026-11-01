<?php
declare(strict_types=1);
namespace GigHub\Tests\Http;
use PDO;
use InvalidArgumentException;
use RuntimeException;
/** Test-only. No connections or mutations on include. Never loads application configuration. */
final class JobFixtures
{
    public static function tables(): array { return ['users','client_profiles','freelancer_profiles','email_verification_tokens','skills','jobs','job_skills']; }
    public static function prefix(string $run): string
    {
        if(!preg_match('/\Aaccept-[0-9a-f]{16}\z/D',$run))throw new InvalidArgumentException('Invalid test run.');
        return 'j'.substr($run,7).'-';
    }
    public static function validateState(array $s,string $run): void
    {
        self::prefix($run);
        if(($s['run']??null)!==$run||($s['port']??null)!==8765||($s['job_posting_enabled']??null)!==true)throw new InvalidArgumentException('Job fixtures not enabled.');
    }
    public static function validateManifest(array $m,string $run): void
    {
        self::prefix($run);$keys=array_keys($m);sort($keys);$expected=['jobs','run','skills','users'];sort($expected);
        if($keys!==$expected||($m['run']??null)!==$run)throw new InvalidArgumentException('Invalid fixture inventory.');
        foreach(['users','skills','jobs'] as $key) {
            if(!is_array($m[$key])||!array_is_list($m[$key])||count($m[$key])>500||count(array_unique($m[$key],SORT_REGULAR))!==count($m[$key]))throw new InvalidArgumentException('Invalid fixture IDs.');
            foreach($m[$key] as $id)if(!is_int($id)||$id<1||$id>4294967295)throw new InvalidArgumentException('Invalid fixture ID.');
        }
    }
    public static function load(string $dir,string $run): array
    {
        if($dir!==runtime($run))throw new RuntimeException('Invalid fixture directory.');
        $m=json_decode(file_get_contents($dir.'/job-fixtures.json'),true,512,JSON_THROW_ON_ERROR);self::validateManifest($m,$run);return $m;
    }
    public static function save(string $dir,string $run,array $m): void
    {
        if($dir!==runtime($run))throw new RuntimeException('Invalid fixture directory.');self::validateManifest($m,$run);
        if(file_put_contents($dir.'/job-fixtures.json',json_encode($m,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('Fixture inventory unavailable.');
    }
    public static function assertEmpty(PDO $p): void
    {
        $tables=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if(count($tables)!==20)throw new RuntimeException('Unexpected test schema.');
        foreach($tables as $t) {
            if(!preg_match('/\A[a-z_]+\z/D',$t)||(int)$p->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn()!==0)throw new RuntimeException('Test schema is not empty.');
        }
    }
    public static function assertInventory(PDO $p,string $dir,string $run): array
    {
        $m=self::load($dir,$run);$prefix=self::prefix($run);
        $tables=$p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);if(count($tables)!==20)throw new RuntimeException('Unexpected test schema.');
        foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if(!in_array($t,self::tables(),true)&&(int)$p->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn()!==0)throw new RuntimeException('Unexpected dependency records.');
        }
        $users=$p->query('SELECT id,email FROM users')->fetchAll();
        foreach($users as $row)if(!in_array((int)$row['id'],$m['users'],true)||!str_starts_with($row['email'],$prefix)||!str_ends_with($row['email'],'@example.invalid'))throw new RuntimeException('Untracked user.');
        foreach($p->query('SELECT id,name FROM skills')->fetchAll() as $row)if(!in_array((int)$row['id'],$m['skills'],true)||!str_starts_with($row['name'],$prefix))throw new RuntimeException('Untracked skill.');
        foreach($p->query('SELECT id,client_id,title,awarded_bid_id FROM jobs')->fetchAll() as $row)if(!in_array((int)$row['id'],$m['jobs'],true)||!in_array((int)$row['client_id'],$m['users'],true)||!str_starts_with($row['title'],$prefix)||$row['awarded_bid_id']!==null)throw new RuntimeException('Untracked job.');
        foreach(['client_profiles','freelancer_profiles','email_verification_tokens'] as $t)foreach($p->query('SELECT user_id FROM '.$t)->fetchAll(PDO::FETCH_COLUMN) as $id)if(!in_array((int)$id,$m['users'],true))throw new RuntimeException('Untracked child.');
        foreach($p->query('SELECT job_id,skill_id FROM job_skills')->fetchAll() as $row)if(!in_array((int)$row['job_id'],$m['jobs'],true)||!in_array((int)$row['skill_id'],$m['skills'],true))throw new RuntimeException('Untracked job skill.');
        return $m;
    }
    public static function cleanup(PDO $p,string $dir,string $run): void
    {
        // Caller has stopped HTTP activity and disabled mutations; failures roll back all cleanup.
        $p->beginTransaction();
        try {
            $m=self::assertInventory($p,$dir,$run);
            foreach(['job_skills'=>['job_id','jobs'],'jobs'=>['id','jobs'],'email_verification_tokens'=>['user_id','users'],'freelancer_profiles'=>['user_id','users'],'client_profiles'=>['user_id','users'],'users'=>['id','users'],'skills'=>['id','skills']] as $t=>[$column,$key]) {
                foreach($m[$key] as $id) {
                    if($t==='email_verification_tokens') {
                        $count=$p->prepare('SELECT COUNT(*) FROM email_verification_tokens WHERE user_id=?');$count->execute([$id]);
                        if((int)$count->fetchColumn()===0)continue; // Pure job runs need no token DELETE grant.
                    }
                    $sql='DELETE FROM '.$t.' WHERE '.$column.'=?';$values=[$id];
                    if($t==='users'){$sql.=' AND email LIKE ?';$values[] = self::prefix($run).'%@example.invalid';}
                    if($t==='skills'){$sql.=' AND name LIKE ?';$values[] = self::prefix($run).'%';}
                    if($t==='jobs'){$sql.=' AND title LIKE ?';$values[] = self::prefix($run).'%';}
                    $q=$p->prepare($sql);$q->execute($values);
                }
            }
            self::assertEmpty($p);$p->commit();
        }catch(\Throwable $e){if($p->inTransaction())$p->rollBack();throw new RuntimeException('Fixture cleanup failed; preserve evidence.');}
    }
}

class TrackedJobRepository extends \GigHub\Repositories\JobRepository
{
    public function __construct(PDO $pdo,private readonly string $directory,private readonly string $run){parent::__construct($pdo);}
    private function owner(int $owner): void
    {
        $m=JobFixtures::load($this->directory,$this->run);
        if(!in_array($owner,$m['users'],true))throw new RuntimeException('Untracked owner.');
    }
    private function title(array $data): void
    {
        if(!str_starts_with($data['title'],JobFixtures::prefix($this->run)))throw new RuntimeException('Only marked jobs allowed.');
    }
    public function insert(int $clientId,array $data,string $status): int
    {
        $this->owner($clientId);$this->title($data);$id=parent::insert($clientId,$data,$status);
        $m=JobFixtures::load($this->directory,$this->run);$m['jobs'][]=$id;JobFixtures::save($this->directory,$this->run,$m);return $id;
    }
    public function findOwned(int $id,int $clientId,bool $lock=false): array|false
    {
        $this->owner($clientId);$m=JobFixtures::load($this->directory,$this->run);
        if(!in_array($id,$m['jobs'],true))return false;
        return parent::findOwned($id,$clientId,$lock);
    }
    public function updateDraft(int $id,int $clientId,array $data,string $status): void
    {
        $this->owner($clientId);$this->title($data);parent::updateDraft($id,$clientId,$data,$status);
    }
}
