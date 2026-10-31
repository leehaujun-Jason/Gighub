<?php
declare(strict_types=1);

namespace GigHub\Core;

use RuntimeException;

final class SecureSession
{
    public static function start(array $settings): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            throw new RuntimeException('Session initialization unavailable.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name($settings['name']);
        $https = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $settings['path'],
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Session initialization unavailable.');
        }
        if (!isset($_SESSION['_initialized'])) {
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Session initialization unavailable.');
            }
            $_SESSION['_initialized'] = true;
        }
    }
}
