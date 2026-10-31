<?php
declare(strict_types=1);
namespace GigHub\Core;

final class RegistrationThrottle
{
    private const WINDOW = 600;
    private const LIMIT = 5;

    public static function consume(?int $now = null): array
    {
        $now ??= time();
        $attempts = $_SESSION['registration_attempts'] ?? [];
        $attempts = is_array($attempts) ? array_values(array_filter($attempts,
            static fn (mixed $at): bool => is_int($at) && $at > $now - self::WINDOW && $at <= $now)) : [];
        $_SESSION['registration_attempts'] = $attempts;
        if (count($attempts) >= self::LIMIT) {
            return ['allowed' => false, 'retry_after' => max(1, $attempts[0] + self::WINDOW - $now)];
        }
        $_SESSION['registration_attempts'][] = $now;
        return ['allowed' => true, 'retry_after' => 0];
    }
}
