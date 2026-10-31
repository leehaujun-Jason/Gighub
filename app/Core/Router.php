<?php
declare(strict_types=1);

namespace GigHub\Core;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes[$path]['GET'] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes[$path]['POST'] = $handler;
    }

    public function dispatch(string $method, mixed $path): array
    {
        if (!is_string($path) || strlen($path) > 100) {
            return self::text(400, 'Invalid request.');
        }
        if (!isset($this->routes[$path])) {
            return self::text(404, 'Page not found.');
        }
        if (!isset($this->routes[$path][$method])) {
            return self::text(405, 'Method not allowed.') + ['allow' => implode(', ', array_keys($this->routes[$path]))];
        }
        return ($this->routes[$path][$method])();
    }

    private static function text(int $status, string $body): array
    {
        return ['status' => $status, 'type' => 'text/plain; charset=UTF-8', 'body' => $body];
    }
}
