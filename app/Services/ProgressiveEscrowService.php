<?php
declare(strict_types=1);
namespace GigHub\Services;
use GigHub\Repositories\ProgressiveEscrowRepository;
use PDO;
final class ProgressiveEscrowService
{
    private readonly ProgressiveEscrowRepository $repo;
    public function __construct(private readonly PDO $pdo) { $this->repo=new ProgressiveEscrowRepository($pdo); }
    private function require(bool $ok, string $message): void { if(!$ok) throw new \DomainException($message); }
    public function lockParticipants(array $job): void
    {
        if(!PaymentMoney::progressive($job)) return;
        $this->require($this->pdo->inTransaction(),'Contract locks require a transaction.');
        $this->repo->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$job['client_id']]);
        $bid=$this->repo->one('SELECT * FROM bids WHERE id=? AND job_id=? FOR UPDATE',[$job['awarded_bid_id'],$job['id']]);
        $this->require($bid!==false,'Awarded bid missing.');
        $this->repo->one('SELECT id FROM users WHERE id=? FOR UPDATE',[$bid['freelancer_id']]);
    }
    public function freeze(array $job, int $escrowId): void
    {
        if(!PaymentMoney::progressive($job))return;
        $this->require($this->pdo->inTransaction(),'Funding requires a transaction.');
        $r=$this->repo;
        $milestones=$r->rows('SELECT * FROM milestones WHERE job_id=? ORDER BY position,id FOR UPDATE',[$job['id']]);
        $this->require(count($milestones)>=1&&count($milestones)<=2,'Progressive contracts require one or two milestones.');
        $e=$r->one('SELECT * FROM escrows WHERE id=? FOR UPDATE',[$escrowId]);
        $this->require($e!==false&&PaymentMoney::sen($e['amount'])>=1000,'Progressive contracts require at least RM10.00.');
        foreach($milestones as $i=>$m)$r->execute('UPDATE milestones SET payout_basis_points=? WHERE id=?',[$i===0?3000:2000,$m['id']]);
        $r->execute("UPDATE escrows SET financial_policy='progressive_v1',milestone_count_at_funding=? WHERE id=? AND financial_policy='legacy_full_v1'",[count($milestones),$escrowId]);
    }
    // Caller owns the transaction. Never commit here: approval/settlement and allocation must be atomic.
    public function allocate(int $jobId,int $actor,string $phase,?int $milestone=null,bool $refund=false): string
    {
        $this->require($this->pdo->inTransaction(),'Allocation requires a transaction.');
        $this->require(in_array($phase,['milestone','final','cancellation','dispute'],true),'Invalid allocation phase.');
        $r=$this->repo;
        $job=$r->one('SELECT * FROM jobs WHERE id=? FOR UPDATE',[$jobId]);
        $this->require($job!==false&&PaymentMoney::progressive($job),'Invalid progressive contract.');
        // Existing workflows hold Job first. Client before Freelancer matches funding and legacy completion.
        $client=$r->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$job['client_id']]);
        $bid=$r->one('SELECT * FROM bids WHERE id=? AND job_id=? FOR UPDATE',[$job['awarded_bid_id'],$jobId]);
        $this->require($bid!==false&&$bid['status']==='accepted','Invalid awarded contract.');
        $freelancer=$r->one('SELECT * FROM users WHERE id=? FOR UPDATE',[$bid['freelancer_id']]);
        $this->require($client!==false&&$freelancer!==false,'Contract participant missing.');
        $this->require($client['role']==='client'&&$freelancer['role']==='freelancer','Invalid contract participant roles.');
        $escrow=$r->one('SELECT * FROM escrows WHERE job_id=? FOR UPDATE',[$jobId]);
        $this->require($escrow!==false&&PaymentMoney::progressive($escrow)&&PaymentMoney::sen($escrow['amount'])===PaymentMoney::sen($bid['amount']),'Escrow principal mismatch.');
        $key='progressive:'.$escrow['id'].':'.$phase.($milestone!==null?':'.$milestone:'');
        $prior=$r->one('SELECT * FROM escrow_disbursements WHERE operation_key=?',[$key]);
        if($prior!==false){
            $this->require((int)$prior['actor_id']===$actor,'Allocation ownership mismatch.');
            return $prior['amount'];
        }
        $this->require($escrow['status']==='locked'&&$escrow['settled_at']===null,'Escrow already settled.');
        if(in_array($phase,['milestone','final'],true)){
            $this->require(!$refund&&(int)$job['client_id']===$actor,'Only the owning Client may approve payment.');
            foreach([$client,$freelancer] as $u)$this->require($u['status']==='active'&&$u['email_verified_at']!==null,'Participant is not eligible for payment.');
            $this->require($r->one('SELECT id FROM disputes WHERE job_id=?',[$jobId])===false,'Contract is under dispute.');
            $this->require($job['status']===($phase==='milestone'?'in_progress':'under_review'),'Contract is not awaiting this approval.');
        }elseif($phase==='cancellation'){
            $c=$r->one("SELECT * FROM cancellation_requests WHERE job_id=? AND status='accepted'",[$jobId]);
            $this->require($refund&&$c!==false&&(int)$c['responded_by']===$actor,'Mutual consent is required.');
        }else{
            $d=$r->one("SELECT * FROM disputes WHERE job_id=? AND status='resolved'",[$jobId]);
            $u=$r->one('SELECT role,status FROM users WHERE id=?',[$actor]);
            $this->require($d!==false&&(int)$d['admin_id']===$actor&&$u!==false&&$u['role']==='admin'&&$u['status']==='active','Administrator resolution required.');
            $this->require(($d['decision']==='refund_client')===$refund,'Resolution outcome mismatch.');
        }
        $milestones=$r->rows('SELECT * FROM milestones WHERE job_id=? ORDER BY position,id FOR UPDATE',[$jobId]);
        $this->require(count($milestones)===(int)$escrow['milestone_count_at_funding']&&count($milestones)>=1&&count($milestones)<=2,'Frozen milestone schedule mismatch.');
        foreach($milestones as $i=>$m)$this->require((int)$m['payout_basis_points']===($i===0?3000:2000),'Frozen payment schedule mismatch.');
        $paid=PaymentMoney::sen($escrow['released_amount']);$refunded=PaymentMoney::sen($escrow['refunded_amount']);
        $remaining=PaymentMoney::sen($escrow['amount'])-$paid-$refunded;
        $amount=$remaining;
        if($phase==='milestone'){
            $found=false;
            foreach($milestones as $i=>$m){
                if((int)$m['id']===$milestone){$this->require($m['status']==='approved','Milestone approval is required.');$amount=PaymentMoney::target($escrow['amount'],$i===0?30:50)-$paid;$found=true;break;}
                $this->require($m['status']==='approved','Earlier milestone must be approved.');
            }
            $this->require($found,'Milestone does not belong to contract.');
        }
        if($phase==='final'){
            foreach($milestones as $m)$this->require($m['status']==='approved','All milestones must be approved.');
            $f=$r->one("SELECT status FROM work_submissions WHERE job_id=? AND submission_type='final' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$jobId]);
            $this->require($f!==false&&$f['status']==='approved','Approved final deliverable required.');
        }
        $this->require($amount>0&&$amount<=$remaining,'Invalid release amount.');
        $recipient=(int)($refund?$job['client_id']:$bid['freelancer_id']);
        $r->execute('INSERT INTO wallets (user_id,balance) VALUES (?,0.00) ON DUPLICATE KEY UPDATE user_id=user_id',[$recipient]);
        $wallet=$r->one('SELECT * FROM wallets WHERE user_id=? FOR UPDATE',[$recipient]);
        $this->require($wallet!==false&&PaymentMoney::sen($wallet['balance'])+$amount<=9999999999,'Wallet limit exceeded.');
        $value=PaymentMoney::decimal($amount);
        $type=$phase==='milestone'?'milestone_release':($refund?'escrow_refund':'escrow_release');
        $source=match($phase){'milestone'=>'milestone_approval','final'=>'client_approval','cancellation'=>'mutual_cancellation',default=>'admin_decision'};
        $r->record((int)$escrow['id'],$milestone,$recipient,$actor,$phase,$value,$key,$type,$source);
        $r->execute('UPDATE wallets SET balance=balance+? WHERE user_id=?',[$value,$recipient]);
        $released=PaymentMoney::decimal($paid+($refund?0:$amount));$refundTotal=PaymentMoney::decimal($refunded+($refund?$amount:0));
        $status=$phase==='milestone'?'locked':($refund?($paid>0?'settled_mixed':'refunded'):'released');
        $this->require($r->execute('UPDATE escrows SET released_amount=?,refunded_amount=?,status=?,settled_at=CASE WHEN ?=\'locked\' THEN NULL ELSE UTC_TIMESTAMP() END WHERE id=? AND status=\'locked\' AND released_amount=? AND refunded_amount=?',[$released,$refundTotal,$status,$status,$escrow['id'],$escrow['released_amount'],$escrow['refunded_amount']])===1,'Escrow changed concurrently.');
        if($phase==='final')$this->require($r->execute("UPDATE jobs SET status='completed',completed_at=UTC_TIMESTAMP(),closed_at=UTC_TIMESTAMP() WHERE id=? AND status='under_review'",[$jobId])===1,'Job completion failed.');
        $r->execute("INSERT INTO notifications (recipient_id,job_id,type,message) VALUES (?,?,?,?)",[$recipient,$jobId,$refund?'payment_refunded':'payment_released','Simulated payment of RM'.$value.' allocated for this contract. Previously released funds are irreversible.']);
        return $value;
    }
}
