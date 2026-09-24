<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class SystemSettings
{
    private const DEFAULTS = [
        'site_name' => 'AI Knowledge Base',
        'admin_theme_color' => '#0d6efd',

        'public_base_url' => '',
        'public_chat_enabled' => 1,

        'widget_enabled' => 0,
        'widget_allowed_origins' => '',
        'widget_title' => 'AI Assistant',
        'widget_greeting' => 'Hello. How can I help you today?',
        'widget_position' => 'right',
        'widget_language' => 'en-US',
        'widget_voice_enabled' => 1,
        'widget_max_attempts' => 20,
        'widget_window_minutes' => 5,
    ];

    public static function all(): array
    {
        try {
            $pdo = Database::connection();

            $row = $pdo->query(
                'SELECT
                    site_name,
                    admin_theme_color,
                    public_base_url,
                    public_chat_enabled,
                    widget_enabled,
                    widget_allowed_origins,
                    widget_title,
                    widget_greeting,
                    widget_position,
                    widget_language,
                    widget_voice_enabled,
                    widget_max_attempts,
                    widget_window_minutes,
                    updated_by,
                    updated_at
                 FROM system_settings
                 WHERE id = 1
                 LIMIT 1'
            )->fetch();

            if (!$row) {
                return self::DEFAULTS;
            }

            return array_merge(
                self::DEFAULTS,
                $row
            );
        } catch (Throwable) {
            return self::DEFAULTS;
        }
    }

    public static function validColor(
        ?string $color
    ): string {
        $color = trim((string) $color);

        if (
            preg_match(
                '/^#[0-9a-fA-F]{6}$/',
                $color
            )
        ) {
            return strtolower($color);
        }

        return self::DEFAULTS[
            'admin_theme_color'
        ];
    }

    public static function baseUrl(array $settings): string
    {
        return rtrim(
            trim(
                (string) (
                    $settings['public_base_url']
                    ?? ''
                )
            ),
            '/'
        );
    }

    public static function loginUrl(array $settings): string
    {
        $base = self::baseUrl($settings);

        return $base !== ''
            ? $base . '/login'
            : '/login';
    }

    public static function chatUrl(array $settings): string
    {
        $base = self::baseUrl($settings);

        return $base !== ''
            ? $base . '/chat'
            : '/chat';
    }

    public static function widgetScriptUrl(array $settings): string
    {
        $base = self::baseUrl($settings);

        return $base !== ''
            ? $base . '/assets/chat-widget.js'
            : '/assets/chat-widget.js';
    }

    public static function widgetApiUrl(array $settings): string
    {
        $base = self::baseUrl($settings);

        return $base !== ''
            ? $base . '/widget/message'
            : '/widget/message';
    }

    public static function allowedOrigins(array $settings): array
    {
        $raw = trim(
            (string) (
                $settings['widget_allowed_origins']
                ?? ''
            )
        );

        if ($raw === '') {
            return [];
        }

        $parts = preg_split(
            '/[\r\n,]+/',
            $raw
        );

        if (!is_array($parts)) {
            return [];
        }

        $origins = [];

        foreach ($parts as $origin) {
            $origin = rtrim(
                trim((string) $origin),
                '/'
            );

            if ($origin !== '') {
                $origins[] = $origin;
            }
        }

        return array_values(
            array_unique($origins)
        );
    }
}
