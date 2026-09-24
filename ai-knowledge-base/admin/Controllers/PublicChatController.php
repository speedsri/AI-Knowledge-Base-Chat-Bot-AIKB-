<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\Csrf;
use App\Auth\Session;
use App\Core\Database;
use App\Core\SystemSettings;
use App\Core\View;
use App\Services\ChatService;

final class PublicChatController
{
    public function page(): void
    {
        $settings = SystemSettings::all();

        if (!(bool) ($settings['public_chat_enabled'] ?? true)) {
            http_response_code(503);

            echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chat Unavailable</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f8f9fa;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}
.box{max-width:520px;background:#fff;border:1px solid #dee2e6;border-radius:14px;padding:32px;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.06)}
h1{font-size:1.5rem;margin:0 0 12px}
p{color:#6c757d;margin:0}
</style>
</head>
<body>
<div class="box">
<h1>Public Chat is currently unavailable</h1>
<p>The administrator has temporarily disabled public chat access.</p>
</div>
</body>
</html>';

            return;
        }

        $kb = $this->activeKnowledgeBase();

        $conversationRef = Session::get(
            'public_chat_conversation_ref'
        );

        if (
            !is_string($conversationRef)
            || $conversationRef === ''
        ) {
            $conversationRef =
                'browser-' . bin2hex(random_bytes(16));

            Session::put(
                'public_chat_conversation_ref',
                $conversationRef
            );
        }

        View::render(
            'chat/public',
            [
                'title' => 'AI Assistant',
                'knowledgeBase' => $kb,
                'csrfToken' => Csrf::token(),
                'voiceConfig' => $this->voiceSettings(),
            ],
            'layouts/public_chat'
        );
    }

    public function message(): void
    {
        header(
            'Content-Type: application/json; charset=utf-8'
        );
        header('Cache-Control: no-store');

        $settings = SystemSettings::all();

        if (!(bool) ($settings['public_chat_enabled'] ?? true)) {
            $this->json(503, [
                'ok' => false,
                'error' => 'public_chat_disabled',
            ]);
            return;
        }

        $body = $this->jsonBody();

        if ($body === null) {
            $this->json(400, [
                'ok' => false,
                'error' => 'invalid_json',
            ]);
            return;
        }

        if (
            !Csrf::verify(
                isset($body['csrf_token'])
                    ? (string) $body['csrf_token']
                    : null
            )
        ) {
            $this->json(403, [
                'ok' => false,
                'error' => 'invalid_csrf',
            ]);
            return;
        }

        if (!$this->allowSessionRequest()) {
            $this->json(429, [
                'ok' => false,
                'error' => 'too_many_requests',
            ]);
            return;
        }

        $message = trim(
            (string) ($body['message'] ?? '')
        );

        $inputMode = strtolower(
            trim((string) ($body['input_mode'] ?? 'text'))
        );

        if (!in_array($inputMode, ['text', 'voice'], true)) {
            $inputMode = 'text';
        }

        $kb = $this->activeKnowledgeBase();

        if (!$kb) {
            $this->json(503, [
                'ok' => false,
                'error' => 'no_active_knowledge_base',
            ]);
            return;
        }

        $conversationRef = Session::get(
            'public_chat_conversation_ref'
        );

        if (
            !is_string($conversationRef)
            || $conversationRef === ''
        ) {
            $conversationRef =
                'browser-' . bin2hex(random_bytes(16));

            Session::put(
                'public_chat_conversation_ref',
                $conversationRef
            );
        }

        $result = (new ChatService())->handle(
            [
                'knowledge_base_id' => (int) $kb['id'],
                'conversation_ref' => $conversationRef,
                'channel' => 'web',
                'message' => $message,
            ],
            [
                'created_via' => 'public_browser_chat',
                'message_via' => $inputMode === 'voice'
                    ? 'public_browser_voice'
                    : 'public_browser_text',
            ]
        );

        $this->json(
            (int) $result['status'],
            $result['body']
        );
    }

    private function voiceSettings(): array
    {
        $defaults = [
            'enabled' => false,
            'provider' => '',
            'voice_id' => '',
            'language' => 'en',
            'stt_provider' => '',
            'stt_model' => '',
            'tts_provider' => '',
            'tts_model' => '',
            'speech_rate' => 1.0,
            'silence_timeout_ms' => 1500,
            'max_recording_seconds' => 60,
            'auto_language_detection' => true,
            'welcome_message' => '',
            'fallback_message' =>
                'Sorry, I could not process your voice request. Please try again.',
        ];

        $pdo = Database::connection();

        $stmt = $pdo->query(
            'SELECT
                is_enabled,
                provider,
                voice_id,
                settings_json
             FROM voice_settings
             WHERE id = 1
             LIMIT 1'
        );

        $row = $stmt->fetch();

        if (!$row) {
            return $defaults;
        }

        $json = json_decode(
            (string) ($row['settings_json'] ?? '{}'),
            true
        );

        if (!is_array($json)) {
            $json = [];
        }

        return array_merge(
            $defaults,
            $json,
            [
                'enabled' =>
                    (bool) ($row['is_enabled'] ?? false),
                'provider' =>
                    (string) ($row['provider'] ?? ''),
                'voice_id' =>
                    (string) ($row['voice_id'] ?? ''),
            ]
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

    private function allowSessionRequest(): bool
    {
        $now = time();

        $bucket = Session::get(
            'public_chat_rate_limit'
        );

        if (!is_array($bucket)) {
            $bucket = [
                'started_at' => $now,
                'count' => 0,
            ];
        }

        if (
            !isset($bucket['started_at'])
            || ($now - (int) $bucket['started_at']) >= 300
        ) {
            $bucket = [
                'started_at' => $now,
                'count' => 0,
            ];
        }

        $bucket['count'] =
            (int) ($bucket['count'] ?? 0) + 1;

        Session::put(
            'public_chat_rate_limit',
            $bucket
        );

        return $bucket['count'] <= 20;
    }

    private function jsonBody(): ?array
    {
        $raw = file_get_contents('php://input');

        if (
            $raw === false
            || trim($raw) === ''
        ) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? $decoded
            : null;
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
