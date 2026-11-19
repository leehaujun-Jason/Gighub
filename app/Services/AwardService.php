<?php
declare(strict_types=1);
namespace GigHub\Services;
use GigHub\Repositories\BidRepository;
use PDO;
use Throwable;

class AwardService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BidRepository $bids,
        private readonly ?\GigHub\Repositories\NotificationRepository $notifications = null
    ) {}
    public function award(int $clientId,int $jobId,int $bidId): void
    {
        $owns=!$this->pdo->inTransaction(); $started=false;
        try {
            if ($owns) $this->pdo->beginTransaction(); else $this->pdo->exec('SAVEPOINT gighub_award'); $started=true;
            // Same serialization boundary and lock order as bidding: job -> users -> bid.
            $job=$this->bids->job($jobId,true);
            if ($job===false || (int)$job['client_id']!==$clientId) throw new BidException(['form'=>'Job not found.'],404);
            if ($job['status']!=='open' || $job['awarded_bid_id']!==null || ($job['awarded_at']??null)!==null) throw new BidException(['form'=>'This job has already been selected or is no longer open.'],409);
            if (!$this->bids->eligible($clientId,'client',true)) throw new BidException(['form'=>'Your account cannot award jobs.'],403);
            // Read only to identify the bidder before taking the account lock, then re-read locked.
            $candidate=$this->bids->bidOnJob($jobId,$bidId);
            if ($candidate===false) throw new BidException(['form'=>'Bid not found.'],404);
            $freelancerId=(int)$candidate['freelancer_id'];
            if ($freelancerId===$clientId || !$this->bids->eligible($freelancerId,'freelancer',true)) throw new BidException(['form'=>'This Freelancer is not eligible for selection.'],409);
            $bid=$this->bids->bidOnJob($jobId,$bidId,true);
            if ($bid===false || (int)$bid['freelancer_id']!==$freelancerId) throw new BidException(['form'=>'Bid not found.'],404);
            if(PaymentMoney::progressive($job)&&PaymentMoney::sen((string)$bid['amount'])<1000)throw new BidException(['form'=>'Progressive contracts require at least RM10.00.'],422);
            if ($bid['status']!=='pending') throw new BidException(['form'=>'Only pending bids can be awarded.'],409);
            if (!$this->bids->selectAward($jobId,$clientId,$bidId)) throw new BidException(['form'=>'This job is no longer available for selection.'],409);
            if ($this->notifications !== null) {
                $this->notifications->create($freelancerId, $jobId, 'award_selected', 'Congratulations! Your bid for "' . $job['title'] . '" has been selected. Work begins once funding is completed.');
            }
            $accept = $this->pdo->prepare("UPDATE bids SET status='accepted' WHERE id=? AND job_id=? AND status='pending'");
            $accept->execute([$bidId, $jobId]);
            if ($accept->rowCount() !== 1) throw new BidException(['form'=>'This bid is no longer available.'],409);
            if ($this->notifications !== null) {
                $losers = $this->pdo->prepare("SELECT DISTINCT freelancer_id FROM bids WHERE job_id=? AND id<>? AND status='pending'");
                $losers->execute([$jobId, $bidId]);
                foreach ($losers->fetchAll(PDO::FETCH_COLUMN) as $recipient) {
                    $this->notifications->create((int)$recipient, $jobId, 'bid_not_selected', 'Another proposal was selected for "' . $job['title'] . '".');
                }
            }
            $reject = $this->pdo->prepare("UPDATE bids SET status='rejected' WHERE job_id=? AND id<>? AND status='pending'");
            $reject->execute([$jobId, $bidId]);
            if ($owns) $this->pdo->commit(); else $this->pdo->exec('RELEASE SAVEPOINT gighub_award');
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                if ($owns) $this->pdo->rollBack(); else { $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_award'); $this->pdo->exec('RELEASE SAVEPOINT gighub_award'); }
            }
            if ($error instanceof BidException) throw $error;
            throw new \RuntimeException('Award selection is temporarily unavailable.');
        }
    }
}
