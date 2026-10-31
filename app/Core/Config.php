<?php
declare(strict_types=1);

namespace GigHub\Core;

use RuntimeException;

final class Config
{
    public static function load(string $directory, bool $includeLocal = true): array
    {
        $defaults = require $directory . '/defaults.php';
        if (!is_array($defaults)) {
            throw new RuntimeException('Application configuration unavailable.');
        }
        $localPath = $directory . '/local.php';
        if ($includeLocal && is_file($localPath)) {
            $local = require $localPath;
            if (!is_array($local)) {
                throw new RuntimeException('Application configuration unavailable.');
            }
            return array_replace_recursive($defaults, $local);
        }
        return $defaults;
    }
}
