<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;
class SkillRepository
{
    public function __construct(protected readonly PDO $pdo) {}
    public function all(): array { return $this->pdo->query('SELECT id,name FROM skills ORDER BY name,id')->fetchAll(); }
    public function existingIds(array $ids): array
    {
        if($ids===[])return [];
        $q=$this->pdo->prepare('SELECT id FROM skills WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
        $q->execute($ids);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    }
}
