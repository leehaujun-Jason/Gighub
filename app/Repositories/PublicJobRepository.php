<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;

/** Public projections only; never relax owner-only JobRepository queries. */
class PublicJobRepository
{
    private const SELECT='SELECT j.id,j.title,j.description,j.budget,j.deadline,j.created_at FROM jobs j JOIN client_profiles cp ON cp.user_id=j.client_id JOIN users u ON u.id=cp.user_id';
    private const VISIBLE="j.status='open' AND u.role='client' AND u.status='active' AND u.email_verified_at IS NOT NULL";
    public function __construct(private readonly PDO $pdo) {}

    public function browse(array $filters): array
    {
        $sql=self::SELECT.' WHERE '.self::VISIBLE;$values=[];
        if($filters['q']!=='') {
            $literal='%'.strtr($filters['q'],['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $sql.=" AND (j.title LIKE ? ESCAPE '!' OR j.description LIKE ? ESCAPE '!')";
            $values[]=$literal;$values[]=$literal;
        }
        foreach(['min_budget'=>'>=','max_budget'=>'<='] as $key=>$operator) {
            if($filters[$key]!==''){$sql.=' AND j.budget '.$operator.' ?';$values[]=$filters[$key];}
        }
        if($filters['skills']!==[]) {
            $sql.=' AND EXISTS (SELECT 1 FROM job_skills js WHERE js.job_id=j.id AND js.skill_id IN ('.implode(',',array_fill(0,count($filters['skills']),'?')).'))';
            array_push($values,...$filters['skills']);
        }
        $order=match($filters['sort']) {'budget_low'=>'j.budget ASC,j.id ASC','budget_high'=>'j.budget DESC,j.id DESC',default=>'j.created_at DESC,j.id DESC'};
        $q=$this->pdo->prepare($sql.' ORDER BY '.$order.' LIMIT 21 OFFSET ?');
        foreach($values as $i=>$value)$q->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $q->bindValue(count($values)+1,($filters['page']-1)*20,PDO::PARAM_INT);$q->execute();
        return $this->withSkills($q->fetchAll());
    }

    public function find(int $id): array|false
    {
        $q=$this->pdo->prepare(self::SELECT.' WHERE '.self::VISIBLE.' AND j.id=?');
        $q->bindValue(1,$id,PDO::PARAM_INT);$q->execute();$row=$q->fetch();
        return $row===false?false:$this->withSkills([$row])[0];
    }

    private function withSkills(array $rows): array
    {
        if($rows===[])return [];
        $ids=array_column($rows,'id');$q=$this->pdo->prepare('SELECT js.job_id,s.id,s.name FROM job_skills js JOIN skills s ON s.id=js.skill_id WHERE js.job_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY s.name,s.id');
        foreach($ids as $i=>$id)$q->bindValue($i+1,(int)$id,PDO::PARAM_INT);$q->execute();$map=[];
        foreach($q->fetchAll() as $skill)$map[(int)$skill['job_id']][]=['id'=>(int)$skill['id'],'name'=>$skill['name']];
        foreach($rows as &$row)$row['skills']=$map[(int)$row['id']]??[];unset($row);
        return $rows;
    }
}
