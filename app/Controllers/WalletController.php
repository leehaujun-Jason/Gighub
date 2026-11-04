<?php
declare(strict_types=1);
namespace GigHub\Controllers;
use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Services\WalletException;
use Throwable;

final class WalletController
{
    public function __construct(private readonly AuthGuard $guard, private readonly Closure $serviceFactory) {}

    public function show(array $query = []): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) {
            return $access;
        }

        $page = max(1, (int)($query['page'] ?? 1));
        try {
            $data = ($this->serviceFactory)()->getWalletOverview((int)$access['user']['id'], $page);
        } catch (WalletException $e) {
            return self::text($e->status, $e->errors['form'] ?? 'Wallet could not be loaded.');
        } catch (Throwable) {
            return self::text(503, 'Wallet service is temporarily unavailable. Please try again later.');
        }

        $notice = $_SESSION['wallet_notice'] ?? null;
        unset($_SESSION['wallet_notice']);

        $user = $access['user'];
        $wallet = $data['wallet'];
        $entries = $data['entries'];
        $hasDemoCredit = $data['has_demo_credit'];
        $hasNext = $data['has_next'];
        $navigationUser = $user;
        $logoutToken = Csrf::token('logout');
        $token = Csrf::token('wallet_credit');
        $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        ob_start();
        require dirname(__DIR__) . '/Views/client/wallet/show.php';
        $body = (string)ob_get_clean();

        return ['status' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    public function claimDemoCredit(array $input): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) {
            return $access;
        }

        if (!Csrf::valid($input['csrf_token'] ?? null, 'wallet_credit')) {
            return self::text(403, 'Your form expired. Reload the page and try again.');
        }

        try {
            ($this->serviceFactory)()->claimDemoCredit((int)$access['user']['id']);
            Csrf::rotate('wallet_credit');
            $_SESSION['wallet_notice'] = 'Demo credit of RM10,000.00 successfully added to your wallet.';
            return self::text(303, '') + ['location' => '?route=/client/wallet'];
        } catch (WalletException $e) {
            return self::text($e->status, $e->errors['form'] ?? 'Demo credit could not be claimed.');
        } catch (Throwable) {
            return self::text(503, 'Wallet service is temporarily unavailable. Please try again later.');
        }
    }

    private static function text(int $status, string $body): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $body];
    }
}
