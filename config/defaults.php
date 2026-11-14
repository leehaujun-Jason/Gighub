<?php
declare(strict_types=1);

return [
    'avatar_storage' => 'C:/MariaDB_Recovery/GigHub_Clean_Test/private/profile-photos',
    'app' => ['environment' => 'local'],
    // Public, explicit URL only. Never infer verification links from HTTP Host.
    'verification' => ['base_url' => 'http://localhost/GigHub/public/'],
    'mail' => [
        'enabled' => false,
        'transport' => 'smtp', // Explicit local/test 'memory' mode is also available.
        'host' => '',
        'port' => 587,
        'encryption' => 'starttls', // starttls or smtps; never plaintext.
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'GigHub',
        'timeout' => 10, // Integer seconds, 1–30; per operation, not a total deadline.
    ],
    'session' => ['name' => 'GIGHUBSESSID', 'path' => '/GigHub/public/'],
    'database' => [
        'enabled' => false,
        'host' => '',
        'port' => 3306,
        'name' => '',
        'username' => '',
        'password' => '',
    ],
];
