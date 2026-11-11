<?php
declare(strict_types=1);

namespace GigHub\Services;

use GigHub\Repositories\DisputeRepository;
use PDO;
use Throwable;

class DisputeService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DisputeRepository $disputes
    ) {}

    /**
     * Strictly validate and normalize a monetary amount as a canonical DECIMAL(10,2) string.
     * Rejects float types to prevent binary floating-point rounding errors.
     * Rejects scientific notation, negative values, and out-of-range amounts.
     * Normalizes valid integer and decimal inputs to exact '0.00' format using string operations without PHP floats.
     */
    public static function validateMoneyString(mixed $rawAmount): string
    {
        if (is_int($rawAmount)) {
            $str = (string)$rawAmount;
        } elseif (is_string($rawAmount)) {
            $str = trim($rawAmount);
        } else {
            throw new DisputeException(['form' => 'Invalid monetary amount format. Float types and non-scalar values are disallowed.'], 422);
        }

        if (!preg_match('/\A\d{1,8}(\.\d{1,2})?\z/', $str)) {
            throw new DisputeException(['form' => 'Monetary amount must be a valid positive currency format.'], 422);
        }

        $parts = explode('.', $str, 2);
        $intPart = ltrim($parts[0], '0');
        $intPart = ($intPart === '') ? '0' : $intPart;
        $decPart = isset($parts[1]) ? str_pad($parts[1], 2, '0', STR_PAD_RIGHT) : '00';
        $canonical = $intPart . '.' . $decPart;

        if (!preg_match('/\A\d{1,8}\.\d{2}\z/', $canonical)) {
            throw new DisputeException(['form' => 'Normalized monetary amount does not match canonical DECIMAL(10,2) format.'], 422);
        }

        if (bccomp($canonical, '0.00', 2) <= 0) {
            throw new DisputeException(['form' => 'Monetary amount must be greater than zero.'], 422);
        }

        if (bccomp($canonical, '99999999.99', 2) > 0) {
            throw new DisputeException(['form' => 'Monetary amount exceeds the maximum allowed limit.'], 422);
        }

        return $canonical;
    }

    /**
     * Request mutual cancellation.
     */
    public function requestCancellation(int $userId, int $jobId, string $reason): array
    {
        $trimmedReason = trim($reason);
        if (mb_strlen($trimmedReason) < 10 || mb_strlen($trimmedReason) > 1000) {
            throw new DisputeException(['reason' => 'Cancellation reason must be between 10 and 1,000 characters.'], 422);
        }

        try {
            $this->pdo->beginTransaction();

            // 1. Lock job
            $job = $this->disputes->getJob($jobId, true);
            if ($job === null) {
                throw new DisputeException(['form' => 'Job not found.'], 404);
            }

            // 2. Lock awarded bid
            if (empty($job['awarded_bid_id'])) {
                throw new DisputeException(['form' => 'Job does not have an awarded contract.'], 409);
            }
            $bid = $this->disputes->getBid((int)$job['awarded_bid_id'], true);
            if ($bid === null) {
                throw new DisputeException(['form' => 'Awarded bid not found.'], 404);
            }

            // Verify participant authorization
            $clientId = (int)$job['client_id'];
            $freelancerId = (int)$bid['freelancer_id'];
            if ($userId !== $clientId && $userId !== $freelancerId) {
                throw new DisputeException(['form' => 'You are not authorized to cancel this contract.'], 403);
            }

            // Verify job status
            if (!in_array($job['status'], ['in_progress', 'under_review'], true)) {
                throw new DisputeException(['form' => 'Cancellation can only be requested for active or under-review jobs.'], 409);
            }

            // 3. Lock cancellation request (enforces UNIQUE(job_id) lifecycle)
            $existingCancel = $this->disputes->getCancellationRequest($jobId, true);
            if ($existingCancel !== null) {
                if ($existingCancel['status'] === 'pending') {
                    throw new DisputeException(['form' => 'A cancellation request is already pending for this job.'], 409);
                }
                if ($existingCancel['status'] === 'rejected') {
                    throw new DisputeException(['form' => 'A mutual cancellation request was previously declined. You may escalate to a formal dispute if agreement cannot be reached.'], 409);
                }
                throw new DisputeException(['form' => 'A cancellation request has already been processed for this job.'], 409);
            }

            // 4. Lock dispute
            $existingDispute = $this->disputes->getDisputeByJobId($jobId, true);
            if ($existingDispute !== null) {
                throw new DisputeException(['form' => 'This job is currently under formal dispute or already resolved.'], 409);
            }

            // Insert cancellation request
            $requestId = $this->disputes->createCancellationRequest($jobId, $userId, $trimmedReason);

            // Dispatch notification to counterpart
            $counterpartId = ($userId === $clientId) ? $freelancerId : $clientId;
            $notifMsg = 'A mutual cancellation request was submitted for "' . $job['title'] . '".';
            $this->disputes->createNotification($counterpartId, $jobId, 'cancellation_requested', $notifMsg);

            $this->pdo->commit();

            return [
                'request_id' => $requestId,
                'job_id' => $jobId,
                'status' => 'pending',
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof DisputeException) {
                throw $e;
            }
            throw new DisputeException(['form' => 'Failed to submit cancellation request: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Respond to a mutual cancellation request (accept or decline).
     */
    public function respondCancellation(int $userId, int $jobId, string $action): array
    {
        if (!in_array($action, ['accept', 'decline'], true)) {
            throw new DisputeException(['action' => 'Invalid action. Must be accept or decline.'], 422);
        }

        try {
            $this->pdo->beginTransaction();

            // 1. Lock job
            $job = $this->disputes->getJob($jobId, true);
            if ($job === null) {
                throw new DisputeException(['form' => 'Job not found.'], 404);
            }

            // 2. Lock awarded bid
            if (empty($job['awarded_bid_id'])) {
                throw new DisputeException(['form' => 'Job does not have an awarded contract.'], 409);
            }
            $bid = $this->disputes->getBid((int)$job['awarded_bid_id'], true);
            if ($bid === null) {
                throw new DisputeException(['form' => 'Awarded bid not found.'], 404);
            }

            $clientId = (int)$job['client_id'];
            $freelancerId = (int)$bid['freelancer_id'];

            if ($userId !== $clientId && $userId !== $freelancerId) {
                throw new DisputeException(['form' => 'You are not authorized to respond to this cancellation request.'], 403);
            }

            if (!in_array($job['status'], ['in_progress', 'under_review'], true)) {
                throw new DisputeException(['form' => 'Cannot respond to cancellation on an inactive job.'], 409);
            }

            // 3. Lock cancellation request
            $cancel = $this->disputes->getCancellationRequest($jobId, true);
            if ($cancel === null || $cancel['status'] !== 'pending') {
                throw new DisputeException(['form' => 'No pending cancellation request found for this job.'], 409);
            }

            // Disallow responding to own request
            if ((int)$cancel['requested_by'] === $userId) {
                throw new DisputeException(['form' => 'You cannot respond to your own cancellation request.'], 403);
            }

            $requesterId = (int)$cancel['requested_by'];

            if ($action === 'decline') {
                if (!$this->disputes->respondCancellationRequest((int)$cancel['id'], 'rejected', $userId)) {
                    throw new DisputeException(['form' => 'Failed to decline cancellation request.'], 409);
                }

                $notifMsg = 'Your mutual cancellation request for "' . $job['title'] . '" was declined. You may continue work or escalate to dispute.';
                $this->disputes->createNotification($requesterId, $jobId, 'cancellation_responded', $notifMsg);

                $this->pdo->commit();

                return [
                    'request_id' => (int)$cancel['id'],
                    'job_id' => $jobId,
                    'status' => 'rejected',
                ];
            }

            // If accept: atomic escrow refund to client
            // 4. Lock escrow
            $escrow = $this->disputes->getEscrow($jobId, true);
            if ($escrow === null) {
                throw new DisputeException(['form' => 'Escrow record not found for this job.'], 404);
            }
            if ($escrow['status'] !== 'locked' || $escrow['settled_at'] !== null) {
                throw new DisputeException(['form' => 'Escrow has already been settled.'], 409);
            }

            $escrowAmount = self::validateMoneyString($escrow['amount']);

            if ($this->disputes->hasSettlementLedger((int)$escrow['id'])) {
                throw new DisputeException(['form' => 'Settlement ledger entry already exists for this escrow.'], 409);
            }

            $operationKey = 'cancellation_refund:job:' . $jobId;
            if ($this->disputes->hasOperationKey($operationKey)) {
                throw new DisputeException(['form' => 'Cancellation refund operation has already been recorded.'], 409);
            }

            // 5. Lock client wallet (recipient of refund)
            $this->disputes->ensureWallet($clientId);
            $this->disputes->getWallet($clientId, true);

            // Execute atomic mutations
            // A. Update cancellation request to accepted
            if (!$this->disputes->respondCancellationRequest((int)$cancel['id'], 'accepted', $userId)) {
                throw new DisputeException(['form' => 'Failed to update cancellation request status.'], 409);
            }

            // B. Settle escrow to refunded
            if (!$this->disputes->settleEscrow((int)$escrow['id'], 'refunded')) {
                throw new DisputeException(['form' => 'Failed to settle escrow.'], 409);
            }

            // C. Credit client wallet
            if (!$this->disputes->creditWallet($clientId, $escrowAmount)) {
                throw new DisputeException(['form' => 'Failed to credit client wallet.'], 500);
            }

            // D. Append immutable ledger entry
            $this->disputes->insertLedgerEntry(
                $clientId,
                (int)$escrow['id'],
                'escrow_refund',
                $escrowAmount,
                'mutual_cancellation',
                $userId,
                $operationKey
            );

            // E. Transition job to cancelled with closed timestamp
            if (!$this->disputes->updateJobStatus($jobId, 'cancelled', true)) {
                throw new DisputeException(['form' => 'Failed to transition job to cancelled.'], 409);
            }

            // F. Notify both parties
            $clientMsg = 'Payment of RM' . $escrowAmount . ' for "' . $job['title'] . '" has been refunded to your wallet due to mutual cancellation.';
            $freelancerMsg = 'Job "' . $job['title'] . '" has been mutually cancelled.';
            $this->disputes->createNotification($clientId, $jobId, 'payment_refunded', $clientMsg);
            $this->disputes->createNotification($freelancerId, $jobId, 'job_cancelled', $freelancerMsg);

            $this->pdo->commit();

            return [
                'request_id' => (int)$cancel['id'],
                'job_id' => $jobId,
                'status' => 'accepted',
                'refund_amount' => $escrowAmount,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof DisputeException) {
                throw $e;
            }
            throw new DisputeException(['form' => 'Failed to process cancellation response: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Escalate contract to administrative dispute.
     */
    public function raiseDispute(int $userId, int $jobId, string $reason): array
    {
        $trimmedReason = trim($reason);
        if (mb_strlen($trimmedReason) < 10 || mb_strlen($trimmedReason) > 1000) {
            throw new DisputeException(['reason' => 'Dispute reason must be between 10 and 1,000 characters.'], 422);
        }

        try {
            $this->pdo->beginTransaction();

            // 1. Lock job
            $job = $this->disputes->getJob($jobId, true);
            if ($job === null) {
                throw new DisputeException(['form' => 'Job not found.'], 404);
            }

            // 2. Lock awarded bid
            if (empty($job['awarded_bid_id'])) {
                throw new DisputeException(['form' => 'Job does not have an awarded contract.'], 409);
            }
            $bid = $this->disputes->getBid((int)$job['awarded_bid_id'], true);
            if ($bid === null) {
                throw new DisputeException(['form' => 'Awarded bid not found.'], 404);
            }

            $clientId = (int)$job['client_id'];
            $freelancerId = (int)$bid['freelancer_id'];

            if ($userId !== $clientId && $userId !== $freelancerId) {
                throw new DisputeException(['form' => 'You are not authorized to raise a dispute on this contract.'], 403);
            }

            if (!in_array($job['status'], ['in_progress', 'under_review'], true)) {
                throw new DisputeException(['form' => 'Disputes can only be raised for active or under-review jobs.'], 409);
            }

            // 3. Lock cancellation request: if pending, supersede it
            $cancel = $this->disputes->getCancellationRequest($jobId, true);
            if ($cancel !== null && $cancel['status'] === 'pending') {
                $this->disputes->supersedeCancellationRequest($jobId);
            }

            // 4. Lock dispute: verify no existing dispute
            $existingDispute = $this->disputes->getDisputeByJobId($jobId, true);
            if ($existingDispute !== null) {
                throw new DisputeException(['form' => 'A dispute has already been raised for this job.'], 409);
            }

            // 5. Lock escrow: verify funded active escrow exists before escalating
            $escrow = $this->disputes->getEscrow($jobId, true);
            if ($escrow === null) {
                throw new DisputeException(['form' => 'Escrow record not found for this contract.'], 404);
            }
            if ($escrow['status'] !== 'locked' || $escrow['settled_at'] !== null) {
                throw new DisputeException(['form' => 'Dispute cannot be raised because contract escrow is already settled.'], 409);
            }

            // 6. Atomic mutations
            // A. Update job status to disputed
            if (!$this->disputes->updateJobStatus($jobId, 'disputed', false)) {
                throw new DisputeException(['form' => 'Failed to transition job status to disputed.'], 409);
            }

            // B. Create dispute record
            $disputeId = $this->disputes->createDispute($jobId, $userId, $trimmedReason);

            // C. Notify counterpart
            $counterpartId = ($userId === $clientId) ? $freelancerId : $clientId;
            $notifMsg = 'A formal dispute has been opened for "' . $job['title'] . '" and escalated for administrative mediation.';
            $this->disputes->createNotification($counterpartId, $jobId, 'dispute_opened', $notifMsg);

            $this->pdo->commit();

            return [
                'dispute_id' => $disputeId,
                'job_id' => $jobId,
                'status' => 'open',
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof DisputeException) {
                throw $e;
            }
            throw new DisputeException(['form' => 'Failed to raise dispute: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Submit counterpart formal written response to an open dispute.
     */
    public function submitDisputeResponse(int $userId, int $disputeId, string $response): array
    {
        $trimmedResponse = trim($response);
        if (mb_strlen($trimmedResponse) < 10 || mb_strlen($trimmedResponse) > 1000) {
            throw new DisputeException(['response' => 'Dispute response must be between 10 and 1,000 characters.'], 422);
        }

        // Unlocked read to resolve candidate job ID before establishing locks (SEC-M21-001)
        $initialDispute = $this->disputes->getDisputeById($disputeId, false);
        if ($initialDispute === null) {
            throw new DisputeException(['form' => 'Dispute not found.'], 404);
        }
        $jobId = (int)$initialDispute['job_id'];

        try {
            $this->pdo->beginTransaction();

            // 1. Lock job first (canonical lock hierarchy)
            $job = $this->disputes->getJob($jobId, true);
            if ($job === null) {
                throw new DisputeException(['form' => 'Disputed job not found.'], 404);
            }
            if ($job['status'] !== 'disputed') {
                throw new DisputeException(['form' => 'Job is not in disputed status.'], 409);
            }

            // 2. Lock awarded bid second
            if (empty($job['awarded_bid_id'])) {
                throw new DisputeException(['form' => 'Job does not have an awarded contract.'], 409);
            }
            $bid = $this->disputes->getBid((int)$job['awarded_bid_id'], true);
            if ($bid === null) {
                throw new DisputeException(['form' => 'Awarded bid not found.'], 404);
            }

            // 3. Lock dispute third
            $dispute = $this->disputes->getDisputeById($disputeId, true);
            if ($dispute === null) {
                throw new DisputeException(['form' => 'Dispute not found.'], 404);
            }
            if ((int)$dispute['job_id'] !== $jobId) {
                throw new DisputeException(['form' => 'Dispute data integrity mismatch.'], 409);
            }
            if ($dispute['status'] !== 'open') {
                throw new DisputeException(['form' => 'This dispute is no longer open for responses.'], 409);
            }
            if ($dispute['response'] !== null) {
                throw new DisputeException(['form' => 'A response has already been submitted for this dispute.'], 409);
            }

            $clientId = (int)$job['client_id'];
            $freelancerId = (int)$bid['freelancer_id'];

            if ($userId !== $clientId && $userId !== $freelancerId) {
                throw new DisputeException(['form' => 'You are not authorized to respond to this dispute.'], 403);
            }

            // Disallow raiser from submitting the response
            if ($userId === (int)$dispute['raised_by']) {
                throw new DisputeException(['form' => 'You cannot submit a counterpart response to a dispute you raised.'], 403);
            }

            // Submit response
            if (!$this->disputes->submitDisputeResponse($disputeId, $trimmedResponse)) {
                throw new DisputeException(['form' => 'Failed to save dispute response.'], 409);
            }

            // Notify raiser that counterpart has responded
            $raiserId = (int)$dispute['raised_by'];
            $notifMsg = 'A formal response was submitted for the dispute on "' . $job['title'] . '".';
            $this->disputes->createNotification($raiserId, $jobId, 'dispute_opened', $notifMsg);

            $this->pdo->commit();

            return [
                'dispute_id' => $disputeId,
                'job_id' => $jobId,
                'status' => 'open',
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof DisputeException) {
                throw $e;
            }
            throw new DisputeException(['form' => 'Failed to submit dispute response: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Admin mediation: resolve dispute with binary decision (refund_client or pay_freelancer).
     */
    public function resolveDispute(int $adminId, int $disputeId, string $decision, string $decisionNote): array
    {
        // Verify Admin identity and active status (SEC-M21-002)
        $admin = $this->disputes->getUser($adminId);
        if ($admin === null || $admin['role'] !== 'admin' || ($admin['status'] ?? '') !== 'active') {
            throw new DisputeException(['form' => 'Only active administrators can adjudicate disputes.'], 403);
        }

        if (!in_array($decision, ['refund_client', 'pay_freelancer'], true)) {
            throw new DisputeException(['decision' => 'Invalid resolution decision. Must be refund_client or pay_freelancer.'], 422);
        }

        $trimmedNote = trim($decisionNote);
        if (mb_strlen($trimmedNote) < 10 || mb_strlen($trimmedNote) > 1000) {
            throw new DisputeException(['decision_note' => 'Decision rationale note must be between 10 and 1,000 characters.'], 422);
        }

        // Unlocked read to resolve candidate job ID before establishing locks (SEC-M21-001)
        $initialDispute = $this->disputes->getDisputeById($disputeId, false);
        if ($initialDispute === null) {
            throw new DisputeException(['form' => 'Dispute not found.'], 404);
        }
        $jobId = (int)$initialDispute['job_id'];

        try {
            $this->pdo->beginTransaction();

            // 1. Lock job first (canonical lock hierarchy)
            $job = $this->disputes->getJob($jobId, true);
            if ($job === null) {
                throw new DisputeException(['form' => 'Disputed job not found.'], 404);
            }
            if ($job['status'] !== 'disputed') {
                throw new DisputeException(['form' => 'Job is not in disputed status.'], 409);
            }

            // 2. Lock awarded bid second
            if (empty($job['awarded_bid_id'])) {
                throw new DisputeException(['form' => 'Awarded contract bid not found.'], 409);
            }
            $bid = $this->disputes->getBid((int)$job['awarded_bid_id'], true);
            if ($bid === null) {
                throw new DisputeException(['form' => 'Awarded contract bid not found.'], 404);
            }

            $clientId = (int)$job['client_id'];
            $freelancerId = (int)$bid['freelancer_id'];

            // 3. Lock dispute third
            $dispute = $this->disputes->getDisputeById($disputeId, true);
            if ($dispute === null) {
                throw new DisputeException(['form' => 'Dispute not found.'], 404);
            }
            if ((int)$dispute['job_id'] !== $jobId) {
                throw new DisputeException(['form' => 'Dispute data integrity mismatch.'], 409);
            }
            if ($dispute['status'] !== 'open') {
                throw new DisputeException(['form' => 'This dispute has already been resolved.'], 409);
            }

            // 4. Lock escrow fourth
            $escrow = $this->disputes->getEscrow($jobId, true);
            if ($escrow === null) {
                throw new DisputeException(['form' => 'Escrow record not found for this job.'], 404);
            }
            if ($escrow['status'] !== 'locked' || $escrow['settled_at'] !== null) {
                throw new DisputeException(['form' => 'Escrow for this job has already been settled.'], 409);
            }

            $escrowAmount = self::validateMoneyString($escrow['amount']);

            if ($this->disputes->hasSettlementLedger((int)$escrow['id'])) {
                throw new DisputeException(['form' => 'Settlement ledger entry already exists for this escrow.'], 409);
            }

            $opKey = ($decision === 'refund_client')
                ? 'dispute_refund:job:' . $jobId . ':dispute:' . $disputeId
                : 'dispute_release:job:' . $jobId . ':dispute:' . $disputeId;

            if ($this->disputes->hasOperationKey($opKey)) {
                throw new DisputeException(['form' => 'Dispute resolution operation has already been recorded.'], 409);
            }

            // 5. Target wallet setup & lock (wallets locked fifth)
            if ($decision === 'refund_client') {
                $recipientId = $clientId;
                $escrowStatus = 'refunded';
                $entryType = 'escrow_refund';
            } else {
                $recipientId = $freelancerId;
                $escrowStatus = 'released';
                $entryType = 'escrow_release';
            }

            $this->disputes->ensureWallet($recipientId);
            $this->disputes->getWallet($recipientId, true);

            // Execute atomic mutations
            // A. Update dispute record to resolved
            if (!$this->disputes->resolveDispute($disputeId, $decision, $adminId, $trimmedNote)) {
                throw new DisputeException(['form' => 'Failed to resolve dispute.'], 409);
            }

            // B. Settle escrow
            if (!$this->disputes->settleEscrow((int)$escrow['id'], $escrowStatus)) {
                throw new DisputeException(['form' => 'Failed to settle escrow record.'], 409);
            }

            // C. Credit recipient wallet balance
            if (!$this->disputes->creditWallet($recipientId, $escrowAmount)) {
                throw new DisputeException(['form' => 'Failed to credit recipient wallet balance.'], 500);
            }

            // D. Append immutable ledger entry
            $this->disputes->insertLedgerEntry(
                $recipientId,
                (int)$escrow['id'],
                $entryType,
                $escrowAmount,
                'admin_decision',
                $adminId,
                $opKey
            );

            // E. Transition job status to closed_disputed with closed timestamp
            if (!$this->disputes->updateJobStatus($jobId, 'closed_disputed', true)) {
                throw new DisputeException(['form' => 'Failed to close disputed job.'], 409);
            }

            // F. Notify both parties
            $clientMsg = ($decision === 'refund_client')
                ? 'Admin mediation resolved the dispute for "' . $job['title'] . '". RM' . $escrowAmount . ' has been refunded to your wallet.'
                : 'Admin mediation resolved the dispute for "' . $job['title'] . '". RM' . $escrowAmount . ' was awarded to the freelancer.';

            $freelancerMsg = ($decision === 'pay_freelancer')
                ? 'Admin mediation resolved the dispute for "' . $job['title'] . '". RM' . $escrowAmount . ' has been released to your wallet.'
                : 'Admin mediation resolved the dispute for "' . $job['title'] . '". RM' . $escrowAmount . ' was refunded to the client.';

            $this->disputes->createNotification($clientId, $jobId, 'dispute_resolved', $clientMsg);
            $this->disputes->createNotification($freelancerId, $jobId, 'dispute_resolved', $freelancerMsg);

            $this->pdo->commit();

            return [
                'dispute_id' => $disputeId,
                'job_id' => $jobId,
                'decision' => $decision,
                'recipient_id' => $recipientId,
                'amount' => $escrowAmount,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof DisputeException) {
                throw $e;
            }
            throw new DisputeException(['form' => 'Failed to adjudicate dispute: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Fetch complete dispute dossier for Admin inspection.
     * Admin view is completely read-only and does NOT mutate chat read watermarks.
     */
    public function getDisputeDossier(int $adminId, int $disputeId, int $chatPage = 1, int $chatPerPage = 50): array
    {
        $admin = $this->disputes->getUser($adminId);
        if ($admin === null || $admin['role'] !== 'admin' || ($admin['status'] ?? '') !== 'active') {
            throw new DisputeException(['form' => 'Only active administrators can inspect dispute dossiers.'], 403);
        }

        $dispute = $this->disputes->getDisputeById($disputeId);
        if ($dispute === null) {
            throw new DisputeException(['form' => 'Dispute not found.'], 404);
        }

        $jobId = (int)$dispute['job_id'];
        $job = $this->disputes->getJob($jobId);
        if ($job === null) {
            throw new DisputeException(['form' => 'Job not found.'], 404);
        }

        $bidId = (int)($job['awarded_bid_id'] ?? 0);
        $bid = $bidId > 0 ? $this->disputes->getBid($bidId) : null;

        $client = $this->disputes->getUser((int)$job['client_id']);
        $freelancer = $bid !== null ? $this->disputes->getUser((int)$bid['freelancer_id']) : null;
        $escrow = $this->disputes->getEscrow($jobId);
        $cancellation = $this->disputes->getCancellationRequest($jobId);

        // Bounded chat evidence pagination (SEC-M21-005)
        $chatPage = max(1, $chatPage);
        $chatPerPage = ($chatPerPage > 0 && $chatPerPage <= 100) ? $chatPerPage : 50;
        $totalChatMessages = 0;
        $totalPages = 1;
        $chatMessages = [];

        if ($bidId > 0) {
            $totalChatMessages = $this->disputes->countDisputeChatMessages($bidId);
            $totalPages = max(1, (int)ceil($totalChatMessages / $chatPerPage));
            if ($chatPage > $totalPages) {
                $chatPage = $totalPages;
            }
            $offset = ($chatPage - 1) * $chatPerPage;
            $chatMessages = $this->disputes->getDisputeChatMessages($bidId, $chatPerPage, $offset);
        }

        $milestones = $this->disputes->getJobMilestonesAndSubmissions($jobId);

        return [
            'dispute' => $dispute,
            'job' => $job,
            'bid' => $bid,
            'client' => $client,
            'freelancer' => $freelancer,
            'escrow' => $escrow,
            'cancellation' => $cancellation,
            'chat_messages' => $chatMessages,
            'chat_pagination' => [
                'current_page' => $chatPage,
                'per_page' => $chatPerPage,
                'total_items' => $totalChatMessages,
                'total_pages' => $totalPages,
            ],
            'milestones' => $milestones,
        ];
    }

    /**
     * Fetch admin dispute queue.
     */
    public function getAdminQueue(int $adminId, string $status = 'open'): array
    {
        $admin = $this->disputes->getUser($adminId);
        if ($admin === null || $admin['role'] !== 'admin' || ($admin['status'] ?? '') !== 'active') {
            throw new DisputeException(['form' => 'Only active administrators can access the dispute queue.'], 403);
        }

        return $this->disputes->getAdminDisputes($status);
    }

    /**
     * Count open disputes for Admin badge/summary.
     */
    public function getOpenDisputeCount(int $adminId): int
    {
        $admin = $this->disputes->getUser($adminId);
        if ($admin === null || $admin['role'] !== 'admin' || ($admin['status'] ?? '') !== 'active') {
            return 0;
        }

        return $this->disputes->countOpenDisputes();
    }

    /**
     * Fetch dispute and cancellation context for a job page (client/freelancer view).
     */
    public function getJobDisputeContext(int $userId, int $jobId): array
    {
        $job = $this->disputes->getJob($jobId);
        if ($job === null) {
            return [];
        }

        $bidId = (int)($job['awarded_bid_id'] ?? 0);
        $bid = $bidId > 0 ? $this->disputes->getBid($bidId) : null;

        $clientId = (int)$job['client_id'];
        $freelancerId = $bid !== null ? (int)$bid['freelancer_id'] : 0;

        $isClient = ($userId === $clientId);
        $isFreelancer = ($userId === $freelancerId);

        $user = $this->disputes->getUser($userId);
        $isAdmin = ($user !== null && $user['role'] === 'admin');

        if (!$isClient && !$isFreelancer && !$isAdmin) {
            return [];
        }

        $cancellation = $this->disputes->getCancellationRequest($jobId);
        $dispute = $this->disputes->getDisputeByJobId($jobId);
        $escrow = $this->disputes->getEscrow($jobId);

        $isActiveJob = in_array($job['status'], ['in_progress', 'under_review'], true);

        $canCancel = $isActiveJob && $cancellation === null && $dispute === null && ($isClient || $isFreelancer);
        $canDispute = $isActiveJob && $dispute === null && ($isClient || $isFreelancer);

        $canRespondCancellation = $cancellation !== null
            && $cancellation['status'] === 'pending'
            && (int)$cancellation['requested_by'] !== $userId
            && ($isClient || $isFreelancer);

        $canRespondDispute = $dispute !== null
            && $dispute['status'] === 'open'
            && $dispute['response'] === null
            && (int)$dispute['raised_by'] !== $userId
            && ($isClient || $isFreelancer);

        return [
            'job' => $job,
            'bid' => $bid,
            'escrow' => $escrow,
            'cancellation' => $cancellation,
            'dispute' => $dispute,
            'is_client' => $isClient,
            'is_freelancer' => $isFreelancer,
            'is_admin' => $isAdmin,
            'can_cancel' => $canCancel,
            'can_dispute' => $canDispute,
            'can_respond_cancellation' => $canRespondCancellation,
            'can_respond_dispute' => $canRespondDispute,
        ];
    }
}
