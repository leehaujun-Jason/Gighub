<?php
declare(strict_types=1);

namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Services\ReviewException;

final class ReviewController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly Closure $serviceFactory
    ) {}

    public function createForm(array $query): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $role = $user['role'] ?? '';
        if (!in_array($role, ['client', 'freelancer'], true)) {
            return ['status' => 403, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Only clients and freelancers can submit reviews.'];
        }

        $jobId = self::id($query['job_id'] ?? null);
        if ($jobId === null) {
            return ['status' => 400, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Invalid job ID.'];
        }

        try {
            $service = ($this->serviceFactory)();
            $context = $service->getReviewContext($jobId, (int)$user['id'], $role);

            if ($context['has_reviewed']) {
                $milestoneUrl = ($role === 'client')
                    ? "?route=/client/jobs/milestones&job_id={$jobId}"
                    : "?route=/freelancer/jobs/milestones&job_id={$jobId}";
                Notification::flash('info', 'You have already submitted a review for this project.');
                return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => $milestoneUrl];
            }

            $token = Csrf::token('job_review');
            return $this->renderView('create', [
                'context' => $context,
                'job' => $context['job'],
                'counterpartName' => $context['counterpart_name'],
                'token' => $token,
                'user' => $user,
                'navigationUser' => $user,
                'logoutToken' => Csrf::token('logout'),
                'errors' => [],
                'old' => [],
            ]);
        } catch (ReviewException $e) {
            return ['status' => $e->status, 'type' => 'text/plain; charset=UTF-8', 'body' => $e->getMessage()];
        }
    }

    public function store(array $post): array
    {
        $access = $this->guard->check();
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $role = $user['role'] ?? '';
        if (!in_array($role, ['client', 'freelancer'], true)) {
            return ['status' => 403, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Only clients and freelancers can submit reviews.'];
        }

        if (!Csrf::valid($post['csrf_token'] ?? null, 'job_review')) {
            return ['status' => 403, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Your form session expired. Please reload and try again.'];
        }

        $jobId = self::id($post['job_id'] ?? null);
        if ($jobId === null) {
            return ['status' => 400, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Invalid job ID.'];
        }

        try {
            $service = ($this->serviceFactory)();
            $result = $service->submitReview($jobId, (int)$user['id'], $role, $post);
            Csrf::rotate('job_review');

            $milestoneUrl = ($role === 'client')
                ? "?route=/client/jobs/milestones&job_id={$jobId}"
                : "?route=/freelancer/jobs/milestones&job_id={$jobId}";

            Notification::flash('success', sprintf('Thank you! Your review for %s has been published.', $result['counterpart_name']));
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => $milestoneUrl];
        } catch (ReviewException $e) {
            if ($e->status === 422) {
                try {
                    $context = $service->getReviewContext($jobId, (int)$user['id'], $role);
                    $token = Csrf::token('job_review');
                    return $this->renderView('create', [
                        'context' => $context,
                        'job' => $context['job'],
                        'counterpartName' => $context['counterpart_name'],
                        'token' => $token,
                        'user' => $user,
                        'navigationUser' => $user,
                        'logoutToken' => Csrf::token('logout'),
                        'errors' => $e->errors,
                        'old' => $post,
                    ], 422);
                } catch (\Throwable) {
                    return ['status' => 422, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Validation failed.'];
                }
            }
            return ['status' => $e->status, 'type' => 'text/plain; charset=UTF-8', 'body' => $e->getMessage()];
        }
    }

    private function renderView(string $view, array $data, int $status = 200): array
    {
        if (!isset($data['navigationUser']) && isset($data['user'])) {
            $data['navigationUser'] = $data['user'];
        }
        if (!isset($data['logoutToken'])) {
            $data['logoutToken'] = Csrf::token('logout');
        }
        extract($data, EXTR_SKIP);
        $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        require dirname(__DIR__) . '/Views/reviews/' . $view . '.php';
        $body = (string)ob_get_clean();
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            $parsed = (int)$value;
            return $parsed > 0 ? $parsed : null;
        }
        return null;
    }
}
