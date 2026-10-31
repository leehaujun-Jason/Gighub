<?php
declare(strict_types=1);

return [
    'app' => ['environment' => 'local'],
    // Public, explicit URL only. Never infer verification links from HTTP Host.
    'verification' => ['base_url' => 'http://localhost/GigHub/public/'],
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
