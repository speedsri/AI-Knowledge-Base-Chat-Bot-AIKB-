<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * DB-backed rate limiter (works without Redis, per architecture requirement
 * that the app must function with Redis disabled). Keyed by an arbitrary
 * string (e.g. "login:{ip}:{email}") with a rolling time window.
 */
final class RateLimiter
{
    public static function tooManyAttempts(string $key, int $maxAttempts, int $windowMinutes): bool
    {
        $count = self::attemptCount($key, $windowMinutes);
        return $count >= $maxAttempts;
    }

    public static function attemptCount(string $key, int $windowMinutes): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM rate_limit_hits
             WHERE rate_key = :key AND created_at >= (NOW() - INTERVAL :minutes MINUTE)'
        );
        $stmt->bindValue(':key', $key);
        $stmt->bindValue(':minutes', $windowMinutes, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public static function hit(string $key): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO rate_limit_hits (rate_key, created_at) VALUES (:key, NOW())'
        );
        $stmt->execute([':key' => $key]);
    }

    public static function clear(string $key): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key = :key');
        $stmt->execute([':key' => $key]);
    }
}
