<?php
declare(strict_types=1);
namespace GigHub\Tests\Http;
use PDO;
use RuntimeException;

/** Test-only deterministic fixture plan; inclusion never creates fixtures or connects. */
final class BrowseFixtures
{
    public static function plan(string $run): array
    {
        $prefix=JobFixtures::prefix($run);
        $users=[
            'client'=>['role'=>'client','status'=>'active','verified'=>true],
            'suspended'=>['role'=>'client','status'=>'suspended','verified'=>true],
            'pending'=>['role'=>'client','status'=>'pending_verification','verified'=>false],
            'freelancer'=>['role'=>'freelancer','status'=>'active','verified'=>true],
        ];
        $jobs=[];
        for($i=0;$i<24;$i++) {
            $jobs[]=['owner'=>'client','status'=>'open','title'=>$prefix.($i===0?'Literal %_! <unsafe>':'Open project '.str_pad((string)$i,2,'0',STR_PAD_LEFT)),
                'description'=>'Synthetic browsing description. '.str_repeat('Safe plain text. ',4).($i===0?' <script>fixture</script> café 界':''),
                'budget'=>$i===0?'0.01':($i===23?'99999999.99':number_format(10*intdiv($i+1,2),2,'.','')),
                'deadline'=>$i%2===0?'2029-12-31':'2030-02-01','created_at'=>'2029-12-'.str_pad((string)(1+intdiv($i,3)),2,'0',STR_PAD_LEFT).' 12:00:00',
                'skills'=>$i%3===0?[0,1]:[$i%3],'closed_at'=>null];
        }
        foreach([['client','draft'],['client','cancelled'],['suspended','open'],['pending','open']] as [$owner,$status]) {
            $jobs[]=['owner'=>$owner,'status'=>$status,'title'=>$prefix.$owner.' '.$status.' hidden project','description'=>str_repeat('Synthetic hidden description. ',3),
                'budget'=>'50.00','deadline'=>'2030-02-01','created_at'=>'2029-12-09 12:00:00','skills'=>[0],'closed_at'=>$status==='cancelled'?'2029-12-10 12:00:00':null];
        }
        return ['users'=>$users,'skills'=>[$prefix.'PHP',$prefix.'HTML',$prefix.'CSS'],'jobs'=>$jobs];
    }

    /** One caller-supplied guarded PDO for all writes; caller owns transaction/rollback. */
    public static function populate(PDO $p,string $run,callable $track): array
    {
        if(!$p->inTransaction())throw new RuntimeException('Fixture transaction required.');
        TestDatabase::identity($p->query('SELECT DATABASE() db,@@port port,@@datadir datadir,VERSION() version,CURRENT_USER() account')->fetch());
        JobFixtures::assertEmpty($p);
        $plan=self::plan($run);$prefix=JobFixtures::prefix($run);$m=['run'=>$run,'users'=>[],'skills'=>[],'jobs'=>[]];$owners=[];
        $hash=password_hash('Synthetic browsing HTTP passphrase',PASSWORD_ARGON2ID);
        $q=$p->prepare('INSERT INTO users(email,password_hash,display_name,role,status,email_verified_at) VALUES (?,?,?,?,?,?)');
        foreach($plan['users'] as $label=>$user) {
            $q->execute([$prefix.$label.'@example.invalid',$hash,'Synthetic browsing '.$label,$user['role'],$user['status'],$user['verified']?'2029-01-01 00:00:00':null]);
            $id=(int)$p->lastInsertId();$owners[$label]=$id;$m['users'][]=$id;$track($m);
            $table=$user['role']==='client'?'client_profiles':'freelancer_profiles';
            $s=$p->prepare('INSERT INTO '.$table.'(user_id) VALUES (?)');$s->execute([$id]);
        }
        $q=$p->prepare('INSERT INTO skills(name) VALUES (?)');
        foreach($plan['skills'] as $name){$q->execute([$name]);$m['skills'][]=(int)$p->lastInsertId();$track($m);}
        $q=$p->prepare('INSERT INTO jobs(client_id,title,description,budget,deadline,status,created_at,closed_at) VALUES (?,?,?,?,?,?,?,?)');
        $link=$p->prepare('INSERT INTO job_skills(job_id,skill_id) VALUES (?,?)');
        foreach($plan['jobs'] as $job) {
            $q->execute([$owners[$job['owner']],$job['title'],$job['description'],$job['budget'],$job['deadline'],$job['status'],$job['created_at'],$job['closed_at']]);
            $id=(int)$p->lastInsertId();$m['jobs'][]=$id;$track($m);
            foreach($job['skills'] as $index)$link->execute([$id,$m['skills'][$index]]);
        }
        return $m;
    }

    /** Also exercised offline with hostile row sets; rejects cross-run/untracked ownership. */
    public static function validateRows(array $m,string $run,array $rows): void
    {
        JobFixtures::validateManifest($m,$run);$prefix=JobFixtures::prefix($run);
        foreach($rows['users'] as $r)if(!in_array((int)$r['id'],$m['users'],true)||!str_starts_with($r['email'],$prefix)||!str_ends_with($r['email'],'@example.invalid'))throw new RuntimeException('Untracked browsing user.');
        foreach($rows['skills'] as $r)if(!in_array((int)$r['id'],$m['skills'],true)||!str_starts_with($r['name'],$prefix))throw new RuntimeException('Untracked browsing skill.');
        foreach($rows['jobs'] as $r)if(!in_array((int)$r['id'],$m['jobs'],true)||!in_array((int)$r['client_id'],$m['users'],true)||!str_starts_with($r['title'],$prefix)||$r['awarded_bid_id']!==null)throw new RuntimeException('Untracked browsing job.');
        foreach($rows['job_skills'] as $r)if(!in_array((int)$r['job_id'],$m['jobs'],true)||!in_array((int)$r['skill_id'],$m['skills'],true))throw new RuntimeException('Untracked browsing link.');
    }
    public static function assertInventory(PDO $p,string $dir,string $run): array
    {
        $m=JobFixtures::assertInventory($p,$dir,$run);
        if((int)$p->query('SELECT COUNT(*) FROM email_verification_tokens')->fetchColumn()!==0)throw new RuntimeException('Unexpected browsing tokens.');
        $rows=[];foreach(['users'=>'id,email','skills'=>'id,name','jobs'=>'id,client_id,title,awarded_bid_id','job_skills'=>'job_id,skill_id'] as $table=>$columns)$rows[$table]=$p->query('SELECT '.$columns.' FROM '.$table)->fetchAll();
        self::validateRows($m,$run,$rows);return $m;
    }
    public static function cleanup(PDO $p,string $dir,string $run): void
    {
        self::assertInventory($p,$dir,$run);
        JobFixtures::cleanup($p,$dir,$run);
        JobFixtures::assertEmpty($p);
    }
}
