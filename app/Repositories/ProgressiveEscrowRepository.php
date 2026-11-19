<?php
declare(strict_types=1);
namespace GigHub\Repositories;
use PDO;
final class ProgressiveEscrowRepository
{
    public function __construct(public readonly PDO $pdo) {}
    public function one(string $sql, array $args): array|false { $q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetch(PDO::FETCH_ASSOC); }
    public function rows(string $sql, array $args): array { $q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC); }
    public function execute(string $sql, array $args): int { $q=$this->pdo->prepare($sql);$q->execute($args);return $q->rowCount(); }
    public function record(int $escrow, ?int $milestone, int $recipient, int $actor, string $phase, string $amount, string $key, string $type, string $source): int
    {
        $this->execute('INSERT INTO escrow_disbursements (escrow_id,milestone_id,recipient_user_id,actor_id,phase,amount,operation_key) VALUES (?,?,?,?,?,?,?)',[$escrow,$milestone,$recipient,$actor,$phase,$amount,$key]);
        $id=(int)$this->pdo->lastInsertId();
        $this->execute('INSERT INTO wallet_entries (wallet_user_id,escrow_id,entry_type,amount_delta,source,actor_id,operation_key,disbursement_id) VALUES (?,?,?,?,?,?,?,?)',[$recipient,$escrow,$type,$amount,$source,$actor,$key,$id]);
        return $id;
    }
}
