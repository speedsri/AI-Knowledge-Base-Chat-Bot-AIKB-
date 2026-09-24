<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Config;

final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $lifetimeSeconds = Config::get('session.lifetime_minutes', 120) * 60;

        session_name((string) Config::get('session.name', 'aikb_session'));

        session_set_cookie_params([
            'lifetime' => $lifetimeSeconds,
            'path' => '/',
            'domain' => '',
            'secure' => (bool) Config::get('session.secure_cookie', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::$started = true;

        self::enforceIdleTimeout($lifetimeSeconds);
        self::regeneratePeriodically();
    }

    private static function enforceIdleTimeout(int $lifetimeSeconds): void
    {
        $lastActivity = $_SESSION['_last_activity'] ?? null;
        if ($lastActivity !== null && (time() - $lastActivity) > $lifetimeSeconds) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = time();
    }

    /** Rotate the session ID periodically to reduce fixation/hijack risk. */
    private static function regeneratePeriodically(): void
    {
        $lastRegen = $_SESSION['_last_regen'] ?? null;

        // A freshly-created PHP session already has a cryptographically
        // random ID. Record its creation time instead of immediately
        // regenerating it and emitting a second Set-Cookie header.
        if ($lastRegen === null) {
            $_SESSION['_last_regen'] = time();
            return;
        }

        if ((time() - (int) $lastRegen) > 900) {
            session_regenerate_id(true);
            $_SESSION['_last_regen'] = time();
        }
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
