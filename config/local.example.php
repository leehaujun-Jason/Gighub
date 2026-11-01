<?php
declare(strict_types=1);

// Copy to config/local.php only when configuring an explicitly approved database.
// local.php is already ignored by Git. This example contains no credentials.
return [
    'app' => ['environment' => 'local'],
    'verification' => ['base_url' => 'http://localhost/GigHub/public/'],
    // Configure privately only after approval for a controlled real-email test.
    // Never put real SMTP credentials in this example or enable it automatically.
    'mail' => [
        'enabled' => false,
        'transport' => 'smtp',
        'host' => '<smtp-host>',
        'port' => 587,
        'encryption' => 'starttls', // Use smtps with provider's implicit TLS port.
        'username' => '<smtp-username>',
        'password' => '<smtp-password>',
        'from_email' => '<approved-sender-email>',
        'from_name' => 'GigHub',
        'timeout' => 10,
    ],
    'database' => [
        'enabled' => false,
        'host' => '<approved-host>',
        'port' => 3306,
        'name' => '<approved-database>',
        'username' => '<approved-username>',
        'password' => '<private-password>',
    ],
];
