<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Services\EscrowException;
use Throwable;

class EscrowController
{
    private ?array $navigationUser = null;

    public function __construct(
        private readonly AuthGuard $guard,
        private readonly mixed $serviceFactory
    ) {}

    public function preview(array $query): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) return $access;
        $this->navigationUser = $access['user'];

        $jobId = self::id($query['job_id'] ?? null);
        if ($jobId === null) return self::text(400, 'Invalid job ID.');

        try {
            $data = ($this->serviceFactory)()->getFundingDetails((int)$access['user']['id'], $jobId);
        } catch (EscrowException $e) {
            if ($e->getCode() === 409) {
                Notification::info($e->getMessage());
                return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/client/jobs/show&id=' . $jobId];
            }
            if ($e->getCode() === 422) {
                Notification::warning($e->getMessage());
                return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/client/jobs/milestones&job_id=' . $jobId];
            }
            return self::text($e->getCode(), $e->getMessage());
        } catch (Throwable) {
            return self::unavailable();
        }

        $user = $access['user'];
        $job = $data['job'];
        $bid = $data['bid'];
        $milestones = $data['milestones'];
        $wallet = $data['wallet'];
        $balance = $data['balance'];
        $amount = $data['amount'];
        $remaining = $data['remaining'];
        $isSufficient = $data['is_sufficient'];

        $navigationUser = $user;
        $logoutToken = Csrf::token('logout');
        $token = Csrf::token('escrow_fund');
        $error = $_SESSION['escrow_fund_error'] ?? null;
        unset($_SESSION['escrow_fund_error']);

        $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require dirname(__DIR__) . '/Views/client/escrow/fund.php';
        $body = (string)ob_get_clean();

        return ['status' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    public function confirm(array $input): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) return $access;
        $this->navigationUser = $access['user'];

        if (!Csrf::valid($input['csrf_token'] ?? null, 'escrow_fund')) {
            return self::text(403, 'Your form expired. Reload the page and try again.');
        }

        $jobId = self::id($input['job_id'] ?? null);
        if ($jobId === null) return self::text(400, 'Invalid job ID.');

        try {
            $result = ($this->serviceFactory)()->fundJob((int)$access['user']['id'], $jobId);
            Csrf::rotate('escrow_fund');
            $msg = 'Job funded successfully. RM' . number_format((float)$result['amount'], 2) . ' has been locked in escrow and work has started.';
            $_SESSION['job_posting_notice'] = $msg;
            Notification::success($msg);
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/client/jobs/show&id=' . $jobId];
        } catch (EscrowException $e) {
            Csrf::rotate('escrow_fund');
            $_SESSION['escrow_fund_error'] = $e->getMessage();
            Notification::error($e->getMessage());
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/client/jobs/fund&job_id=' . $jobId];
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
        return self::text(503, 'Escrow service is temporarily unavailable. Please try again later.');
    }
}
