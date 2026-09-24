<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Auth\PasswordResetService;
use App\Core\View;

final class AuthController
{
    public function showLogin(): void
    {
        if (AuthService::check()) {
            header('Location: /admin');
            exit;
        }

        View::render('auth/login', [
            'title' => 'Sign in',
            'error' => $_SESSION['_flash_error'] ?? null,
        ], 'layouts/guest');

        unset($_SESSION['_flash_error']);
    }

    public function login(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /login');
            exit;
        }

        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $result = AuthService::attemptLogin($email, $password, $ip);

        if (!$result['success']) {
            $_SESSION['_flash_error'] = $result['error'];
            header('Location: /login');
            exit;
        }

        $intended = $_SESSION['_intended_url'] ?? '/admin';
        unset($_SESSION['_intended_url']);
        header('Location: ' . $intended);
        exit;
    }

    public function logout(): void
    {
        AuthService::logout();
        header('Location: /login');
        exit;
    }

    public function showForgotPassword(): void
    {
        View::render('auth/forgot_password', [
            'title' => 'Reset password',
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/guest');

        unset($_SESSION['_flash_status']);
    }

    public function sendResetLink(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            header('Location: /forgot-password');
            exit;
        }

        $email = (string) ($_POST['email'] ?? '');
        $token = PasswordResetService::createTokenFor($email);

        // Mail delivery is wired in a later phase. For now, the token is
        // logged server-side (never shown to the client) so an administrator
        // can retrieve it manually during early operation/testing.
        if ($token !== null) {
            \App\Core\Logger::info('password_reset.token_issued_no_mailer', ['email' => $email]);
        }

        // Always show the same message, regardless of whether the email existed,
        // to avoid leaking which addresses are registered.
        $_SESSION['_flash_status'] = 'If that email address is registered, password reset instructions have been generated.';
        header('Location: /forgot-password');
        exit;
    }
}
