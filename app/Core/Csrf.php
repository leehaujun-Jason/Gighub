<?php
declare(strict_types=1);
namespace GigHub\Core;

final class Csrf
{
    public static function token(string $purpose = 'registration'): string
    {
        $key = self::key($purpose);
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$key];
    }

    public static function valid(mixed $token, string $purpose = 'registration'): bool
    {
        $key = self::key($purpose);
        return is_string($token) && strlen($token) === 64
            && isset($_SESSION[$key])
            && hash_equals($_SESSION[$key], $token);
    }

    public static function rotate(string $purpose = 'registration'): void
    {
        $_SESSION[self::key($purpose)] = bin2hex(random_bytes(32));
    }

    private static function key(string $purpose): string
    {
        if (!in_array($purpose, ['registration','verification','login','logout','job_posting','freelancer_profile','bidding','bid_award','wallet_credit'], true)) {
            throw new \InvalidArgumentException('Unknown form purpose.');
        }
        return $purpose . '_csrf';
    }
}
