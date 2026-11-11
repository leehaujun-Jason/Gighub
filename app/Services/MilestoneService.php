<?php
declare(strict_types=1);
namespace GigHub\Services;
use GigHub\Repositories\MilestoneRepository;
use PDO;
use Throwable;

class MilestoneService
{
    public const MAX_MILESTONES = 20;

    public function __construct(private readonly PDO $pdo, private readonly MilestoneRepository $milestones) {}

    public function listForJob(int $clientId, int $jobId): array
    {
        $job = $this->milestones->job($jobId);
        if ($job === false || (int)$job['client_id'] !== $clientId) {
            throw new MilestoneException(['form' => 'Job not found.'], 404);
        }
        if (!$this->milestones->eligibleClient($clientId)) {
            throw new MilestoneException(['form' => 'Your account cannot access milestones.'], 403);
        }
        $allowedStatuses = ['awarded', 'in_progress', 'under_review', 'completed', 'disputed', 'closed_disputed', 'cancelled'];
        if (!in_array($job['status'], $allowedStatuses, true) || $job['awarded_bid_id'] === null) {
            throw new MilestoneException(['form' => 'Milestones are only available for awarded or in-progress jobs.'], 409);
        }

        $hasEscrow = $this->milestones->hasEscrow($jobId);
        $escrow = $hasEscrow ? $this->milestones->escrow($jobId) : false;
        $isLocked = $hasEscrow || $job['status'] !== 'awarded';
        $freelancer = method_exists($this->milestones, 'awardedFreelancer') ? $this->milestones->awardedFreelancer($jobId) : false;

        return [
            'job' => $job,
            'milestones' => $this->milestones->forJob($jobId),
            'submissions' => $this->milestones->submissionsForJob($jobId),
            'is_locked' => $isLocked,
            'has_escrow' => $hasEscrow,
            'escrow' => $escrow !== false ? $escrow : null,
            'freelancer' => $freelancer !== false ? $freelancer : null,
            'reviews' => method_exists($this->milestones, 'reviewsForJob') ? $this->milestones->reviewsForJob($jobId) : [],
            'is_client' => true,
        ];
    }

    public function listForFreelancer(int $freelancerId, int $jobId): array
    {
        $job = $this->milestones->job($jobId);
        if ($job === false) {
            throw new MilestoneException(['form' => 'Job not found.'], 404);
        }
        if (!$this->milestones->eligibleFreelancer($freelancerId)) {
            throw new MilestoneException(['form' => 'Your account cannot access milestones.'], 403);
        }
        if (!$this->milestones->isAwardedFreelancer($jobId, $freelancerId)) {
            throw new MilestoneException(['form' => 'Job not found or you are not the awarded freelancer.'], 404);
        }

        $allowedStatuses = ['awarded', 'in_progress', 'under_review', 'completed', 'disputed', 'closed_disputed', 'cancelled'];
        if (!in_array($job['status'], $allowedStatuses, true)) {
            throw new MilestoneException(['form' => 'Milestones are not available for this job yet.'], 409);
        }

        $hasEscrow = $this->milestones->hasEscrow($jobId);
        $escrow = $hasEscrow ? $this->milestones->escrow($jobId) : false;
        $client = method_exists($this->milestones, 'jobClient') ? $this->milestones->jobClient($jobId) : false;
        if ($client !== false && !empty($client['display_name'])) {
            $job['client_name'] = $client['display_name'];
        }

        return [
            'job' => $job,
            'client' => $client !== false ? $client : null,
            'milestones' => $this->milestones->forJob($jobId),
            'submissions' => $this->milestones->submissionsForJob($jobId),
            'is_locked' => true,
            'has_escrow' => $hasEscrow,
            'escrow' => $escrow !== false ? $escrow : null,
            'reviews' => method_exists($this->milestones, 'reviewsForJob') ? $this->milestones->reviewsForJob($jobId) : [],
            'is_client' => false,
        ];
    }

