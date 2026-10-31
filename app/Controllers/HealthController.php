<?php
declare(strict_types=1);

namespace GigHub\Controllers;

final class HealthController
{
    public function show(string $environment, string $remoteAddress): array
    {
        if ($environment !== 'local' || !in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
            return ['status' => 404, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Page not found.'];
        }
        return [
            'status' => 200,
            'type' => 'application/json; charset=UTF-8',
            'body' => json_encode([
                'application' => 'ready',
                'session' => session_status() === PHP_SESSION_ACTIVE ? 'ready' : 'not_started',
                'database' => 'not_checked',
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
