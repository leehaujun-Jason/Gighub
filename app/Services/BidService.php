<?php
declare(strict_types=1);
namespace GigHub\Services;
use GigHub\Repositories\BidRepository;
use PDO;
use Throwable;

class BidService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BidRepository $bids,
        private readonly ?\GigHub\Repositories\NotificationRepository $notifications = null
    ) {}
    public function listing(int $freelancerId,int $page, ?string $status = null): array { return $this->bids->mine($freelancerId,$page,$status); }
    private function assertJob(array|false $job,int $freelancerId,bool $lock=false): void
    {
        if ($job===false || !$this->bids->eligible((int)$job['client_id'],'client',$lock)) throw new BidException(['form'=>'Job not found.'],404);
        if ((int)$job['client_id']===$freelancerId) throw new BidException(['form'=>'You cannot bid on your own job.'],403);
        if ($job['status']!=='open' || $job['awarded_bid_id']!==null) throw new BidException(['form'=>'This job is no longer accepting bid changes.'],409);
        if (!$this->bids->eligible($freelancerId,'freelancer',$lock)) throw new BidException(['form'=>'Your account cannot submit bids.'],403);
    }
    public function context(int $freelancerId,int $jobId,?int $bidId=null): array
    {
        $bid=$bidId===null?$this->bids->forJob($jobId,$freelancerId):$this->bids->owned($bidId,$freelancerId);
        if ($bidId!==null && ($bid===false || (int)$bid['job_id']!==$jobId)) throw new BidException(['form'=>'Bid not found.'],404);
        $job=$this->bids->job($jobId); $this->assertJob($job,$freelancerId);
        if ($bid!==false && !in_array($bid['status'],['pending','withdrawn'],true)) throw new BidException(['form'=>'This bid is locked.'],409);
        return ['job'=>$job,'bid'=>$bid];
    }
    public function edit(int $freelancerId,int $bidId): array
    {
        $bid=$this->bids->owned($bidId,$freelancerId);
        if ($bid===false) throw new BidException(['form'=>'Bid not found.'],404);
        return $this->context($freelancerId,(int)$bid['job_id'],$bidId);
    }
    public function clientListing(int $clientId,int $jobId,int $page): array
    {
        $job=$this->bids->job($jobId);
        if ($job===false || (int)$job['client_id']!==$clientId) throw new BidException(['form'=>'Job not found.'],404);
        return ['job'=>$job,'bids'=>$this->bids->clientBids($jobId,$clientId,$page)];
    }
    public function save(int $freelancerId,array $input,?int $bidId=null): int
    {
        $result=BidValidator::validate($input);
        if ($result['errors']!==[]) throw new BidException($result['errors']);
        return $this->write($freelancerId,$bidId,$input,$result['data'],false);
    }
    public function withdraw(int $freelancerId,int $bidId): void { $this->write($freelancerId,$bidId,[],[],true); }
    private function write(int $freelancerId,?int $bidId,array $input,array $data,bool $withdraw): int
    {
        $owns=!$this->pdo->inTransaction(); $started=false; $stored=null; $committing=false;
        try {
            // Discover parent without taking a child lock; lock order is job -> users -> bid.
            $prior=$bidId===null?false:$this->bids->owned($bidId,$freelancerId);
            if ($bidId!==null && $prior===false) throw new BidException(['form'=>'Bid not found.'],404);
            $jobId=$bidId===null?BidValidator::id($input['job_id']??null):(int)$prior['job_id'];
            if ($jobId===null) throw new BidException(['form'=>'Invalid job.'],400);
            if ($owns) $this->pdo->beginTransaction(); else $this->pdo->exec('SAVEPOINT gighub_bid'); $started=true;
            $job=$this->bids->job($jobId,true); $this->assertJob($job,$freelancerId,true);
            $bid=$this->bids->forJob($jobId,$freelancerId,true);
            if ($bidId!==null && ($bid===false || (int)$bid['id']!==$bidId)) throw new BidException(['form'=>'Bid not found.'],404);
            if ($withdraw) {
                if ($bid===false || $bid['status']!=='pending') throw new BidException(['form'=>'Only pending bids can be withdrawn.'],409);
                if (!$this->bids->supportsWithdrawal()) throw new BidException(['form'=>'Bid withdrawal is temporarily unavailable.'],503);
                $this->bids->withdraw($bidId,$freelancerId); $id=$bidId;
            } elseif ($bidId===null && $bid!==false) {
                throw new BidException(['form'=>'You already have a bid for this job. Manage it from My Bids.'],409);
            } elseif ($bid!==false) {
                if (!in_array($bid['status'],['pending','withdrawn'],true)) throw new BidException(['form'=>'This bid is locked.'],409);
                $isResubmit = $bid['status'] === 'withdrawn';
                $isMeaningful = bccomp(sprintf('%.2f', (float)$bid['amount']), sprintf('%.2f', (float)$data['amount']), 2) !== 0
                    || (int)$bid['delivery_days'] !== (int)$data['delivery_days']
                    || trim((string)$bid['cover_letter']) !== trim((string)$data['cover_letter']);
                $this->bids->update((int)$bid['id'],$freelancerId,$data,$bid['status']);
                $id=(int)$bid['id'];
                if ($this->notifications !== null) {
                    if ($isResubmit) {
                        $this->notifications->create((int)$job['client_id'], $jobId, 'bid_received', 'Resubmitted bid of RM' . number_format((float)$data['amount'], 2) . ' received for "' . $job['title'] . '".');
                    } elseif ($isMeaningful) {
                        $this->notifications->create((int)$job['client_id'], $jobId, 'bid_received', 'Updated bid of RM' . number_format((float)$data['amount'], 2) . ' received for "' . $job['title'] . '".');
                    }
                }
            } else {
                $id = $this->bids->insert($jobId, $freelancerId, $data);
                if ($this->notifications !== null) {
                    $this->notifications->create((int)$job['client_id'], $jobId, 'bid_received', 'New bid of RM' . number_format((float)$data['amount'], 2) . ' received for "' . $job['title'] . '".');
                }
            }
            $file=$input['_proposal']??null;
            if (!$withdraw && $file !== null && ($file['error']??null)!==UPLOAD_ERR_NO_FILE) {
                try { $stored=MarketplaceDocuments::storage()->store($file); }
                catch (\InvalidArgumentException $error) { throw new BidException(['form'=>$error->getMessage()]); }
                $q=$this->pdo->prepare('UPDATE bids SET proposal_document_key=?,proposal_document_name=? WHERE id=? AND freelancer_id=?');
                $q->execute([$stored['key'],$stored['name'],$id,$freelancerId]);
            }
            $committing=true;
            if ($owns) $this->pdo->commit(); else $this->pdo->exec('RELEASE SAVEPOINT gighub_bid');
            return $id;
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                if ($owns) $this->pdo->rollBack(); else { $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_bid'); $this->pdo->exec('RELEASE SAVEPOINT gighub_bid'); }
                if (!$committing && $stored!==null) MarketplaceDocuments::storage()->remove($stored['key']);
            }
            if ($error instanceof BidException) throw $error;
            if ($error instanceof \PDOException && (int)($error->errorInfo[1]??0)===1062) throw new BidException(['form'=>'You already have a bid for this job. Manage it from My Bids.'],409);
            throw new \RuntimeException('Bidding is temporarily unavailable.');
        }
    }
}
