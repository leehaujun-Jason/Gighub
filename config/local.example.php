<?php
declare(strict_types=1);

// Copy to config/local.php only when configuring an explicitly approved database.
// local.php is already ignored by Git. This example contains no credentials.
return [
    'app' => ['environment' => 'local'],
    'database' => [
        'enabled' => false,
        'host' => '<approved-host>',
        'port' => 3306,
        'name' => '<approved-database>',
        'username' => '<approved-username>',
        'password' => '<private-password>',
    ],
];
