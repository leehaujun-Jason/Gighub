<?php
declare(strict_types=1);
namespace GigHub\Services;
use GigHub\Repositories\EscrowRepository;
use PDO;
use Throwable;

class EscrowService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EscrowRepository $escrows,
        private readonly ?\GigHub\Repositories\NotificationRepository $notifications = null
    ) {}

    public function getFundingDetails(int $clientId, int $jobId): array
    {
        $job = $this->escrows->job($jobId);
        if ($job === false || (int)$job['client_id'] !== $clientId) {
            throw new EscrowException(['form' => 'Job not found.'], 404);
        }

        if (!$this->escrows->eligibleClient($clientId)) {
            throw new EscrowException(['form' => 'Your account cannot fund jobs.'], 403);
        }

        if ($job['status'] !== 'awarded' || $job['awarded_bid_id'] === null) {
            throw new EscrowException(['form' => 'Only awarded jobs awaiting funding can be funded.'], 409);
        }

        if ($this->escrows->hasEscrow($jobId)) {
            throw new EscrowException(['form' => 'This job has already been funded.'], 409);
        }

        $bid = $this->escrows->awardedBid($jobId, (int)$job['awarded_bid_id']);
        if ($bid === false || !in_array($bid['status'], ['pending', 'accepted'], true)) {
            throw new EscrowException(['form' => 'Awarded bid is not available for funding.'], 409);
        }

        $milestones = $this->escrows->milestones($jobId);
        if (count($milestones) < 1) {
            throw new EscrowException(['form' => 'You must set up at least one milestone before funding this job.'], 422);
        }

        $this->escrows->ensureWallet($clientId);
        $wallet = $this->escrows->getWallet($clientId);
        $balance = (string)($wallet['balance'] ?? '0.00');
        $bidAmount = number_format((float)$bid['amount'], 2, '.', '');
        $isSufficient = bccomp($balance, $bidAmount, 2) >= 0;
        $remaining = $isSufficient ? bcsub($balance, $bidAmount, 2) : '0.00';

        return [
            'job' => $job,
            'bid' => $bid,
            'milestones' => $milestones,
            'wallet' => $wallet,
            'balance' => $balance,
            'amount' => $bidAmount,
            'remaining' => $remaining,
            'is_sufficient' => $isSufficient,
        ];
    }

    public function fundJob(int $clientId, int $jobId): array
    {
        $owns = !$this->pdo->inTransaction();
        $started = false;

        try {
            if ($owns) {
                $this->pdo->beginTransaction();
            } else {
                $this->pdo->exec('SAVEPOINT gighub_escrow_fund');
            }
            $started = true;

            // 1. Lock job row
            $job = $this->escrows->job($jobId, true);
            if ($job === false || (int)$job['client_id'] !== $clientId) {
                throw new EscrowException(['form' => 'Job not found.'], 404);
            }
            if ($job['status'] !== 'awarded' || $job['awarded_bid_id'] === null) {
                throw new EscrowException(['form' => 'Only awarded jobs awaiting funding can be funded.'], 409);
            }

            // 2. Lock and verify active, email-verified Client account
            if (!$this->escrows->eligibleClient($clientId, true)) {
                throw new EscrowException(['form' => 'Your account cannot fund jobs.'], 403);
            }

            // 3. Lock awarded bid row
            $bidId = (int)$job['awarded_bid_id'];
            $bid = $this->escrows->awardedBid($jobId, $bidId, true);
            if ($bid === false) {
                throw new EscrowException(['form' => 'Awarded bid not found.'], 404);
            }
            if (!in_array($bid['status'], ['pending', 'accepted'], true)) {
                throw new EscrowException(['form' => 'Awarded bid is no longer eligible for funding.'], 409);
            }
            $amount = number_format((float)$bid['amount'], 2, '.', '');
            if (bccomp($amount, '0.00', 2) <= 0) {
                throw new EscrowException(['form' => 'Invalid bid amount.'], 422);
            }

            // 4. Lock and verify no existing escrow record
            if ($this->escrows->hasEscrow($jobId, true)) {
                throw new EscrowException(['form' => 'This job has already been funded.'], 409);
            }

            // 5. Verify at least one milestone is defined
            if ($this->escrows->milestoneCount($jobId) < 1) {
                throw new EscrowException(['form' => 'You must set up at least one milestone before funding this job.'], 422);
            }

            // 6. Lock and verify Client wallet balance
            $this->escrows->ensureWallet($clientId);
            $wallet = $this->escrows->getWallet($clientId, true);
            $balance = (string)($wallet['balance'] ?? '0.00');
            if (bccomp($balance, $amount, 2) < 0) {
                throw new EscrowException([
                    'form' => 'Insufficient wallet balance. Required: RM' . number_format((float)$amount, 2) . ', available: RM' . number_format((float)$balance, 2) . '. Please claim demo credit in your wallet.'
                ], 422);
            }

            // 7. Atomic state mutations
            // A. Deduct exact amount from client wallet balance
            if (!$this->escrows->debitWallet($clientId, $amount)) {
                throw new EscrowException(['form' => 'Could not debit wallet balance.'], 409);
            }

            // B. Create locked escrow record
            $escrowId = $this->escrows->createEscrow($jobId, $bidId, $amount);

            // C. Append immutable negative ledger entry
            $operationKey = 'escrow_fund:job:' . $jobId;
            $this->escrows->insertLedgerDebit($clientId, $escrowId, '-' . $amount, $clientId, $operationKey);

            // D. Transition job status to in_progress
            if (!$this->escrows->transitionJobToInProgress($jobId, $bidId)) {
                throw new EscrowException(['form' => 'Failed to transition job status.'], 409);
            }

            // E. Transition winning bid status to accepted
            if ($bid['status'] === 'pending' && !$this->escrows->acceptWinningBid($jobId, $bidId)) {
                throw new EscrowException(['form' => 'Failed to accept winning bid.'], 409);
            }

            // F. Reject all other pending bids on this job
            $this->escrows->rejectLosingBids($jobId, $bidId);

            // G. Dispatch notification to winning freelancer
            if ($this->notifications !== null) {
                $this->notifications->create((int)$bid['freelancer_id'], $jobId, 'escrow_funded', 'Escrow of RM' . number_format((float)$amount, 2) . ' has been funded for "' . $job['title'] . '". You may now begin work.');
            }

            if ($owns) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT gighub_escrow_fund');
            }

            return [
                'escrow_id' => $escrowId,
                'job_id' => $jobId,
                'bid_id' => $bidId,
                'amount' => $amount,
            ];
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                if ($owns) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_escrow_fund');
                    $this->pdo->exec('RELEASE SAVEPOINT gighub_escrow_fund');
                }
            }
            if ($error instanceof EscrowException) {
                throw $error;
            }
            error_log('Escrow funding error: ' . $error->getMessage());
            throw new \RuntimeException('Escrow funding is temporarily unavailable.');
        }
    }

    public function getEscrowForJob(int $jobId): array|false
    {
        return $this->escrows->getEscrow($jobId);
    }

    public function getSettlementConfirmationDetails(int $clientId, int $jobId): array
    {
        $job = $this->escrows->job($jobId);
        if ($job === false || (int)$job['client_id'] !== $clientId) {
            throw new EscrowException(['form' => 'Job not found.'], 404);
        }

        if (!$this->escrows->eligibleClient($clientId)) {
            throw new EscrowException(['form' => 'Your account cannot release escrow.'], 403);
        }

        if ($job['status'] !== 'under_review') {
            throw new EscrowException(['form' => 'Only jobs under review can have escrow released.'], 409);
        }

        if ($job['awarded_bid_id'] === null) {
            throw new EscrowException(['form' => 'Job has no awarded bid.'], 409);
        }

        $bid = $this->escrows->awardedBid($jobId, (int)$job['awarded_bid_id']);
        if ($bid === false || $bid['status'] !== 'accepted') {
            throw new EscrowException(['form' => 'Awarded bid is not accepted.'], 409);
        }

        $escrow = $this->escrows->getEscrow($jobId);
        if ($escrow === false || $escrow['status'] !== 'locked' || $escrow['settled_at'] !== null) {
            throw new EscrowException(['form' => 'Escrow is not in locked state or has already been settled.'], 409);
        }

        $milestones = $this->escrows->milestones($jobId);
        if (count($milestones) < 1) {
            throw new EscrowException(['form' => 'Job has no milestones defined.'], 422);
        }
        foreach ($milestones as $ms) {
            if ($ms['status'] !== 'approved') {
                throw new EscrowException(['form' => 'All milestones must be approved before releasing escrow.'], 422);
            }
        }

        $freelancerId = (int)$bid['freelancer_id'];
        $freelancerWallet = $this->escrows->getWallet($freelancerId);
        $hasFreelancerWallet = $freelancerWallet !== false;

        $amount = number_format((float)$escrow['amount'], 2, '.', '');

        return [
            'job' => $job,
            'bid' => $bid,
            'escrow' => $escrow,
            'amount' => $amount,
            'freelancer_name' => $bid['freelancer_name'] ?? 'Freelancer',
            'freelancer_id' => $freelancerId,
            'has_freelancer_wallet' => $hasFreelancerWallet,
        ];
    }

    public function releaseEscrowAndCompleteJob(int $clientId, int $jobId): array
    {
        if ($this->pdo->inTransaction()) {
            throw new EscrowException(['form' => 'Cannot execute settlement inside an existing transaction.'], 500);
        }

        $this->pdo->beginTransaction();

        try {
            // 1. Lock job row
            $job = $this->escrows->job($jobId, true);
            if ($job === false || (int)$job['client_id'] !== $clientId) {
                throw new EscrowException(['form' => 'Job not found.'], 404);
            }
            if ($job['status'] !== 'under_review') {
                throw new EscrowException(['form' => 'Only jobs under review can have escrow released.'], 409);
            }
            if ($job['awarded_bid_id'] === null) {
                throw new EscrowException(['form' => 'Job has no awarded bid.'], 409);
            }

            // 2. Lock and verify active, email-verified Client account
            if (!$this->escrows->eligibleClient($clientId, true)) {
                throw new EscrowException(['form' => 'Your account cannot release escrow.'], 403);
            }

            // 3. Lock awarded bid row
            $bid = $this->escrows->awardedBid($jobId, (int)$job['awarded_bid_id'], true);
            if ($bid === false) {
                throw new EscrowException(['form' => 'Awarded bid not found.'], 404);
            }
            if ($bid['status'] !== 'accepted') {
                throw new EscrowException(['form' => 'Awarded bid is not accepted.'], 409);
            }
            $bidAmount = number_format((float)$bid['amount'], 2, '.', '');

            // 4. Lock and verify active, email-verified Freelancer
            $freelancerId = (int)$bid['freelancer_id'];
            if (!$this->escrows->eligibleFreelancer($freelancerId, true)) {
                throw new EscrowException(['form' => 'Awarded freelancer account is not eligible for payment.'], 403);
            }

            // 5. Lock Freelancer destination wallet — must exist, no silent auto-creation
            $wallet = $this->escrows->getWallet($freelancerId, true);
            if ($wallet === false) {
                throw new EscrowException([
                    'form' => 'Winning freelancer has not initialized their wallet yet. The freelancer must visit their wallet page before payment can be released.'
                ], 409);
            }

            // 6. Lock escrow row
            $escrow = $this->escrows->getEscrow($jobId, true);
            if ($escrow === false) {
                throw new EscrowException(['form' => 'Escrow record not found for this job.'], 404);
            }
            if ($escrow['status'] !== 'locked' || $escrow['settled_at'] !== null) {
                throw new EscrowException(['form' => 'Escrow is not in locked state or has already been settled.'], 409);
            }
            $escrowAmount = number_format((float)$escrow['amount'], 2, '.', '');
            if (bccomp($escrowAmount, $bidAmount, 2) !== 0) {
                throw new EscrowException(['form' => 'Escrow amount does not match the accepted bid amount.'], 422);
            }

            // 7. Lock milestones in ascending ID order and assert every milestone is approved
            $milestones = $this->escrows->lockMilestones($jobId);
            if (empty($milestones)) {
                throw new EscrowException(['form' => 'Job has no milestones defined.'], 422);
            }
            foreach ($milestones as $ms) {
                if ($ms['status'] !== 'approved') {
                    throw new EscrowException(['form' => 'All milestones must be approved before releasing escrow.'], 422);
                }
            }

            // 8. Lock latest final submission and assert approved
            $finalSub = $this->escrows->lockLatestFinalSubmission($jobId);
            if ($finalSub === false || $finalSub['status'] !== 'approved') {
                throw new EscrowException(['form' => 'Final deliverable must be approved before releasing escrow.'], 409);
            }

            // 9. Check idempotency guards
            if ($this->escrows->hasSettlementLedger((int)$escrow['id'])) {
                throw new EscrowException(['form' => 'Escrow for this job has already been released.'], 409);
            }
            $operationKey = 'escrow_release:job:' . $jobId;
            if ($this->escrows->hasOperationKey($operationKey)) {
                throw new EscrowException(['form' => 'Settlement operation has already been recorded.'], 409);
            }

            // 10. Atomic mutations
            // A. Release locked escrow
            if (!$this->escrows->releaseEscrow((int)$escrow['id'])) {
                throw new EscrowException(['form' => 'Failed to release escrow.'], 409);
            }

            // B. Credit freelancer destination wallet
            if (!$this->escrows->creditWallet($freelancerId, $escrowAmount)) {
                throw new EscrowException(['form' => 'Failed to credit freelancer wallet.'], 500);
            }

            // C. Insert immutable append-only ledger entry
            $ledgerId = $this->escrows->insertLedgerRelease($freelancerId, (int)$escrow['id'], $escrowAmount, $clientId, $operationKey);

            // D. Transition job to completed
            if (!$this->escrows->completeJob($jobId)) {
                throw new EscrowException(['form' => 'Failed to transition job status.'], 409);
            }

            // E. Create payment released notification for winning freelancer
            $notifMsg = 'Payment of RM' . number_format((float)$escrowAmount, 2) . ' for "' . $job['title'] . '" has been released to your wallet.';
            $this->escrows->createPaymentNotification($freelancerId, $jobId, $notifMsg);

            $this->pdo->commit();

            return [
                'job_id' => $jobId,
                'escrow_id' => (int)$escrow['id'],
                'freelancer_id' => $freelancerId,
                'freelancer_name' => $bid['freelancer_name'] ?? 'Freelancer',
                'amount' => $escrowAmount,
                'ledger_id' => $ledgerId,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof EscrowException) {
                throw $error;
            }
            error_log('Escrow release error: ' . $error->getMessage());
            throw new \RuntimeException('Escrow settlement is temporarily unavailable.');
        }
    }
}
