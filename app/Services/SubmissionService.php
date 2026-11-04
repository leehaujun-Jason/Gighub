<?php
declare(strict_types=1);
namespace GigHub\Services;

use GigHub\Repositories\SubmissionRepository;
use PDO;
use Throwable;

class SubmissionService
{
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly SubmissionRepository $submissions
    ) {}

    public function getSubmissionContext(int $freelancerId, int $jobId, int $milestoneId): array
    {
        if (!$this->submissions->eligibleFreelancer($freelancerId)) {
            throw new SubmissionException(['form' => 'Your account cannot submit work.'], 403);
        }

        $job = $this->submissions->job($jobId);
        if ($job === false) {
            throw new SubmissionException(['form' => 'Job not found.'], 404);
        }

        if (!$this->submissions->isAwardedFreelancer($jobId, $freelancerId)) {
            throw new SubmissionException(['form' => 'Job not found or you are not the awarded freelancer.'], 404);
        }

        if ($job['status'] !== 'in_progress') {
            throw new SubmissionException(['form' => 'Submissions are only accepted for jobs currently in progress.'], 409);
        }

        if (!$this->submissions->hasLockedEscrow($jobId)) {
            throw new SubmissionException(['form' => 'Escrow must be funded and locked before work can be submitted.'], 409);
        }

        $milestone = $this->submissions->milestone($jobId, $milestoneId);
        if ($milestone === false) {
            throw new SubmissionException(['form' => 'Milestone not found for this project.'], 404);
        }

        if ($milestone['status'] === 'submitted') {
            throw new SubmissionException(['form' => 'This milestone already has a submission awaiting client review.'], 409);
        }

        if ($milestone['status'] === 'approved') {
            throw new SubmissionException(['form' => 'This milestone has already been approved.'], 409);
        }

        if (!in_array($milestone['status'], ['pending', 'revisions_requested'], true)) {
            throw new SubmissionException(['form' => 'This milestone cannot accept submissions at this time.'], 409);
        }

        return [
            'job' => $job,
            'milestone' => $milestone,
            'previous_submissions' => $this->submissions->submissionsForMilestone($jobId, $milestoneId),
        ];
    }

    public function submitMilestoneWork(int $freelancerId, int $jobId, int $milestoneId, array $input): array
    {
        // 1. Validate Form Input
        $validated = SubmissionValidator::validate($input);

        // 2. Transaction with Savepoint & Uniform Lock Ordering
        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT sp_work_submission');
        }

        try {
            // Step 1: Lock Job row
            $job = $this->submissions->job($jobId, true);
            if ($job === false) {
                throw new SubmissionException(['form' => 'Job not found.'], 404);
            }

            // Step 2: Lock Freelancer identity
            if (!$this->submissions->eligibleFreelancer($freelancerId, true)) {
                throw new SubmissionException(['form' => 'Your account cannot submit work.'], 403);
            }

            if (!$this->submissions->isAwardedFreelancer($jobId, $freelancerId)) {
                throw new SubmissionException(['form' => 'Job not found or you are not the awarded freelancer.'], 404);
            }

            if ($job['status'] !== 'in_progress') {
                throw new SubmissionException(['form' => 'Submissions are only accepted for jobs currently in progress.'], 409);
            }

            if (!$this->submissions->hasLockedEscrow($jobId)) {
                throw new SubmissionException(['form' => 'Escrow must be funded and locked before work can be submitted.'], 409);
            }

            // Step 3: Lock Milestone row
            $milestone = $this->submissions->milestone($jobId, $milestoneId, true);
            if ($milestone === false) {
                throw new SubmissionException(['form' => 'Milestone not found for this project.'], 404);
            }

            if ($milestone['status'] === 'submitted') {
                throw new SubmissionException(['form' => 'This milestone already has a submission awaiting client review.'], 409);
            }

            if ($milestone['status'] === 'approved') {
                throw new SubmissionException(['form' => 'This milestone has already been approved.'], 409);
            }

            if (!in_array($milestone['status'], ['pending', 'revisions_requested'], true)) {
                throw new SubmissionException(['form' => 'This milestone cannot accept submissions at this time.'], 409);
            }

            // Step 4: Insert Work Submission Record
            $submissionId = $this->submissions->createMilestoneSubmission(
                $jobId,
                $milestoneId,
                $freelancerId,
                $validated['notes'],
                $validated['deliverable_url']
            );

            // Step 5: Update Milestone Status to 'submitted'
            $this->submissions->updateMilestoneStatus($jobId, $milestoneId, 'submitted');

            if ($ownTx) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT sp_work_submission');
            }

            return [
                'submission_id' => $submissionId,
                'job_id' => $jobId,
                'milestone_id' => $milestoneId,
            ];
        } catch (Throwable $e) {
            if ($ownTx) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_work_submission');
            }
            throw $e;
        }
    }

    public function listSubmissionsForJob(int $userId, int $jobId, string $role): array
    {
        $job = $this->submissions->job($jobId);
        if ($job === false) {
            throw new SubmissionException(['form' => 'Job not found.'], 404);
        }

        if ($role === 'client') {
            if (!$this->submissions->eligibleClient($userId)) {
                throw new SubmissionException(['form' => 'Your account cannot view submissions.'], 403);
            }
            if ((int)$job['client_id'] !== $userId) {
                throw new SubmissionException(['form' => 'Job not found.'], 404);
            }
        } elseif ($role === 'freelancer') {
            if (!$this->submissions->eligibleFreelancer($userId)) {
                throw new SubmissionException(['form' => 'Your account cannot view submissions.'], 403);
            }
            if (!$this->submissions->isAwardedFreelancer($jobId, $userId)) {
                throw new SubmissionException(['form' => 'Job not found or you are not the awarded freelancer.'], 404);
            }
        } else {
            throw new SubmissionException(['form' => 'Access denied.'], 403);
        }

        return [
            'job' => $job,
            'submissions' => $this->submissions->submissionsForJob($jobId),
        ];
    }

    public function approveMilestone(int $clientId, int $jobId, int $milestoneId, int $submissionId, array $input): array
    {
        $validated = ReviewValidator::validateApproval($input);

        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT sp_milestone_review');
        }

        try {
            // Lock Order 1: Job
            $job = $this->submissions->job($jobId, true);
            if ($job === false || (int)$job['client_id'] !== $clientId) {
                throw new SubmissionException(['form' => 'Job not found.'], 404);
            }

            // Lock Order 2: Client User
            if (!$this->submissions->eligibleClient($clientId, true)) {
                throw new SubmissionException(['form' => 'Your account cannot review milestones.'], 403);
            }

            if ($job['status'] !== 'in_progress') {
                throw new SubmissionException(['form' => 'Milestones can only be reviewed for jobs currently in progress.'], 409);
            }

            if (!$this->submissions->hasLockedEscrow($jobId)) {
                throw new SubmissionException(['form' => 'Escrow must be funded and locked to review milestones.'], 409);
            }

            // Lock Order 3: Milestone
            $milestone = $this->submissions->milestone($jobId, $milestoneId, true);
            if ($milestone === false) {
                throw new SubmissionException(['form' => 'Milestone not found for this project.'], 404);
            }

            if ($milestone['status'] !== 'submitted') {
                throw new SubmissionException(['form' => 'This milestone is not currently awaiting review.'], 409);
            }

            // Lock Order 4: Targeted Submission
            $submission = $this->submissions->submissionById($submissionId, true);
            if ($submission === false || (int)$submission['job_id'] !== $jobId || (int)$submission['milestone_id'] !== $milestoneId) {
                throw new SubmissionException(['form' => 'Submitted deliverable not found for this milestone.'], 404);
            }

            if ($submission['status'] !== 'submitted') {
                throw new SubmissionException(['form' => 'Stale review request: this submission is no longer pending review.'], 409);
            }

            // Check that targeted submission is the latest attempt
            $latest = $this->submissions->latestSubmissionForMilestone($jobId, $milestoneId, true);
            if ($latest === false || (int)$latest['id'] !== $submissionId) {
                throw new SubmissionException(['form' => 'Stale review request: a newer submission exists for this milestone.'], 409);
            }

            // Guarded Update 1: Work Submission
            $subUpdated = $this->submissions->approveSubmission($submissionId, $clientId, $validated['feedback']);
            if ($subUpdated !== 1) {
                throw new SubmissionException(['form' => 'Submission status could not be updated.'], 409);
            }

            // Guarded Update 2: Milestone
            $msUpdated = $this->submissions->approveMilestoneStatus($jobId, $milestoneId);
            if ($msUpdated !== 1) {
                throw new SubmissionException(['form' => 'Milestone status could not be updated.'], 409);
            }

            if ($ownTx) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT sp_milestone_review');
            }

            return [
                'job_id' => $jobId,
                'milestone_id' => $milestoneId,
                'submission_id' => $submissionId,
            ];
        } catch (Throwable $e) {
            if ($ownTx) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_milestone_review');
            }
            throw $e;
        }
    }

    public function requestMilestoneRevision(int $clientId, int $jobId, int $milestoneId, int $submissionId, array $input): array
    {
        $validated = ReviewValidator::validateRevision($input);

        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT sp_milestone_review');
        }

        try {
            // Lock Order 1: Job
            $job = $this->submissions->job($jobId, true);
            if ($job === false || (int)$job['client_id'] !== $clientId) {
                throw new SubmissionException(['form' => 'Job not found.'], 404);
            }

            // Lock Order 2: Client User
            if (!$this->submissions->eligibleClient($clientId, true)) {
                throw new SubmissionException(['form' => 'Your account cannot review milestones.'], 403);
            }

            if ($job['status'] !== 'in_progress') {
                throw new SubmissionException(['form' => 'Milestones can only be reviewed for jobs currently in progress.'], 409);
            }

            if (!$this->submissions->hasLockedEscrow($jobId)) {
                throw new SubmissionException(['form' => 'Escrow must be funded and locked to review milestones.'], 409);
            }

            // Lock Order 3: Milestone
            $milestone = $this->submissions->milestone($jobId, $milestoneId, true);
            if ($milestone === false) {
                throw new SubmissionException(['form' => 'Milestone not found for this project.'], 404);
            }

            if ($milestone['status'] !== 'submitted') {
                throw new SubmissionException(['form' => 'This milestone is not currently awaiting review.'], 409);
            }

            // Lock Order 4: Targeted Submission
            $submission = $this->submissions->submissionById($submissionId, true);
            if ($submission === false || (int)$submission['job_id'] !== $jobId || (int)$submission['milestone_id'] !== $milestoneId) {
                throw new SubmissionException(['form' => 'Submitted deliverable not found for this milestone.'], 404);
            }

            if ($submission['status'] !== 'submitted') {
                throw new SubmissionException(['form' => 'Stale review request: this submission is no longer pending review.'], 409);
            }

            // Check that targeted submission is the latest attempt
            $latest = $this->submissions->latestSubmissionForMilestone($jobId, $milestoneId, true);
            if ($latest === false || (int)$latest['id'] !== $submissionId) {
                throw new SubmissionException(['form' => 'Stale review request: a newer submission exists for this milestone.'], 409);
            }

            // Guarded Update 1: Work Submission
            $subUpdated = $this->submissions->requestSubmissionRevision($submissionId, $clientId, $validated['feedback']);
            if ($subUpdated !== 1) {
                throw new SubmissionException(['form' => 'Submission status could not be updated.'], 409);
            }

            // Guarded Update 2: Milestone
            $msUpdated = $this->submissions->requestMilestoneRevisionStatus($jobId, $milestoneId);
            if ($msUpdated !== 1) {
                throw new SubmissionException(['form' => 'Milestone status could not be updated.'], 409);
            }

            if ($ownTx) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT sp_milestone_review');
            }

            return [
                'job_id' => $jobId,
                'milestone_id' => $milestoneId,
                'submission_id' => $submissionId,
            ];
        } catch (Throwable $e) {
            if ($ownTx) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_milestone_review');
            }
            throw $e;
        }
    }
}
