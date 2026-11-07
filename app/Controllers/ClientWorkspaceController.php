<?php
declare(strict_types=1);

namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use Throwable;

final class ClientWorkspaceController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly Closure $workspaceFactory
    ) {}

    public function index(): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $clientId = (int)$user['id'];

        try {
            /** @var \GigHub\Repositories\ClientWorkspaceRepository $repo */
            $repo = ($this->workspaceFactory)();

            $statistics = $repo->getStatistics($clientId);
            $recentJobs = $repo->getRecentJobs($clientId, 5);

            return $this->render('workspace', [
                'user' => $user,
                'navigationUser' => $user,
                'logoutToken' => Csrf::token('logout'),
                'statistics' => $statistics,
                'recentJobs' => $recentJobs,
            ]);
        } catch (Throwable) {
            return [
                'status' => 503,
                'type' => 'text/plain; charset=UTF-8',
                'body' => 'Client workspace is temporarily unavailable. Please try again later.',
            ];
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
            require dirname(__DIR__) . '/Views/client/' . $view . '.php';
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
}
