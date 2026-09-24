<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

final class UserRepository
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function create(string $name, string $email, string $passwordHash): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (name, email, password_hash, is_active, created_at)
             VALUES (:name, :email, :hash, 1, NOW())'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':hash' => $passwordHash,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public static function assignRole(int $userId, string $roleName): void
    {
        $pdo = Database::connection();
        $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
        $roleStmt->execute([':name' => $roleName]);
        $role = $roleStmt->fetch();
        if (!$role) {
            throw new \RuntimeException("Unknown role: {$roleName}");
        }

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)'
        );
        $stmt->execute([':user_id' => $userId, ':role_id' => $role['id']]);
    }

    /** @return string[] role names for the user */
    public static function rolesFor(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.name FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);
        return array_column($stmt->fetchAll(), 'name');
    }

    /** @return string[] permission keys for the user, derived from their roles */
    public static function permissionsFor(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT p.key_name FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);
        return array_column($stmt->fetchAll(), 'key_name');
    }

    public static function touchLastLogin(int $userId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET last_login_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
    }
}
