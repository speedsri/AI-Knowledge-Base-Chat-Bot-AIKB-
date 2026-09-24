<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\SystemSettings;
use App\Services\ChatService;
use App\Services\RateLimiter;

final class WidgetController
{
    public function message(): void
    {
        header(
            'Content-Type: application/json; charset=utf-8'
        );
        header('Cache-Control: no-store');

        /*
         * Server-level emergency master switch.
         * Normal operational control comes from
         * Admin -> System Settings.
         */
        if (!(bool) Config::get('widget.enabled', true)) {
            $this->json(503, [
                'ok' => false,
                'error' => 'widget_master_disabled',
            ]);
            return;
        }

        $settings = SystemSettings::all();

        if (!(bool) ($settings['widget_enabled'] ?? false)) {
            $this->json(503, [
                'ok' => false,
                'error' => 'widget_disabled',
            ]);
            return;
        }

        $origin = rtrim(
            trim(
                (string) (
                    $_SERVER['HTTP_ORIGIN']
                    ?? ''
                )
            ),
            '/'
        );

        if (!$this->originAllowed(
            $origin,
            $settings
        )) {
            Logger::security(
                'widget.origin_rejected',
                [
                    'origin' =>
                        $origin !== ''
                            ? $origin
                            : null,
                    'ip' =>
                        $this->clientIp(),
                ]
            );

            $this->json(403, [
                'ok' => false,
                'error' => 'origin_not_allowed',
            ]);
            return;
        }

        header(
            'Access-Control-Allow-Origin: '
            . $origin
        );
        header('Vary: Origin');

        $message = trim(
            (string) (
                $_POST['message']
                ?? ''
            )
        );

        $conversationRef = trim(
            (string) (
                $_POST['conversation_ref']
                ?? ''
            )
        );

        $inputMode = strtolower(
            trim(
                (string) (
                    $_POST['input_mode']
                    ?? 'text'
                )
            )
        );

        if (!in_array(
            $inputMode,
            ['text', 'voice'],
            true
        )) {
            $inputMode = 'text';
        }

        if (
            $inputMode === 'voice'
            && !(bool) (
                $settings['widget_voice_enabled']
                ?? true
            )
        ) {
            $this->json(403, [
                'ok' => false,
                'error' => 'widget_voice_disabled',
            ]);
            return;
        }

        if (
            $message === ''
            || mb_strlen($message) > 4000
        ) {
            $this->json(422, [
                'ok' => false,
                'error' => 'invalid_message',
            ]);
            return;
        }

        if (
            $conversationRef === ''
            || strlen($conversationRef) > 190
            || preg_match(
                '/[\x00-\x1F\x7F]/',
                $conversationRef
            )
        ) {
            $this->json(422, [
                'ok' => false,
                'error' => 'invalid_conversation_ref',
            ]);
            return;
        }

        $ip = $this->clientIp();

        $rateKey = sprintf(
            'widget:%s:%s',
            hash(
                'sha256',
                $origin
            ),
            hash(
                'sha256',
                $ip
            )
        );

        $maxAttempts = max(
            1,
            min(
                1000,
                (int) (
                    $settings[
                        'widget_max_attempts'
                    ]
                    ?? 20
                )
            )
        );

        $windowMinutes = max(
            1,
            min(
                1440,
                (int) (
                    $settings[
                        'widget_window_minutes'
                    ]
                    ?? 5
                )
            )
        );

        if (
            RateLimiter::tooManyAttempts(
                $rateKey,
                $maxAttempts,
                $windowMinutes
            )
        ) {
            $this->json(429, [
                'ok' => false,
                'error' => 'too_many_requests',
            ]);
            return;
        }

        RateLimiter::hit($rateKey);

        $kb = $this->activeKnowledgeBase();

        if (!$kb) {
            $this->json(503, [
                'ok' => false,
                'error' =>
                    'no_active_knowledge_base',
            ]);
            return;
        }

        $result =
            (new ChatService())->handle(
                [
                    'knowledge_base_id' =>
                        (int) $kb['id'],
                    'conversation_ref' =>
                        $conversationRef,
                    'channel' =>
                        'widget',
                    'message' =>
                        $message,
                ],
                [
                    'created_via' =>
                        'embedded_widget',
                    'message_via' =>
                        $inputMode === 'voice'
                            ? 'embedded_widget_voice'
                            : 'embedded_widget_text',
                ]
            );

        $this->json(
            (int) $result['status'],
            $result['body']
        );
    }

    private function originAllowed(
        string $origin,
        array $settings
    ): bool {
        if ($origin === '') {
            return false;
        }

        $allowed =
            SystemSettings::allowedOrigins(
                $settings
            );

        if ($allowed === []) {
            return false;
        }

        return in_array(
            $origin,
            $allowed,
            true
        );
    }

    private function activeKnowledgeBase(): array|false
    {
        $pdo = Database::connection();

        $stmt = $pdo->query(
            'SELECT id, name, slug
             FROM knowledge_bases
             WHERE is_active = 1
             ORDER BY id ASC
             LIMIT 1'
        );

        return $stmt->fetch();
    }

    private function clientIp(): string
    {
        $cfIp = trim(
            (string) (
                $_SERVER[
                    'HTTP_CF_CONNECTING_IP'
                ]
                ?? ''
            )
        );

        if (
            $cfIp !== ''
            && filter_var(
                $cfIp,
                FILTER_VALIDATE_IP
            )
        ) {
            return $cfIp;
        }

        $remote = trim(
            (string) (
                $_SERVER['REMOTE_ADDR']
                ?? ''
            )
        );

        if (
            $remote !== ''
            && filter_var(
                $remote,
                FILTER_VALIDATE_IP
            )
        ) {
            return $remote;
        }

        return 'unknown';
    }

    private function json(
        int $status,
        array $payload
    ): void {
        http_response_code($status);

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
    }
}
