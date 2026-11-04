<?php
declare(strict_types=1);
namespace GigHub\Services;
use GigHub\Repositories\EscrowRepository;
use PDO;
use Throwable;

class EscrowService
{
    public function __construct(private readonly PDO $pdo, private readonly EscrowRepository $escrows) {}

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
        if ($bid === false || $bid['status'] !== 'pending') {
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
            if ($bid['status'] !== 'pending') {
                throw new EscrowException(['form' => 'Awarded bid is no longer pending.'], 409);
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
            if (!$this->escrows->acceptWinningBid($jobId, $bidId)) {
                throw new EscrowException(['form' => 'Failed to accept winning bid.'], 409);
            }

            // F. Reject all other pending bids on this job
            $this->escrows->rejectLosingBids($jobId, $bidId);

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
}
