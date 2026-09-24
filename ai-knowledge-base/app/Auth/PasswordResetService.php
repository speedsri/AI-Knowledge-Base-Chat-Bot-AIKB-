<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\Logger;

/**
 * Token-based password reset. Sending the actual email is out of scope for
 * Phase 1 (no mail transport configured yet) — tokens are generated and
 * validated here; wiring to an SMTP/API mail sender happens in a later phase.
 */
final class PasswordResetService
{
    private const TOKEN_TTL_MINUTES = 60;

    public static function createTokenFor(string $email): ?string
    {
        $user = UserRepository::findByEmail(strtolower(trim($email)));
        if (!$user) {
            // Do not reveal whether the email exists.
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL :ttl MINUTE), NOW())'
        );
        $stmt->execute([
            ':user_id' => $user['id'],
            ':token_hash' => $tokenHash,
            ':ttl' => self::TOKEN_TTL_MINUTES,
        ]);

        Logger::security('password_reset.token_created', ['user_id' => $user['id']]);

        // Caller is responsible for emailing this raw token to the user.
        return $token;
    }

    public static function resetPassword(string $token, string $newPassword): bool
    {
        $tokenHash = hash('sha256', $token);
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT * FROM password_resets
             WHERE token_hash = :hash AND expires_at > NOW() AND used_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $reset = $stmt->fetch();

        if (!$reset) {
            return false;
        }

        $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $update->execute([
            ':hash' => AuthService::hashPassword($newPassword),
            ':id' => $reset['user_id'],
        ]);

        $consume = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = :id');
        $consume->execute([':id' => $reset['id']]);

        Logger::security('password_reset.completed', ['user_id' => $reset['user_id']]);

        return true;
    }
}
