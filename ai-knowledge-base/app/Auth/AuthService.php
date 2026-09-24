<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;
use App\Core\Logger;
use App\Services\RateLimiter;

final class AuthService
{
    private const SESSION_USER_ID = '_auth_user_id';

    /**
     * @return array{success: bool, error?: string}
     */
    public static function attemptLogin(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        $maxAttempts = (int) Config::get('auth.login_max_attempts', 5);
        $lockoutMinutes = (int) Config::get('auth.login_lockout_minutes', 15);

        $throttleKey = 'login:' . $ip . ':' . $email;

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts, $lockoutMinutes)) {
            Logger::security('login.rate_limited', ['email' => $email, 'ip' => $ip]);
            return ['success' => false, 'error' => 'Too many login attempts. Please try again later.'];
        }

        $user = UserRepository::findByEmail($email);

        // Constant-time-ish behavior: always verify against *some* hash to avoid
        // trivially timing whether the email exists.
        $hashToCheck = $user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalid';
        $passwordOk = password_verify($password, $hashToCheck);

        if (!$user || !$passwordOk || (int) $user['is_active'] !== 1) {
            RateLimiter::hit($throttleKey);
            Logger::security('login.failed', ['email' => $email, 'ip' => $ip]);
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        RateLimiter::clear($throttleKey);

        // Upgrade legacy hashes transparently.
        if (password_needs_rehash($hashToCheck, PASSWORD_DEFAULT)) {
            self::rehashPassword((int) $user['id'], $password);
        }

        session_regenerate_id(true);
        Session::put(self::SESSION_USER_ID, (int) $user['id']);
        UserRepository::touchLastLogin((int) $user['id']);
        Logger::security('login.success', ['user_id' => $user['id'], 'ip' => $ip]);

        return ['success' => true];
    }

    public static function logout(): void
    {
        $userId = self::userId();
        Session::destroy();
        Logger::security('logout', ['user_id' => $userId]);
    }

    public static function check(): bool
    {
        return self::userId() !== null;
    }

    public static function userId(): ?int
    {
        $id = Session::get(self::SESSION_USER_ID);
        return is_int($id) ? $id : null;
    }

    public static function user(): ?array
    {
        $id = self::userId();
        return $id === null ? null : UserRepository::findById($id);
    }

    public static function hasRole(string $role): bool
    {
        $id = self::userId();
        if ($id === null) {
            return false;
        }
        return in_array($role, UserRepository::rolesFor($id), true);
    }

    public static function hasPermission(string $permissionKey): bool
    {
        $id = self::userId();
        if ($id === null) {
            return false;
        }
        return in_array($permissionKey, UserRepository::permissionsFor($id), true);
    }

    private static function rehashPassword(int $userId, string $plainPassword): void
    {
        $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $pdo = \App\Core\Database::connection();
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $newHash, ':id' => $userId]);
    }

    public static function hashPassword(string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }
}
