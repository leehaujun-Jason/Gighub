<?php
declare(strict_types=1);

namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Services\DisputeException;
use Throwable;

final class AdminDisputeController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly Closure $serviceFactory
    ) {}

    /**
     * Admin dashboard dispute queue.
     */
    public function index(array $query = []): array
    {
        $access = $this->guard->check('admin');
        if (!isset($access['user'])) {
            return $access;
        }
        if (($access['user']['status'] ?? '') !== 'active') {
            return ['status' => 403, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Account is suspended or inactive.'];
        }

        $user = $access['user'];
        $status = ($query['status'] ?? 'open') === 'resolved' ? 'resolved' : 'open';

        try {
            $service = ($this->serviceFactory)();
            $disputes = $service->getAdminQueue((int)$user['id'], $status);
            $openCount = $service->getOpenDisputeCount((int)$user['id']);

            return $this->render('dashboard', [
                'user' => $user,
                'navigationUser' => $user,
                'logoutToken' => Csrf::token('logout'),
                'disputes' => $disputes,
                'currentStatus' => $status,
                'openCount' => $openCount,
            ]);
        } catch (Throwable) {
            return [
                'status' => 503,
                'type' => 'text/plain; charset=UTF-8',
                'body' => 'Admin dashboard is temporarily unavailable.',
            ];
        }
    }

    /**
     * Admin Dispute Dossier detail view.
     */
    public function show(array $query = []): array
    {
        $access = $this->guard->check('admin');
        if (!isset($access['user'])) {
            return $access;
        }
        if (($access['user']['status'] ?? '') !== 'active') {
            return ['status' => 403, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Account is suspended or inactive.'];
        }

        $disputeId = self::id($query['id'] ?? null);
        if ($disputeId === null) {
            return ['status' => 400, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Invalid dispute ID.'];
        }

        $user = $access['user'];
        $chatPage = max(1, (int)($query['chat_page'] ?? 1));

        try {
            $service = ($this->serviceFactory)();
            $dossier = $service->getDisputeDossier((int)$user['id'], $disputeId, $chatPage);

            return $this->render('dispute-detail', [
                'user' => $user,
                'navigationUser' => $user,
                'logoutToken' => Csrf::token('logout'),
                'decisionToken' => Csrf::token('admin_decision'),
                'dossier' => $dossier,
            ]);
        } catch (DisputeException $e) {
            return ['status' => $e->status, 'type' => 'text/plain; charset=UTF-8', 'body' => $e->getMessage()];
        } catch (Throwable) {
            return [
                'status' => 503,
                'type' => 'text/plain; charset=UTF-8',
                'body' => 'Dispute dossier is temporarily unavailable.',
            ];
        }
    }

    /**
     * Resolve a dispute with a binding binary decision.
     */
    public function resolve(array $post): array
    {
        $access = $this->guard->check('admin');
        if (!isset($access['user'])) {
            return $access;
        }
        if (($access['user']['status'] ?? '') !== 'active') {
            return ['status' => 403, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Account is suspended or inactive.'];
        }

        $disputeId = self::id($post['dispute_id'] ?? null);
        if ($disputeId === null) {
            return ['status' => 400, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Invalid dispute ID.'];
        }

        $redirectUrl = '?route=/admin/disputes/show&id=' . $disputeId;

        if (!Csrf::valid($post['csrf_token'] ?? null, 'admin_decision')) {
            Notification::error('Your session expired. Please reload and try again.');
            return self::redirect($redirectUrl);
        }

        $decision = (string)($post['decision'] ?? '');
        $decisionNote = (string)($post['decision_note'] ?? '');

        try {
            $service = ($this->serviceFactory)();
            $result = $service->resolveDispute((int)$access['user']['id'], $disputeId, $decision, $decisionNote);
            Csrf::rotate('admin_decision');

            $decisionLabel = ($decision === 'refund_client') ? 'Full refund to client' : 'Full payout to freelancer';
            Notification::success('Dispute #' . $disputeId . ' has been resolved (' . $decisionLabel . '). Financial settlement executed.');
            return self::redirect($redirectUrl);
        } catch (DisputeException $e) {
            Csrf::rotate('admin_decision');
            $msg = $e->errors['decision'] ?? $e->errors['decision_note'] ?? $e->errors['form'] ?? $e->getMessage();
            Notification::error($msg);
            return self::redirect($redirectUrl);
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    private function render(string $view, array $data): array
    {
        $escape = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $assetVersion = static function (string $filename): string {
            $path = dirname(__DIR__, 2) . '/public/assets/' . $filename;
            return is_file($path) ? substr((string)hash_file('sha256', $path), 0, 12) : '1';
        };

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require dirname(__DIR__) . '/Views/admin/' . $view . '.php';
            $body = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return [
            'status' => 200,
            'type' => 'text/html; charset=UTF-8',
            'body' => $body,
        ];
    }

    private static function redirect(string $url): array
    {
        return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => $url];
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
