<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Auth\UserRepository;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;

final class UserController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $users = $pdo->query(
            'SELECT u.id, u.name, u.email, u.is_active, u.last_login_at,
                    GROUP_CONCAT(r.name SEPARATOR ", ") AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             GROUP BY u.id
             ORDER BY u.created_at DESC'
        )->fetchAll();

        $roles = $pdo->query('SELECT name FROM roles ORDER BY name')->fetchAll();

        View::render('users/index', [
            'title' => 'Users',
            'users' => $users,
            'roles' => array_column($roles, 'name'),
            'error' => $_SESSION['_flash_error'] ?? null,
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error'], $_SESSION['_flash_status']);
    }

    public function store(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/users');
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'Viewer');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
            $_SESSION['_flash_error'] = 'Please provide a valid name, email, and a password of at least 10 characters.';
            header('Location: /admin/users');
            exit;
        }

        if (UserRepository::findByEmail($email)) {
            $_SESSION['_flash_error'] = 'A user with that email already exists.';
            header('Location: /admin/users');
            exit;
        }

        $userId = UserRepository::create($name, $email, AuthService::hashPassword($password));
        UserRepository::assignRole($userId, $role);

        Logger::security('user.created', [
            'created_user_id' => $userId,
            'created_by' => AuthService::userId(),
            'role' => $role,
        ]);

        $_SESSION['_flash_status'] = "User {$email} created.";
        header('Location: /admin/users');
        exit;
    }
}
