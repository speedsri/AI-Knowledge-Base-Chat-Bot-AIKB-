<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use PDO;
use Throwable;

final class RoleController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $roles = $pdo->query(
            'SELECT
                r.id,
                r.name,
                r.description,
                COUNT(DISTINCT ur.user_id) AS user_count
             FROM roles r
             LEFT JOIN user_roles ur
                ON ur.role_id = r.id
             GROUP BY r.id
             ORDER BY r.id ASC'
        )->fetchAll();

        $permissions = $pdo->query(
            'SELECT
                id,
                key_name,
                description
             FROM permissions
             ORDER BY id ASC'
        )->fetchAll();

        $rows = $pdo->query(
            'SELECT
                role_id,
                permission_id
             FROM role_permissions
             ORDER BY role_id, permission_id'
        )->fetchAll();

        $assigned = [];

        foreach ($rows as $row) {
            $roleId = (int) $row['role_id'];
            $permissionId =
                (int) $row['permission_id'];

            $assigned[$roleId][$permissionId] = true;
        }

        View::render('roles/index', [
            'title' => 'Roles',
            'roles' => $roles,
            'permissions' => $permissions,
            'assigned' => $assigned,
            'error' =>
                $_SESSION['_flash_error'] ?? null,
            'status' =>
                $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset(
            $_SESSION['_flash_error'],
            $_SESSION['_flash_status']
        );
    }

    public function update(int $id): void
    {
        if (!Csrf::verify(
            $_POST['csrf_token'] ?? null
        )) {
            $_SESSION['_flash_error'] =
                'Your session expired. Please try again.';

            header('Location: /admin/roles');
            exit;
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT
                id,
                name,
                description
             FROM roles
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute([
            ':id' => $id,
        ]);

        $role = $stmt->fetch();

        if (!$role) {
            $_SESSION['_flash_error'] =
                'Role not found.';

            header('Location: /admin/roles');
            exit;
        }

        $description = trim(
            (string) (
                $_POST['description'] ?? ''
            )
        );

        if (mb_strlen($description) > 255) {
            $_SESSION['_flash_error'] =
                'Role description must not exceed 255 characters.';

            header('Location: /admin/roles');
            exit;
        }

        $permissionIds = [];

        foreach (
            (array) (
                $_POST['permissions'] ?? []
            )
            as $permissionId
        ) {
            if (
                filter_var(
                    $permissionId,
                    FILTER_VALIDATE_INT
                ) !== false
            ) {
                $permissionIds[] =
                    (int) $permissionId;
            }
        }

        $permissionIds = array_values(
            array_unique($permissionIds)
        );

        try {
            $pdo->beginTransaction();

            $updateRole = $pdo->prepare(
                'UPDATE roles
                 SET description = :description
                 WHERE id = :id'
            );

            $updateRole->execute([
                ':description' =>
                    $description !== ''
                        ? $description
                        : null,
                ':id' => $id,
            ]);

            if (
                (string) $role['name']
                === 'Administrator'
            ) {
                $allPermissionIds = $pdo->query(
                    'SELECT id
                     FROM permissions
                     ORDER BY id'
                )->fetchAll(
                    PDO::FETCH_COLUMN
                );

                $permissionIds = array_map(
                    'intval',
                    $allPermissionIds
                );
            }

            if ($permissionIds) {
                $placeholders = implode(
                    ',',
                    array_fill(
                        0,
                        count($permissionIds),
                        '?'
                    )
                );

                $validStmt = $pdo->prepare(
                    'SELECT id
                     FROM permissions
                     WHERE id IN (' .
                        $placeholders .
                    ')'
                );

                $validStmt->execute(
                    $permissionIds
                );

                $permissionIds = array_map(
                    'intval',
                    $validStmt->fetchAll(
                        PDO::FETCH_COLUMN
                    )
                );
            }

            $delete = $pdo->prepare(
                'DELETE FROM role_permissions
                 WHERE role_id = :role_id'
            );

            $delete->execute([
                ':role_id' => $id,
            ]);

            if ($permissionIds) {
                $insert = $pdo->prepare(
                    'INSERT INTO role_permissions (
                        role_id,
                        permission_id
                     ) VALUES (
                        :role_id,
                        :permission_id
                     )'
                );

                foreach ($permissionIds as $permissionId) {
                    $insert->execute([
                        ':role_id' => $id,
                        ':permission_id' =>
                            $permissionId,
                    ]);
                }
            }

            $pdo->commit();

            Logger::security(
                'role.permissions_updated',
                [
                    'role_id' => $id,
                    'role_name' =>
                        (string) $role['name'],
                    'permission_ids' =>
                        $permissionIds,
                    'updated_by' =>
                        AuthService::userId(),
                ]
            );

            $_SESSION['_flash_status'] =
                'Role settings updated.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error(
                'role.update_failed',
                [
                    'role_id' => $id,
                    'error' => $e->getMessage(),
                ]
            );

            $_SESSION['_flash_error'] =
                'Unable to update the role.';
        }

        header('Location: /admin/roles');
        exit;
    }
}
