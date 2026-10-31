<?php
declare(strict_types=1);
namespace GigHub\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['registration_csrf'])) {
            $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['registration_csrf'];
    }

    public static function valid(mixed $token): bool
    {
        return is_string($token) && strlen($token) === 64
            && isset($_SESSION['registration_csrf'])
            && hash_equals($_SESSION['registration_csrf'], $token);
    }

    public static function rotate(): void
    {
        $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
    }
}