    public function createMilestone(int $clientId, int $jobId, array $input): int
    {
        $owns = !$this->pdo->inTransaction();
        $started = false;
        try {
            if ($owns) {
                $this->pdo->beginTransaction();
            } else {
                $this->pdo->exec('SAVEPOINT gighub_milestone');
            }
            $started = true;

            // 1. Lock job first
            $job = $this->milestones->job($jobId, true);
            if ($job === false || (int)$job['client_id'] !== $clientId) {
                throw new MilestoneException(['form' => 'Job not found.'], 404);
            }

            // 2. Lock and verify active verified Client
            if (!$this->milestones->eligibleClient($clientId, true)) {
                throw new MilestoneException(['form' => 'Your account cannot manage milestones.'], 403);
            }

            // 3. Verify job is awarded and awaiting funding
            if ($job['status'] !== 'awarded' || $job['awarded_bid_id'] === null) {
                throw new MilestoneException(['form' => 'Milestones can only be defined for awarded jobs awaiting funding.'], 409);
            }

            // 4. Verify no escrow exists (unfunded context)
            if ($this->milestones->hasEscrow($jobId, true)) {
                throw new MilestoneException(['form' => 'Milestones cannot be added once a job is funded.'], 409);
            }

            // 5. Quantity limit check
            $count = $this->milestones->countForJob($jobId);
            if ($count >= self::MAX_MILESTONES) {
                throw new MilestoneException(['form' => 'Maximum of ' . self::MAX_MILESTONES . ' milestones reached for this job.'], 422);
            }

            // 6. Validate input
            $validated = MilestoneValidator::validate($input, (string)$job['deadline']);
            if ($validated['errors'] !== []) {
                throw new MilestoneException($validated['errors'], 422);
            }

            // 7. Get next position under lock and insert
            $position = $this->milestones->nextPosition($jobId);
            $milestoneId = $this->milestones->insert(
                $jobId,
                $validated['data']['title'],
                $validated['data']['description'],
                $validated['data']['due_date'],
                $position
            );

            if ($owns) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT gighub_milestone');
            }

            return $milestoneId;
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                if ($owns) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_milestone');
                    $this->pdo->exec('RELEASE SAVEPOINT gighub_milestone');
                }
            }
            if ($error instanceof MilestoneException) {
                throw $error;
            }
            throw new \RuntimeException('Milestone operation is temporarily unavailable.');
        }
    }

    public function getMilestone(int $clientId, int $jobId, int $id): array
    {
        $job = $this->milestones->job($jobId);
        if ($job === false || (int)$job['client_id'] !== $clientId) {
            throw new MilestoneException(['form' => 'Job not found.'], 404);
        }
        if (!$this->milestones->eligibleClient($clientId)) {
            throw new MilestoneException(['form' => 'Your account cannot access milestones.'], 403);
        }
        if ($job['status'] !== 'awarded' || $job['awarded_bid_id'] === null) {
            throw new MilestoneException(['form' => 'Milestones can only be managed for awarded jobs awaiting funding.'], 409);
        }
        if ($this->milestones->hasEscrow($jobId)) {
            throw new MilestoneException(['form' => 'Milestones cannot be modified once a job is funded.'], 409);
        }

        $milestone = $this->milestones->find($id, $jobId);
        if ($milestone === false) {
            throw new MilestoneException(['form' => 'Milestone not found.'], 404);
        }

        return ['job' => $job, 'milestone' => $milestone];
    }

    public function updateMilestone(int $clientId, int $jobId, int $id, array $input): void
    {
        $owns = !$this->pdo->inTransaction();
        $started = false;
        try {
            if ($owns) {
                $this->pdo->beginTransaction();
            } else {
                $this->pdo->exec('SAVEPOINT gighub_milestone');
            }
            $started = true;

            // 1. Lock job
            $job = $this->milestones->job($jobId, true);
            if ($job === false || (int)$job['client_id'] !== $clientId) {
                throw new MilestoneException(['form' => 'Job not found.'], 404);
            }

            // 2. Lock client
            if (!$this->milestones->eligibleClient($clientId, true)) {
                throw new MilestoneException(['form' => 'Your account cannot manage milestones.'], 403);
            }

            // 3. Status checks
            if ($job['status'] !== 'awarded' || $job['awarded_bid_id'] === null) {
                throw new MilestoneException(['form' => 'Milestones can only be updated for awarded jobs awaiting funding.'], 409);
            }
            if ($this->milestones->hasEscrow($jobId, true)) {
                throw new MilestoneException(['form' => 'Milestones cannot be modified once a job is funded.'], 409);
            }

            // 4. Lock milestone
            $milestone = $this->milestones->find($id, $jobId, true);
            if ($milestone === false) {
                throw new MilestoneException(['form' => 'Milestone not found.'], 404);
            }
            if ($milestone['status'] !== 'pending') {
                throw new MilestoneException(['form' => 'Only pending milestones can be modified.'], 409);
            }

            // 5. Validate input
            $validated = MilestoneValidator::validate($input, (string)$job['deadline']);
            if ($validated['errors'] !== []) {
                throw new MilestoneException($validated['errors'], 422);
            }

            // 6. Update
            $this->milestones->update(
                $id,
                $jobId,
                $validated['data']['title'],
                $validated['data']['description'],
                $validated['data']['due_date']
            );

            if ($owns) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT gighub_milestone');
            }
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                if ($owns) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_milestone');
                    $this->pdo->exec('RELEASE SAVEPOINT gighub_milestone');
                }
            }
            if ($error instanceof MilestoneException) {
                throw $error;
            }
            throw new \RuntimeException('Milestone operation is temporarily unavailable.');
        }
    }

    public function deleteMilestone(int $clientId, int $jobId, int $id): void
    {
        $owns = !$this->pdo->inTransaction();
        $started = false;
        try {
            if ($owns) {
                $this->pdo->beginTransaction();
            } else {
                $this->pdo->exec('SAVEPOINT gighub_milestone');
            }
            $started = true;

            // 1. Lock job
            $job = $this->milestones->job($jobId, true);
            if ($job === false || (int)$job['client_id'] !== $clientId) {
                throw new MilestoneException(['form' => 'Job not found.'], 404);
            }

            // 2. Lock client
            if (!$this->milestones->eligibleClient($clientId, true)) {
                throw new MilestoneException(['form' => 'Your account cannot manage milestones.'], 403);
            }

            // 3. Status checks
            if ($job['status'] !== 'awarded' || $job['awarded_bid_id'] === null) {
                throw new MilestoneException(['form' => 'Milestones can only be deleted for awarded jobs awaiting funding.'], 409);
            }
            if ($this->milestones->hasEscrow($jobId, true)) {
                throw new MilestoneException(['form' => 'Milestones cannot be modified once a job is funded.'], 409);
            }

            // 4. Lock milestone
            $milestone = $this->milestones->find($id, $jobId, true);
            if ($milestone === false) {
                throw new MilestoneException(['form' => 'Milestone not found.'], 404);
            }
            if ($milestone['status'] !== 'pending') {
                throw new MilestoneException(['form' => 'Only pending milestones can be deleted.'], 409);
            }

            $deletedPosition = (int)$milestone['position'];

            // 5. Delete milestone
            $this->milestones->delete($id, $jobId);

            // 6. Safe sequential reindexing: moves subsequent positions down without duplicate collisions
            $this->milestones->shiftPositionsDown($jobId, $deletedPosition);

            if ($owns) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT gighub_milestone');
            }
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) {
                if ($owns) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT gighub_milestone');
                    $this->pdo->exec('RELEASE SAVEPOINT gighub_milestone');
                }
            }
            if ($error instanceof MilestoneException) {
                throw $error;
            }
            throw new \RuntimeException('Milestone operation is temporarily unavailable.');
        }
    }
}
