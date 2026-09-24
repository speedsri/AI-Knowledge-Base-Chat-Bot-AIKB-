<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\SystemSettings;
use App\Core\View;
use Throwable;

final class SystemSettingsController
{
    public function index(): void
    {
        View::render(
            'system_settings/index',
            [
                'title' => 'System Settings',
                'settings' => SystemSettings::all(),
                'status' =>
                    $_SESSION['_flash_status'] ?? null,
                'error' =>
                    $_SESSION['_flash_error'] ?? null,
            ],
            'layouts/base'
        );

        unset(
            $_SESSION['_flash_status'],
            $_SESSION['_flash_error']
        );
    }

    public function update(): void
    {
        if (!Csrf::verify(
            $_POST['csrf_token'] ?? null
        )) {
            $_SESSION['_flash_error'] =
                'Your session expired. Please try again.';

            header(
                'Location: /admin/system-settings'
            );
            exit;
        }

        $siteName = trim(
            (string) (
                $_POST['site_name']
                ?? ''
            )
        );

        $themeColor =
            SystemSettings::validColor(
                $_POST['admin_theme_color']
                ?? null
            );

        $publicBaseUrl = rtrim(
            trim(
                (string) (
                    $_POST['public_base_url']
                    ?? ''
                )
            ),
            '/'
        );

        $publicChatEnabled =
            isset($_POST['public_chat_enabled'])
                ? 1
                : 0;

        $widgetEnabled =
            isset($_POST['widget_enabled'])
                ? 1
                : 0;

        $widgetAllowedOrigins = trim(
            (string) (
                $_POST['widget_allowed_origins']
                ?? ''
            )
        );

        $widgetTitle = trim(
            (string) (
                $_POST['widget_title']
                ?? 'AI Assistant'
            )
        );

        $widgetGreeting = trim(
            (string) (
                $_POST['widget_greeting']
                ?? 'Hello. How can I help you today?'
            )
        );

        $widgetPosition = strtolower(
            trim(
                (string) (
                    $_POST['widget_position']
                    ?? 'right'
                )
            )
        );

        if (!in_array(
            $widgetPosition,
            ['left', 'right'],
            true
        )) {
            $widgetPosition = 'right';
        }

        $widgetLanguage = trim(
            (string) (
                $_POST['widget_language']
                ?? 'en-US'
            )
        );

        $widgetVoiceEnabled =
            isset($_POST['widget_voice_enabled'])
                ? 1
                : 0;

        $widgetMaxAttempts = max(
            1,
            min(
                1000,
                (int) (
                    $_POST['widget_max_attempts']
                    ?? 20
                )
            )
        );

        $widgetWindowMinutes = max(
            1,
            min(
                1440,
                (int) (
                    $_POST['widget_window_minutes']
                    ?? 5
                )
            )
        );

        if (
            $siteName === ''
            || mb_strlen($siteName) > 120
        ) {
            $_SESSION['_flash_error'] =
                'Site name must be between 1 and 120 characters.';

            header(
                'Location: /admin/system-settings'
            );
            exit;
        }

        if (
            $publicBaseUrl !== ''
            && !filter_var(
                $publicBaseUrl,
                FILTER_VALIDATE_URL
            )
        ) {
            $_SESSION['_flash_error'] =
                'Public Base URL must be a valid URL.';

            header(
                'Location: /admin/system-settings'
            );
            exit;
        }

        if (
            mb_strlen($widgetTitle) < 1
            || mb_strlen($widgetTitle) > 120
        ) {
            $_SESSION['_flash_error'] =
                'Widget title must be between 1 and 120 characters.';

            header(
                'Location: /admin/system-settings'
            );
            exit;
        }

        if (
            mb_strlen($widgetGreeting) > 500
        ) {
            $_SESSION['_flash_error'] =
                'Widget greeting must not exceed 500 characters.';

            header(
                'Location: /admin/system-settings'
            );
            exit;
        }

        if (
            $widgetLanguage === ''
            || mb_strlen($widgetLanguage) > 20
        ) {
            $_SESSION['_flash_error'] =
                'Widget language must be between 1 and 20 characters.';

            header(
                'Location: /admin/system-settings'
            );
            exit;
        }

        try {
            $pdo = Database::connection();

            $stmt = $pdo->prepare(
                'UPDATE system_settings
                 SET
                    site_name = :site_name,
                    admin_theme_color = :theme_color,
                    public_base_url = :public_base_url,
                    public_chat_enabled = :public_chat_enabled,
                    widget_enabled = :widget_enabled,
                    widget_allowed_origins = :widget_allowed_origins,
                    widget_title = :widget_title,
                    widget_greeting = :widget_greeting,
                    widget_position = :widget_position,
                    widget_language = :widget_language,
                    widget_voice_enabled = :widget_voice_enabled,
                    widget_max_attempts = :widget_max_attempts,
                    widget_window_minutes = :widget_window_minutes,
                    updated_by = :updated_by
                 WHERE id = 1'
            );

            $stmt->execute([
                ':site_name' => $siteName,
                ':theme_color' => $themeColor,
                ':public_base_url' =>
                    $publicBaseUrl,
                ':public_chat_enabled' =>
                    $publicChatEnabled,
                ':widget_enabled' =>
                    $widgetEnabled,
                ':widget_allowed_origins' =>
                    $widgetAllowedOrigins !== ''
                        ? $widgetAllowedOrigins
                        : null,
                ':widget_title' =>
                    $widgetTitle,
                ':widget_greeting' =>
                    $widgetGreeting,
                ':widget_position' =>
                    $widgetPosition,
                ':widget_language' =>
                    $widgetLanguage,
                ':widget_voice_enabled' =>
                    $widgetVoiceEnabled,
                ':widget_max_attempts' =>
                    $widgetMaxAttempts,
                ':widget_window_minutes' =>
                    $widgetWindowMinutes,
                ':updated_by' =>
                    AuthService::userId(),
            ]);

            Logger::audit(
                'system_settings.updated',
                AuthService::userId(),
                'system_settings',
                '1',
                [
                    'site_name' =>
                        $siteName,
                    'admin_theme_color' =>
                        $themeColor,
                    'public_base_url' =>
                        $publicBaseUrl,
                    'public_chat_enabled' =>
                        $publicChatEnabled,
                    'widget_enabled' =>
                        $widgetEnabled,
                    'widget_allowed_origins' =>
                        $widgetAllowedOrigins,
                    'widget_title' =>
                        $widgetTitle,
                    'widget_position' =>
                        $widgetPosition,
                    'widget_language' =>
                        $widgetLanguage,
                    'widget_voice_enabled' =>
                        $widgetVoiceEnabled,
                    'widget_max_attempts' =>
                        $widgetMaxAttempts,
                    'widget_window_minutes' =>
                        $widgetWindowMinutes,
                ]
            );

            $_SESSION['_flash_status'] =
                'System settings updated successfully.';
        } catch (Throwable $e) {
            Logger::error(
                'system_settings.update_failed',
                [
                    'message' =>
                        $e->getMessage(),
                ]
            );

            $_SESSION['_flash_error'] =
                'Unable to update system settings.';
        }

        header(
            'Location: /admin/system-settings'
        );
        exit;
    }
}
