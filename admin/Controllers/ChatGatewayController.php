<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Core\Config;
use App\Core\Logger;
use App\Services\ChatService;

final class ChatGatewayController
{
    public function chat(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!$this->authorize()) {
            $this->json(401, [
                'ok' => false,
                'error' => 'unauthorized',
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

        $result = (new ChatService())->handle(
            $body,
            [
                'created_via' => 'chat_gateway',
                'message_via' => 'chat_gateway',
            ]
        );

        $this->json(
            (int) $result['status'],
            $result['body']
        );
    }

    private function authorize(): bool
    {
        $expected = (string) Config::get(
            'auth.chat_gateway_token',
            ''
        );

        if ($expected === '') {
            Logger::error(
                'chat_gateway.token_not_configured'
            );
            return false;
        }

        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (
            $header === ''
            && function_exists('getallheaders')
        ) {
            $headers = getallheaders();

            if (is_array($headers)) {
                $header = (string) (
                    $headers['Authorization']
                    ?? $headers['authorization']
                    ?? ''
                );
            }
        }

        if (
            !preg_match(
                '/^Bearer\s+(.+)$/i',
                trim($header),
                $matches
            )
        ) {
            return false;
        }

        return hash_equals(
            $expected,
            trim($matches[1])
        );
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
