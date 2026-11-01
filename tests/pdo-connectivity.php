<?php
declare(strict_types=1);

// Explicitly invoked CLI inspection only. Never include this in a web route.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$pdo = null;

try {
    $root = dirname(__DIR__);
    require_once __DIR__ . '/integration-bootstrap.php';
    $pdo = GigHub\Tests\IntegrationDatabase::connection($argv);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'gighub_schema_test') {
        throw new RuntimeException('Unexpected database; inspection stopped.');
    }

    // Session/transaction controls only; no DDL, DML, or global settings changes.
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $state = $pdo->query(
        'SELECT DATABASE() AS selected_database, VERSION() AS server_version, '
        . '@@SESSION.sql_mode AS sql_mode, @@SESSION.time_zone AS time_zone, '
        . '@@SESSION.character_set_client AS charset_client, '
        . '@@SESSION.character_set_connection AS charset_connection, '
        . '@@SESSION.character_set_results AS charset_results'
    )->fetch();
    $native = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false
        || $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === 0;
    $probe = $pdo->prepare('SELECT ? AS prepared_probe');
    $probe->execute(['pdo-validation']);
    $probePassed = $probe->fetchColumn() === 'pdo-validation';
    $actual = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $expected = explode(' ', 'users freelancer_profiles client_profiles email_verification_tokens '
        . 'skills user_skills jobs job_skills bids milestones work_submissions uploaded_files '
        . 'wallets wallet_entries escrows chat_messages notifications disputes cancellation_requests reviews');
    sort($expected);
    sort($actual);
    $strict = count(array_intersect(explode(',', $state['sql_mode']), ['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES'])) > 0;
    $passed = $strict && $state['time_zone'] === '+00:00'
        && $state['charset_client'] === 'utf8mb4'
        && $state['charset_connection'] === 'utf8mb4'
        && $state['charset_results'] === 'utf8mb4'
        && str_contains($state['server_version'], '10.4.32-MariaDB')
        && $native && $probePassed && $actual === $expected;
    $pdo->rollBack();
    echo json_encode([
        'connection' => $passed ? 'PASS' : 'FAIL',
        'selected_database' => $state['selected_database'],
        'server_version' => $state['server_version'],
        'sql_mode' => $state['sql_mode'],
        'strict_mode' => $strict,
        'time_zone' => $state['time_zone'],
        'character_sets' => [$state['charset_client'], $state['charset_connection'], $state['charset_results']],
        'emulated_prepares' => !$native,
        'prepared_select' => $probePassed,
        'table_count' => count($actual),
        'expected_table_set_matches' => $actual === $expected,
        'inspection_transaction' => 'read_only_rolled_back',
        'configuration_file_modified' => false,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($passed ? 0 : 1);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Driver exceptions may contain credentials/paths. Never print their message.
    fwrite(STDERR, "FAIL: PDO validation stopped. Check private configuration and access permissions; no driver details are displayed.\n");
    exit(1);
}
