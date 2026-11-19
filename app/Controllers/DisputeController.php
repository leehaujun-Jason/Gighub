<?php
declare(strict_types=1);

namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Services\DisputeException;
use Throwable;

final class DisputeController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly Closure $serviceFactory
    ) {}

    /**
     * Submit a mutual cancellation request (Client or Freelancer).
     */
    public function requestCancellation(array $post): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $role = $user['role'] ?? '';
        if (!in_array($role, ['client', 'freelancer'], true)) {
            return self::text(403, 'Only clients and freelancers can request contract cancellation.');
        }

        $jobId = self::id($post['job_id'] ?? null);
        if ($jobId === null) {
            return self::text(400, 'Invalid job ID.');
        }

        $redirectUrl = ($role === 'client')
            ? '?route=/client/jobs/milestones&job_id=' . $jobId
            : '?route=/freelancer/jobs/milestones&job_id=' . $jobId;

        if (!Csrf::valid($post['csrf_token'] ?? null, 'cancellation_action')) {
            Notification::error('Your session expired. Please reload the page and try again.');
            return self::redirect($redirectUrl);
        }

        $reason = (string)($post['reason'] ?? '');

        try {
            $service = ($this->serviceFactory)();
            $service->requestCancellation((int)$user['id'], $jobId, $reason);
            Csrf::rotate('cancellation_action');

            Notification::success('Mutual cancellation request submitted. Awaiting counterpart response.');
            return self::redirect($redirectUrl);
        } catch (DisputeException $e) {
            Csrf::rotate('cancellation_action');
            $msg = $e->errors['reason'] ?? $e->errors['form'] ?? $e->getMessage();
            $_SESSION['resolution_error'][$jobId] = ['dialog' => 'cancel-dialog', 'message' => $msg, 'reason' => $reason];
            return self::redirect($redirectUrl);
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Respond to a mutual cancellation request (accept or decline).
     */
    public function respondCancellation(array $post): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $role = $user['role'] ?? '';
        if (!in_array($role, ['client', 'freelancer'], true)) {
            return self::text(403, 'Only clients and freelancers can respond to contract cancellation.');
        }

        $jobId = self::id($post['job_id'] ?? null);
        if ($jobId === null) {
            return self::text(400, 'Invalid job ID.');
        }

        $redirectUrl = ($role === 'client')
            ? '?route=/client/jobs/milestones&job_id=' . $jobId
            : '?route=/freelancer/jobs/milestones&job_id=' . $jobId;

        if (!Csrf::valid($post['csrf_token'] ?? null, 'cancellation_action')) {
            Notification::error('Your session expired. Please reload the page and try again.');
            return self::redirect($redirectUrl);
        }

        $action = (string)($post['action'] ?? '');

        try {
            $service = ($this->serviceFactory)();
            $result = $service->respondCancellation((int)$user['id'], $jobId, $action);
            Csrf::rotate('cancellation_action');

            if ($result['status'] === 'accepted') {
                Notification::success('Mutual cancellation accepted. Escrow has been refunded to the client wallet and the project is closed.');
            } else {
                Notification::info('Mutual cancellation declined. Work on the contract remains active.');
            }

            return self::redirect($redirectUrl);
        } catch (DisputeException $e) {
            Csrf::rotate('cancellation_action');
            $msg = $e->errors['action'] ?? $e->errors['form'] ?? $e->getMessage();
            Notification::error($msg);
            return self::redirect($redirectUrl);
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Escalate contract to formal dispute (Client or Freelancer).
     */
    public function raiseDispute(array $post, array $uploads = []): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $role = $user['role'] ?? '';
        if (!in_array($role, ['client', 'freelancer'], true)) {
            return self::text(403, 'Only clients and freelancers can raise disputes.');
        }

        $jobId = self::id($post['job_id'] ?? null);
        if ($jobId === null) {
            return self::text(400, 'Invalid job ID.');
        }

        $redirectUrl = ($role === 'client')
            ? '?route=/client/jobs/milestones&job_id=' . $jobId
            : '?route=/freelancer/jobs/milestones&job_id=' . $jobId;

        if (!Csrf::valid($post['csrf_token'] ?? null, 'dispute_action')) {
            Notification::error('Your session expired. Please reload the page and try again.');
            return self::redirect($redirectUrl);
        }

        $reason = (string)($post['reason'] ?? '');

        try {
            $service = ($this->serviceFactory)();
            $files = [];
            $upload = $uploads['evidence'] ?? [];
            if ($upload !== []) {
                if (!is_array($upload['error'] ?? null) || count($upload['error']) > 6) {
                    throw new DisputeException(['reason' => 'Choose at most six evidence images.'], 422);
                }
                foreach ($upload['error'] as $i => $error) {
                    if ($error === UPLOAD_ERR_NO_FILE) continue;
                    $files[] = ['error'=>$error, 'name'=>$upload['name'][$i] ?? '', 'tmp_name'=>$upload['tmp_name'][$i] ?? ''];
                }
            }
            $service->raiseDispute((int)$user['id'], $jobId, $reason, $files);
            Csrf::rotate('dispute_action');

            Notification::success('Formal dispute opened. Contract activities are frozen pending administrative mediation.');
            return self::redirect($redirectUrl);
        } catch (DisputeException $e) {
            Csrf::rotate('dispute_action');
            $msg = $e->errors['reason'] ?? $e->errors['form'] ?? $e->getMessage();
            $_SESSION['resolution_error'][$jobId] = ['dialog' => 'dispute-dialog', 'message' => $msg, 'reason' => $reason];
            return self::redirect($redirectUrl);
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Submit counterpart written response to an open dispute.
     */
    public function submitDisputeResponse(array $post, array $uploads = []): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $role = $user['role'] ?? '';
        if (!in_array($role, ['client', 'freelancer'], true)) {
            return self::text(403, 'Only clients and freelancers can submit dispute responses.');
        }

        $jobId = self::id($post['job_id'] ?? null);
        $disputeId = self::id($post['dispute_id'] ?? null);
        if ($jobId === null || $disputeId === null) {
            return self::text(400, 'Invalid dispute or job identifier.');
        }

        $redirectUrl = ($role === 'client')
            ? '?route=/client/jobs/milestones&job_id=' . $jobId
            : '?route=/freelancer/jobs/milestones&job_id=' . $jobId;

        if (!Csrf::valid($post['csrf_token'] ?? null, 'dispute_action')) {
            Notification::error('Your session expired. Please reload the page and try again.');
            return self::redirect($redirectUrl);
        }

        $response = (string)($post['response'] ?? '');

        try {
            $service = ($this->serviceFactory)();
            $files = self::evidenceUploads($uploads);
            $result = $service->submitDisputeResponse((int)$user['id'], $disputeId, $response, $files);
            Csrf::rotate('dispute_action');

            // Authoritative redirect using Job ID returned by service; fail safely if contract violated
            $targetJobId = (isset($result['job_id']) && is_int($result['job_id']) && $result['job_id'] > 0)
                ? $result['job_id']
                : null;

            if ($targetJobId === null) {
                return self::unavailable();
            }

            $targetUrl = ($role === 'client')
                ? '?route=/client/jobs/milestones&job_id=' . $targetJobId
                : '?route=/freelancer/jobs/milestones&job_id=' . $targetJobId;

            Notification::success('Your response and any supporting images have been recorded for the mediating administrator.');
            return self::redirect($targetUrl);
        } catch (DisputeException $e) {
            Csrf::rotate('dispute_action');
            $msg = $e->errors['response'] ?? $e->errors['form'] ?? $e->getMessage();
            Notification::error($msg);
            return self::redirect($redirectUrl);
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    private static function evidenceUploads(array $uploads): array
    {
        $upload = $uploads['evidence'] ?? [];
        if ($upload === []) return [];
        if (!is_array($upload) || !is_array($upload['error'] ?? null) || count($upload['error']) > 6) {
            throw new DisputeException(['response' => 'Choose at most six evidence images.'], 422);
        }
        $files = [];
        foreach ($upload['error'] as $i => $error) {
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            $files[] = ['error' => $error, 'name' => $upload['name'][$i] ?? '', 'tmp_name' => $upload['tmp_name'][$i] ?? ''];
        }
        return $files;
    }

    public function evidence(array $query): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) return $access;
        $id = self::id($query['dispute_id'] ?? null);
        $imageId = self::id($query['id'] ?? null);
        if (!$id || !$imageId) return self::text(404, 'Evidence not found.');
        try {
            $file = ($this->serviceFactory)()->evidenceImage($access['user'], $id, $imageId);
            if ($file === null) return self::text(404, 'Evidence not found.');
            return ['status'=>200, 'type'=>'image/webp', 'body'=>$file, 'content_disposition'=>'inline'];
        } catch (Throwable) { return self::text(404, 'Evidence not found.'); }
    }

    private static function redirect(string $url): array
    {
        return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => $url];
    }

    private static function text(int $status, string $message): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $message];
    }

    private static function unavailable(): array
    {
        return ['status' => 503, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Service temporarily unavailable. Please try again.'];
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) return $value;
        return is_string($value) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) && (int)$value <= 4294967295 ? (int)$value : null;
    }
}
