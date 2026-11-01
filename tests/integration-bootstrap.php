<?php
declare(strict_types=1);
namespace GigHub\Tests;

use GigHub\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/Core/Database.php';

/** Test-only: no application config, SMTP bootstrap, environment override or fallback. */
final class IntegrationDatabase
{
    private const PRIVATE_CONFIG = 'C:/MariaDB_Recovery/GigHub_Clean_Test/private/gighub-test.local.php';

    public static function assertOptIn(array $arguments): void
    {
        if (PHP_SAPI !== 'cli' || count($arguments) !== 2 || ($arguments[1] ?? '') !== '--run-isolated') {
            throw new RuntimeException('Isolated test database unavailable.');
        }
    }

    public static function validateSettings(#[\SensitiveParameter] array $config): array
    {
        $s = $config['database'] ?? null;
        if (!is_array($s) || ($s['enabled'] ?? null) !== true
            || ($s['host'] ?? null) !== '127.0.0.1' || ($s['port'] ?? null) !== 3309
            || ($s['name'] ?? null) !== 'gighub_schema_test' || ($s['username'] ?? null) !== 'gighub_test'
            || !is_string($s['password'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $s['password'])) {
            throw new RuntimeException('Isolated test database unavailable.');
        }
        return $s;
    }

    public static function clearDatabaseEnvironment(): void
    {
        foreach (array_keys(getenv()) as $key) {
            if (preg_match('/\A(?:DB_|DATABASE_|MYSQL|MARIADB|GIGHUB_|SMTP|MAIL_)/i', $key)) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    public static function connection(array $arguments): PDO
    {
        self::assertOptIn($arguments);
        self::clearDatabaseEnvironment();
        ini_set('error_log', 'C:/MariaDB_Recovery/GigHub_Clean_Test/logs/test-integration-errors.log');
        try {
            if (!is_file(self::PRIVATE_CONFIG) || !is_readable(self::PRIVATE_CONFIG)) { throw new RuntimeException(); }
            ob_start();
            try { $config = require self::PRIVATE_CONFIG; } finally { ob_end_clean(); }
            if (!is_array($config)) { throw new RuntimeException(); }
            $settings = self::validateSettings($config);
            $pdo = Database::forSchemaValidation($settings)->connection();
            unset($config, $settings);
            $identity = $pdo->query('SELECT DATABASE() AS db,@@port AS port,@@datadir AS datadir,VERSION() AS version,CURRENT_USER() AS account')->fetch();
            if ($identity['db'] !== 'gighub_schema_test' || (int) $identity['port'] !== 3309
                || strtolower(str_replace('\\', '/', $identity['datadir'])) !== 'c:/mariadb_recovery/gighub_clean_test/data/'
                || !str_contains($identity['version'], '10.4.32-MariaDB') || $identity['account'] !== 'gighub_test@127.0.0.1') {
                throw new RuntimeException();
            }
            return $pdo;
        } catch (Throwable) {
            throw new RuntimeException('Isolated test database unavailable.');
        }
    }
}
