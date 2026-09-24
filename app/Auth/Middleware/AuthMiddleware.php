<?php

declare(strict_types=1);

namespace App\Auth\Middleware;

use App\Auth\AuthService;

final class AuthMiddleware
{
    public static function handle(): void
    {
        if (!AuthService::check()) {
            $_SESSION['_intended_url'] = $_SERVER['REQUEST_URI'] ?? '/admin';
            header('Location: /login');
            exit;
        }
    }
}
