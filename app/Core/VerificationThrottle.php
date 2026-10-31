<?php
declare(strict_types=1);
namespace GigHub\Core;
final class VerificationThrottle
{
    public static function consume(?int $now = null): array
    {
        $now ??= time();
        $attempts = $_SESSION['verification_attempts'] ?? [];
        $attempts = is_array($attempts) ? array_values(array_filter($attempts, static fn (mixed $at): bool => is_int($at) && $at > $now - 600 && $at <= $now)) : [];
        $_SESSION['verification_attempts'] = $attempts;
        if (count($attempts) >= 10) { return ['allowed' => false, 'retry_after' => max(1, min($attempts) + 600 - $now)]; }
        $_SESSION['verification_attempts'][] = $now;
        return ['allowed' => true, 'retry_after' => 0];
    }
}
