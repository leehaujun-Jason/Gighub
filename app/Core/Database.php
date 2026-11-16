<?php
declare(strict_types=1);

namespace GigHub\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly array $settings,
        private readonly bool $allowSchemaValidation = false
    )
    {
        // Lazy: constructing this class never opens a connection.
    }

    public static function forSchemaValidation(array $settings): self
    {
        if (PHP_SAPI !== 'cli' || ($settings['name'] ?? null) !== 'gighub_schema_test') {
            throw new RuntimeException('Database connection unavailable.');
        }
        // Explicit CLI opt-in only; do not change local.php or application defaults.
        $settings['enabled'] = true;
        return new self($settings, true);
    }

    public function connection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $expectedDatabase = $this->validatedDatabaseName();
        $s = $this->settings;

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $s['host'], $s['port'], $s['name']);
        try {
            $pdo = new PDO($dsn, $s['username'], $s['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
                throw new RuntimeException('Database connection unavailable.');
            }
            // Constants only: these affect this connection, never global settings.
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            $this->pdo = $pdo;
            return $pdo;
        } catch (PDOException $error) {
            // Never log DSN, credentials, SQL, or driver exception messages.
            error_log('[GigHub] Database connection or session initialization failed.');
            throw new RuntimeException('Database connection unavailable.');
        }
    }
    private function validatedDatabaseName(): string
    {
        $s = $this->settings;
        if (($s['enabled'] ?? false) !== true
            || !is_string($s['host'] ?? null)
            || !preg_match('/\A[a-zA-Z0-9.:-]+\z/', $s['host'])
            || !is_string($s['name'] ?? null)
            || !preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $s['name'])
            || ($this->allowSchemaValidation
                ? PHP_SAPI !== 'cli' || $s['name'] !== 'gighub_schema_test'
                : strcasecmp($s['name'], 'gighub_schema_test') === 0)
            || !is_string($s['username'] ?? null) || trim($s['username']) === ''
            || !is_string($s['password'] ?? null)
            || !is_int($s['port'] ?? null) || $s['port'] < 1 || $s['port'] > 65535) {
            throw new RuntimeException('Database connection unavailable.');
        }
        return $s['name'];
    }
}
