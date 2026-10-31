<?php
declare(strict_types=1);

namespace GigHub\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes[$path] = $handler;
    }

    public function dispatch(string $method, mixed $path): array
    {
        if (!is_string($path) || strlen($path) > 100) {
            return self::text(400, 'Invalid request.');
        }
        if ($method !== 'GET') {
            return self::text(405, 'Method not allowed.');
        }
        if (!isset($this->routes[$path])) {
            return self::text(404, 'Page not found.');
        }
        return ($this->routes[$path])();
    }

    private static function text(int $status, string $body): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $body];
    }
}
