<?php

declare(strict_types=1);

namespace App\Auth\Middleware;

use App\Auth\AuthService;
use App\Core\Logger;

final class RoleMiddleware
{
    /**
     * Require the current user to hold at least one of the given permission keys.
     * Call AuthMiddleware::handle() first to guarantee a logged-in user.
     */
    public static function requirePermission(string ...$permissionKeys): void
    {
        foreach ($permissionKeys as $key) {
            if (AuthService::hasPermission($key)) {
                return;
            }
        }

        Logger::security('access.denied', [
            'user_id' => AuthService::userId(),
            'required_permissions' => $permissionKeys,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
        ]);

        http_response_code(403);
        echo '403 — You do not have permission to access this page.';
        exit;
    }

    public static function requireRole(string ...$roleNames): void
    {
        foreach ($roleNames as $role) {
            if (AuthService::hasRole($role)) {
                return;
            }
        }

        Logger::security('access.denied_role', [
            'user_id' => AuthService::userId(),
            'required_roles' => $roleNames,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
        ]);

        http_response_code(403);
        echo '403 — You do not have permission to access this page.';
        exit;
    }
}
