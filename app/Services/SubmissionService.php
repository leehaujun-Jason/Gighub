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
        protected readonly SubmissionRepository $submissions,
        protected readonly ?\GigHub\Repositories\NotificationRepository $notifications = null
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

            // Step 6: Dispatch notification to client
            if ($this->notifications !== null) {
                $this->notifications->create((int)$job['client_id'], $jobId, 'submission_received', 'Work submitted for milestone "' . $milestone['title'] . '" on "' . $job['title'] . '".');
            }

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

            // Dispatch notification to freelancer
            if ($this->notifications !== null) {
                $this->notifications->create((int)$submission['freelancer_id'], $jobId, 'milestone_approved', 'Milestone "' . $milestone['title'] . '" on "' . $job['title'] . '" has been approved!');
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

            // Dispatch notification to freelancer
            if ($this->notifications !== null) {
                $this->notifications->create((int)$submission['freelancer_id'], $jobId, 'revision_requested', 'Revisions requested for milestone "' . $milestone['title'] . '" on "' . $job['title'] . '".');
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

    public function getFinalSubmissionContext(int $freelancerId, int $jobId): array
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
            throw new SubmissionException(['form' => 'Final submissions are only accepted for jobs currently in progress.'], 409);
        }

        if (!$this->submissions->hasLockedEscrow($jobId)) {
            throw new SubmissionException(['form' => 'Escrow must be funded and locked before work can be submitted.'], 409);
        }

        if (!$this->submissions->allMilestonesApproved($jobId)) {
            throw new SubmissionException(['form' => 'All milestones must be approved before submitting final deliverables.'], 409);
        }

        if ($this->submissions->hasApprovedFinalSubmission($jobId)) {
            throw new SubmissionException(['form' => 'Final work deliverable has already been approved.'], 409);
        }

        if ($this->submissions->hasPendingFinalSubmission($jobId)) {
            throw new SubmissionException(['form' => 'A final deliverable submission is already awaiting client review.'], 409);
        }

        return [
            'job' => $job,
            'escrow' => $this->submissions->escrow($jobId) ?: null,
            'milestones' => $this->submissions->milestonesForJob($jobId),
            'previous_submissions' => $this->submissions->finalSubmissionsForJob($jobId),
            'latest_submission' => $this->submissions->latestFinalSubmissionForJob($jobId),
        ];
    }

    public function submitFinalWork(int $freelancerId, int $jobId, array $input): array
    {
        // 1. Validate Form Input
        $validated = SubmissionValidator::validate($input);

        // 2. Transaction with Savepoint & Uniform Lock Ordering
        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT sp_final_work_submission');
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
                throw new SubmissionException(['form' => 'Final submissions are only accepted for jobs currently in progress.'], 409);
            }

            if (!$this->submissions->hasLockedEscrow($jobId)) {
                throw new SubmissionException(['form' => 'Escrow must be funded and locked before work can be submitted.'], 409);
            }

            // Step 3: Lock Milestones and verify all approved
            if (!$this->submissions->allMilestonesApproved($jobId, true)) {
                throw new SubmissionException(['form' => 'All milestones must be approved before submitting final deliverables.'], 409);
            }

            // Step 4: Check for active or approved final submissions
            if ($this->submissions->hasApprovedFinalSubmission($jobId, true)) {
                throw new SubmissionException(['form' => 'Final work deliverable has already been approved.'], 409);
            }

            if ($this->submissions->hasPendingFinalSubmission($jobId, true)) {
                throw new SubmissionException(['form' => 'A final deliverable submission is already awaiting client review.'], 409);
            }

            // Step 5: Insert Final Submission Record
            $submissionId = $this->submissions->createFinalSubmission(
                $jobId,
                $freelancerId,
                $validated['notes'],
                $validated['deliverable_url']
            );

            // Step 6: Guarded Job Transition from 'in_progress' to 'under_review'
            $jobUpdated = $this->submissions->updateJobStatus($jobId, 'under_review', 'in_progress');
            if ($jobUpdated !== 1) {
                throw new SubmissionException(['form' => 'Job status could not be updated to under review.'], 409);
            }

            // Step 7: Dispatch notification to client
            if ($this->notifications !== null) {
                $this->notifications->create((int)$job['client_id'], $jobId, 'submission_received', 'Final project deliverables submitted for "' . $job['title'] . '".');
            }

            if ($ownTx) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT sp_final_work_submission');
            }

            return [
                'submission_id' => $submissionId,
                'job_id' => $jobId,
            ];
        } catch (Throwable $e) {
            if ($ownTx) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_final_work_submission');
            }
            throw $e;
        }
    }

    public function approveFinalWork(int $clientId, int $jobId, int $submissionId, array $input): array
    {
        $validated = ReviewValidator::validateApproval($input);

        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT sp_final_review');
        }

        try {
            // Lock Order 1: Job
            $job = $this->submissions->job($jobId, true);
            if ($job === false || (int)$job['client_id'] !== $clientId) {
                throw new SubmissionException(['form' => 'Job not found.'], 404);
            }

            // Lock Order 2: Client User
            if (!$this->submissions->eligibleClient($clientId, true)) {
                throw new SubmissionException(['form' => 'Your account cannot review deliverables.'], 403);
            }

            if ($job['status'] !== 'under_review') {
                throw new SubmissionException(['form' => 'Final deliverables can only be reviewed when the job is under review.'], 409);
            }

            if (!$this->submissions->hasLockedEscrow($jobId)) {
                throw new SubmissionException(['form' => 'Escrow must be funded and locked to review deliverables.'], 409);
            }

            // Lock Order 3: Milestones (verify all still approved)
            if (!$this->submissions->allMilestonesApproved($jobId, true)) {
                throw new SubmissionException(['form' => 'All milestones must remain approved to accept final deliverables.'], 409);
            }

            // Lock Order 4: Targeted Final Submission
            $submission = $this->submissions->submissionById($submissionId, true);
            if ($submission === false || (int)$submission['job_id'] !== $jobId || $submission['submission_type'] !== 'final') {
                throw new SubmissionException(['form' => 'Final submission deliverable not found for this project.'], 404);
            }

            if ($submission['status'] !== 'submitted') {
                throw new SubmissionException(['form' => 'Stale review request: this submission is no longer pending review.'], 409);
            }

            // Check that targeted submission is the latest final attempt
            $latest = $this->submissions->latestFinalSubmissionForJob($jobId, true);
            if ($latest === false || (int)$latest['id'] !== $submissionId) {
                throw new SubmissionException(['form' => 'Stale review request: a newer final submission exists for this job.'], 409);
            }

            // Guarded Update 1: Final Work Submission to 'approved'
            $subUpdated = $this->submissions->approveSubmission($submissionId, $clientId, $validated['feedback']);
            if ($subUpdated !== 1) {
                throw new SubmissionException(['form' => 'Final submission status could not be updated.'], 409);
            }

            // Job status remains 'under_review' and Escrow remains 'locked' awaiting settlement in Module 16

            if ($ownTx) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT sp_final_review');
            }

            return [
                'job_id' => $jobId,
                'submission_id' => $submissionId,
            ];
        } catch (Throwable $e) {
            if ($ownTx) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_final_review');
            }
            throw $e;
        }
    }

    public function requestFinalRevision(int $clientId, int $jobId, int $submissionId, array $input): array
    {
        $validated = ReviewValidator::validateRevision($input);

        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT sp_final_review');
        }

        try {
            // Lock Order 1: Job
            $job = $this->submissions->job($jobId, true);
            if ($job === false || (int)$job['client_id'] !== $clientId) {
                throw new SubmissionException(['form' => 'Job not found.'], 404);
            }

            // Lock Order 2: Client User
            if (!$this->submissions->eligibleClient($clientId, true)) {
                throw new SubmissionException(['form' => 'Your account cannot review deliverables.'], 403);
            }

            if ($job['status'] !== 'under_review') {
                throw new SubmissionException(['form' => 'Final deliverables can only be reviewed when the job is under review.'], 409);
            }

            if (!$this->submissions->hasLockedEscrow($jobId)) {
                throw new SubmissionException(['form' => 'Escrow must be funded and locked to review deliverables.'], 409);
            }

            // Lock Order 3: Milestones (verify all still approved)
            if (!$this->submissions->allMilestonesApproved($jobId, true)) {
                throw new SubmissionException(['form' => 'All milestones must remain approved to review final deliverables.'], 409);
            }

            // Lock Order 4: Targeted Final Submission
            $submission = $this->submissions->submissionById($submissionId, true);
            if ($submission === false || (int)$submission['job_id'] !== $jobId || $submission['submission_type'] !== 'final') {
                throw new SubmissionException(['form' => 'Final submission deliverable not found for this project.'], 404);
            }

            if ($submission['status'] !== 'submitted') {
                throw new SubmissionException(['form' => 'Stale review request: this submission is no longer pending review.'], 409);
            }

            // Check that targeted submission is the latest final attempt
            $latest = $this->submissions->latestFinalSubmissionForJob($jobId, true);
            if ($latest === false || (int)$latest['id'] !== $submissionId) {
                throw new SubmissionException(['form' => 'Stale review request: a newer final submission exists for this job.'], 409);
            }

            // Guarded Update 1: Final Work Submission to 'revisions_requested'
            $subUpdated = $this->submissions->requestSubmissionRevision($submissionId, $clientId, $validated['feedback']);
            if ($subUpdated !== 1) {
                throw new SubmissionException(['form' => 'Final submission status could not be updated.'], 409);
            }

            // Guarded Update 2: Revert Job Status from 'under_review' to 'in_progress'
            $jobUpdated = $this->submissions->updateJobStatus($jobId, 'in_progress', 'under_review');
            if ($jobUpdated !== 1) {
                throw new SubmissionException(['form' => 'Job status could not be reverted to in progress.'], 409);
            }

            // Dispatch notification to freelancer
            if ($this->notifications !== null) {
                $this->notifications->create((int)$submission['freelancer_id'], $jobId, 'revision_requested', 'Revisions requested for final deliverables on "' . $job['title'] . '".');
            }

            // Milestone approvals remain intact

            if ($ownTx) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT sp_final_review');
            }

            return [
                'job_id' => $jobId,
                'submission_id' => $submissionId,
            ];
        } catch (Throwable $e) {
            if ($ownTx) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_final_review');
            }
            throw $e;
        }
    }
}
