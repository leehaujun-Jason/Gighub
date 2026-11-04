<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Services\SubmissionException;
use Throwable;

class SubmissionController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly mixed $serviceFactory
    ) {}

    public function createForm(array $query): array
    {
        $access = $this->guard->check('freelancer');
        if (!isset($access['user'])) {
            return $access;
        }

        $jobId = self::id($query['job_id'] ?? null);
        $milestoneId = self::id($query['milestone_id'] ?? null);
        if ($jobId === null || $milestoneId === null) {
            return self::text(400, 'Invalid submission parameters.');
        }

        try {
            $context = ($this->serviceFactory)()->getSubmissionContext((int)$access['user']['id'], $jobId, $milestoneId);
        } catch (SubmissionException $e) {
            Notification::error($e->getMessage());
            return self::text($e->status, $e->getMessage());
        } catch (Throwable) {
            return self::unavailable();
        }

        $errors = $_SESSION['submission_errors'] ?? [];
        $old = $_SESSION['submission_old'] ?? [];
        unset($_SESSION['submission_errors'], $_SESSION['submission_old']);

        $user = $access['user'];
        $job = $context['job'];
        $milestone = $context['milestone'];
        $previousSubmissions = $context['previous_submissions'];
        $navigationUser = $user;
        $logoutToken = Csrf::token('logout');
        $token = Csrf::token('work_submission');
        $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require dirname(__DIR__) . '/Views/freelancer/submissions/create.php';
        $body = (string)ob_get_clean();

        return ['status' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    public function submit(array $input): array
    {
        $access = $this->guard->check('freelancer');
        if (!isset($access['user'])) {
            return $access;
        }

        if (!Csrf::valid($input['csrf_token'] ?? null, 'work_submission')) {
            return self::text(403, 'Your form expired. Reload the page and try again.');
        }

        $jobId = self::id($input['job_id'] ?? null);
        $milestoneId = self::id($input['milestone_id'] ?? null);
        if ($jobId === null || $milestoneId === null) {
            return self::text(400, 'Invalid submission parameters.');
        }

        try {
            ($this->serviceFactory)()->submitMilestoneWork(
                (int)$access['user']['id'],
                $jobId,
                $milestoneId,
                $input
            );
            Csrf::rotate('work_submission');
            Notification::success('Work deliverable submitted successfully. Awaiting client review.');
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/freelancer/jobs/milestones&job_id=' . $jobId];
        } catch (SubmissionException $e) {
            if ($e->status === 422) {
                $_SESSION['submission_errors'] = $e->errors;
                $_SESSION['submission_old'] = $input;
                Notification::error('Please correct the validation errors in your deliverable submission.');
                return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/freelancer/milestones/submit&job_id=' . $jobId . '&milestone_id=' . $milestoneId];
            }
            Notification::error($e->getMessage());
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/freelancer/jobs/milestones&job_id=' . $jobId];
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    public function approve(array $input): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) {
            return $access;
        }

        if (!Csrf::valid($input['csrf_token'] ?? null, 'milestone_review')) {
            return self::text(403, 'Your form expired. Reload the page and try again.');
        }

        $jobId = self::id($input['job_id'] ?? null);
        $milestoneId = self::id($input['milestone_id'] ?? null);
        $submissionId = self::id($input['submission_id'] ?? null);
        if ($jobId === null || $milestoneId === null || $submissionId === null) {
            return self::text(400, 'Invalid milestone review request.');
        }

        try {
            ($this->serviceFactory)()->approveMilestone(
                (int)$access['user']['id'],
                $jobId,
                $milestoneId,
                $submissionId,
                $input
            );
            Csrf::rotate('milestone_review');
            Notification::success('Milestone approved successfully. Checkpoint marked as completed.');
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (SubmissionException $e) {
            Notification::error($e->getMessage());
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    public function requestRevision(array $input): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) {
            return $access;
        }

        if (!Csrf::valid($input['csrf_token'] ?? null, 'milestone_review')) {
            return self::text(403, 'Your form expired. Reload the page and try again.');
        }

        $jobId = self::id($input['job_id'] ?? null);
        $milestoneId = self::id($input['milestone_id'] ?? null);
        $submissionId = self::id($input['submission_id'] ?? null);
        if ($jobId === null || $milestoneId === null || $submissionId === null) {
            return self::text(400, 'Invalid revision request parameters.');
        }

        try {
            ($this->serviceFactory)()->requestMilestoneRevision(
                (int)$access['user']['id'],
                $jobId,
                $milestoneId,
                $submissionId,
                $input
            );
            Csrf::rotate('milestone_review');
            Notification::success('Revision requested successfully. The freelancer can view your feedback.');
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (SubmissionException $e) {
            if ($e->status === 422) {
                $_SESSION['review_errors'] = $e->errors;
                $_SESSION['review_old'] = $input;
                Notification::error($e->errors['feedback'] ?? 'Please provide feedback explaining the requested revisions.');
                return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
            }
            Notification::error($e->getMessage());
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) return $value;
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) && (int)$value <= 4294967295 ? (int)$value : null;
    }

    private static function text(int $status, string $body): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $body];
    }

    private static function unavailable(): array
    {
        return self::text(503, 'Work submission service is temporarily unavailable. Please try again later.');
    }
}
