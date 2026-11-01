<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$count = 0;
$check = static function (bool $ok, string $label) use (&$count): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$count;
};
$check(is_file(__DIR__ . '/integration-bootstrap.php'), 'isolated bootstrap exists');
require __DIR__ . '/integration-bootstrap.php';
use GigHub\Tests\IntegrationDatabase;
$valid = ['enabled' => true, 'host' => '127.0.0.1', 'port' => 3309, 'name' => 'gighub_schema_test', 'username' => 'gighub_test', 'password' => str_repeat('a', 64)];
$reject = static function (callable $operation, string $label) use ($check): void {
    try { $operation(); } catch (RuntimeException $e) {
        $check($e->getMessage() === 'Isolated test database unavailable.' && $e->getPrevious() === null, $label); return;
    }
    $check(false, $label);
};
$check(IntegrationDatabase::validateSettings(['database' => $valid]) === $valid, 'valid explicit settings accepted');
foreach (['host' => ['localhost', '127.0.0.1;dbname=gighub_dev', ''], 'port' => [3306,3308,'3309'],
    'name' => ['gighub_dev','mysql','other',''], 'username' => ['root','gighub_app',''],
    'password' => ['',null], 'enabled' => [false,'true']] as $field => $values) {
    foreach ($values as $value) { $reject(static fn () => IntegrationDatabase::validateSettings(['database' => array_replace($valid,[$field => $value])]), 'reject unsafe ' . $field); }
}
foreach (array_keys($valid) as $field) {
    $missing = $valid; unset($missing[$field]);
    $reject(static fn () => IntegrationDatabase::validateSettings(['database' => $missing]), 'missing ' . $field);
}
$reject(static fn () => IntegrationDatabase::validateSettings([]), 'no fallback for absent settings');
foreach ([['test.php'],['test.php','--run-synthetic'],['test.php','--run-isolated','gighub_dev']] as $args) {
    $reject(static fn () => IntegrationDatabase::assertOptIn($args), 'explicit opt-in required');
}
IntegrationDatabase::assertOptIn(['test.php','--run-isolated']);
$check(true, 'isolated opt-in accepted');
// No connection is attempted: hostile environment values must not alter settings.
putenv('DB_DATABASE=gighub_dev'); putenv('MYSQL_PWD=synthetic-never-used');
$check(IntegrationDatabase::validateSettings(['database' => $valid]) === $valid, 'environment cannot override explicit settings');
IntegrationDatabase::clearDatabaseEnvironment();
$check(getenv('DB_DATABASE') === false && getenv('MYSQL_PWD') === false, 'database environment removed');
echo "PASS: $count isolated-bootstrap offline checks; no database connection.\n";
