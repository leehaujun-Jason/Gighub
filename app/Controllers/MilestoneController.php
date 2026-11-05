<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Services\BidValidator;
use GigHub\Services\MilestoneException;
use Throwable;

class MilestoneController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly mixed $serviceFactory
    ) {}

    public function index(array $query): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) return $access;

        $jobId = self::id($query['job_id'] ?? $query['id'] ?? null);
        if ($jobId === null) return self::text(400, 'Invalid job identifier.');

        try {
            $data = ($this->serviceFactory)()->listForJob((int)$access['user']['id'], $jobId);
        } catch (MilestoneException $e) {
            return self::text($e->status, $e->errors['form'] ?? 'Milestones could not be loaded.');
        } catch (Throwable) {
            return self::text(503, 'Milestones service is temporarily unavailable. Please try again later.');
        }

        $notice = $_SESSION['milestone_notice'] ?? null;
        unset($_SESSION['milestone_notice']);
        $errors = $_SESSION['milestone_errors'] ?? [];
        unset($_SESSION['milestone_errors']);
        $old = $_SESSION['milestone_old'] ?? [];
        unset($_SESSION['milestone_old']);
        $reviewErrors = $_SESSION['review_errors'] ?? [];
        unset($_SESSION['review_errors']);
        $reviewOld = $_SESSION['review_old'] ?? [];
        unset($_SESSION['review_old']);
        $finalReviewErrors = $_SESSION['final_review_errors'] ?? [];
        unset($_SESSION['final_review_errors']);
        $finalReviewOld = $_SESSION['final_review_old'] ?? [];
        unset($_SESSION['final_review_old']);

        $user = $access['user'];
        $job = $data['job'];
        $escrow = $data['escrow'] ?? null;
        $freelancer = $data['freelancer'] ?? null;
        $milestones = $data['milestones'];
        $submissions = $data['submissions'] ?? [];
        $isLocked = $data['is_locked'] ?? false;
        $hasEscrow = $data['has_escrow'] ?? false;
        $isClient = true;
        $navigationUser = $user;
        $logoutToken = Csrf::token('logout');
        $token = Csrf::token('job_milestone');
        $reviewToken = Csrf::token('milestone_review');
        $finalReviewToken = Csrf::token('final_review');
        $escrowReleaseToken = Csrf::token('escrow_release');
        $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require dirname(__DIR__) . '/Views/client/milestones/index.php';
        $body = (string)ob_get_clean();

        return ['status' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    public function freelancerIndex(array $query): array
    {
        $access = $this->guard->check('freelancer');
        if (!isset($access['user'])) return $access;

        $jobId = self::id($query['job_id'] ?? $query['id'] ?? null);
        if ($jobId === null) return self::text(400, 'Invalid job identifier.');

        try {
            $data = ($this->serviceFactory)()->listForFreelancer((int)$access['user']['id'], $jobId);
        } catch (MilestoneException $e) {
            return self::text($e->status, $e->errors['form'] ?? 'Milestones could not be loaded.');
        } catch (Throwable) {
            return self::text(503, 'Milestones service is temporarily unavailable. Please try again later.');
        }

        $notice = null;
        $errors = [];
        $old = [];
        $reviewErrors = [];
        $reviewOld = [];
        $reviewToken = '';
        $finalReviewErrors = [];
        $finalReviewOld = [];
        $finalReviewToken = '';
        $escrowReleaseToken = '';

        $user = $access['user'];
        $job = $data['job'];
        $escrow = $data['escrow'] ?? null;
        $milestones = $data['milestones'];
        $submissions = $data['submissions'] ?? [];
        $isLocked = true;
        $hasEscrow = $data['has_escrow'] ?? true;
        $isClient = false;
        $navigationUser = $user;
        $logoutToken = Csrf::token('logout');
        $token = Csrf::token('job_milestone');
        $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require dirname(__DIR__) . '/Views/client/milestones/index.php';
        $body = (string)ob_get_clean();

        return ['status' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    public function create(array $input): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) return $access;

        if (!Csrf::valid($input['csrf_token'] ?? null, 'job_milestone')) {
            return self::text(403, 'Your form expired. Reload the page and try again.');
        }

        $jobId = self::id($input['job_id'] ?? null);
        if ($jobId === null) return self::text(400, 'Invalid job identifier.');

        try {
            ($this->serviceFactory)()->createMilestone((int)$access['user']['id'], $jobId, $input);
            Csrf::rotate('job_milestone');
            $_SESSION['milestone_notice'] = 'Milestone added successfully.';
            Notification::success('Milestone added successfully.');
            return self::text(303, '') + ['location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (MilestoneException $e) {
            if ($e->status === 422) {
                $_SESSION['milestone_errors'] = $e->errors;
                $_SESSION['milestone_old'] = $input;
                Notification::error('Please correct the validation errors in the milestone form.');
                return self::text(303, '') + ['location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
            }
            Notification::error($e->errors['form'] ?? 'Milestone could not be created.');
            return self::text(303, '') + ['location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (Throwable) {
            return self::text(503, 'Milestone operation is temporarily unavailable. Please try again later.');
        }
    }

    public function edit(array $query): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) return $access;

        $jobId = self::id($query['job_id'] ?? null);
        $id = self::id($query['id'] ?? null);
        if ($jobId === null || $id === null) return self::text(400, 'Invalid milestone request.');

        try {
            $data = ($this->serviceFactory)()->getMilestone((int)$access['user']['id'], $jobId, $id);
        } catch (MilestoneException $e) {
            return self::text($e->status, $e->errors['form'] ?? 'Milestone could not be loaded.');
        } catch (Throwable) {
            return self::text(503, 'Milestones service is temporarily unavailable. Please try again later.');
        }

        $errors = $_SESSION['milestone_edit_errors'] ?? [];
        unset($_SESSION['milestone_edit_errors']);
        $old = $_SESSION['milestone_edit_old'] ?? [];
        unset($_SESSION['milestone_edit_old']);

        $user = $access['user'];
        $job = $data['job'];
        $milestone = $data['milestone'];
        $navigationUser = $user;
        $logoutToken = Csrf::token('logout');
        $token = Csrf::token('job_milestone');
        $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require dirname(__DIR__) . '/Views/client/milestones/edit.php';
        $body = (string)ob_get_clean();

        return ['status' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    public function update(array $input): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) return $access;

        if (!Csrf::valid($input['csrf_token'] ?? null, 'job_milestone')) {
            return self::text(403, 'Your form expired. Reload the page and try again.');
        }

        $jobId = self::id($input['job_id'] ?? null);
        $id = self::id($input['id'] ?? null);
        if ($jobId === null || $id === null) return self::text(400, 'Invalid milestone request.');

        try {
            ($this->serviceFactory)()->updateMilestone((int)$access['user']['id'], $jobId, $id, $input);
            Csrf::rotate('job_milestone');
            $_SESSION['milestone_notice'] = 'Milestone updated successfully.';
            Notification::success('Milestone updated successfully.');
            return self::text(303, '') + ['location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (MilestoneException $e) {
            if ($e->status === 422) {
                $_SESSION['milestone_edit_errors'] = $e->errors;
                $_SESSION['milestone_edit_old'] = $input;
                Notification::error('Please correct the validation errors in the milestone form.');
                return self::text(303, '') + ['location' => '?route=/client/jobs/milestones/edit&job_id=' . $jobId . '&id=' . $id];
            }
            Notification::error($e->errors['form'] ?? 'Milestone could not be updated.');
            return self::text(303, '') + ['location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (Throwable) {
            return self::text(503, 'Milestone operation is temporarily unavailable. Please try again later.');
        }
    }

    public function delete(array $input): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) return $access;

        if (!Csrf::valid($input['csrf_token'] ?? null, 'job_milestone')) {
            return self::text(403, 'Your form expired. Reload the page and try again.');
        }

        $jobId = self::id($input['job_id'] ?? null);
        $id = self::id($input['id'] ?? null);
        if ($jobId === null || $id === null) return self::text(400, 'Invalid milestone request.');

        try {
            ($this->serviceFactory)()->deleteMilestone((int)$access['user']['id'], $jobId, $id);
            Csrf::rotate('job_milestone');
            $_SESSION['milestone_notice'] = 'Milestone deleted successfully.';
            Notification::success('Milestone deleted successfully.');
            return self::text(303, '') + ['location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (MilestoneException $e) {
            Notification::error($e->errors['form'] ?? 'Milestone could not be deleted.');
            return self::text(303, '') + ['location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
        } catch (Throwable) {
            return self::text(503, 'Milestone operation is temporarily unavailable. Please try again later.');
        }
    }

    private static function text(int $status, string $body): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $body];
    }

    private static function id(mixed $value): ?int
    {
        return BidValidator::id(is_int($value) ? (string)$value : (is_string($value) ? $value : null));
    }
}
