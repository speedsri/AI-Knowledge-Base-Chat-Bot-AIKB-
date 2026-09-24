<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env loader. No composer dependency required for Phase 1.
 * Values are read once and cached in a static array for the request lifetime.
 */
final class Env
{
    private static bool $loaded = false;
    private static array $values = [];

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip matching surrounding quotes.
                if (strlen($value) >= 2 &&
                    (($value[0] === '"' && $value[-1] === '"') ||
                     ($value[0] === "'" && $value[-1] === "'"))
                ) {
                    $value = substr($value, 1, -1);
                }
                self::$values[$key] = $value;
                if (getenv($key) === false) {
                    putenv("{$key}={$value}");
                }
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        $fromEnv = getenv($key);
        return $fromEnv === false ? $default : $fromEnv;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Required environment variable [{$key}] is not set.");
        }
        return (string) $value;
    }
}
