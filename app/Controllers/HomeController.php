<?php
declare(strict_types=1);

namespace GigHub\Controllers;

final class HomeController
{
    public function show(): array
    {
        ob_start();
        require dirname(__DIR__) . '/Views/home.php';
        $body = ob_get_clean();
        return ['status' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }
}
