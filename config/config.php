<?php

declare(strict_types=1);

use App\Core\Env;

Env::load(dirname(__DIR__) . '/.env');

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'AI Knowledge Base'),
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
        'url' => Env::get('APP_URL', 'http://localhost'),
        'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
        'key' => Env::get('APP_KEY', ''),
    ],

    'secrets' => [
        // Used by App\Services\Encryptor to encrypt AI provider API keys at rest.
        'encryption_key' => Env::get('SECRETS_ENCRYPTION_KEY', ''),
    ],

    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', 'ai_kb'),
        'username' => Env::get('DB_USERNAME', 'root'),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    ],

    'session' => [
        'name' => Env::get('SESSION_NAME', 'aikb_session'),
        'lifetime_minutes' => Env::int('SESSION_LIFETIME_MINUTES', 120),
        'secure_cookie' => Env::bool('SESSION_SECURE_COOKIE', true),
    ],

    'redis' => [
        'enabled' => Env::bool('REDIS_ENABLED', false),
        'host' => Env::get('REDIS_HOST', '127.0.0.1'),
        'port' => Env::int('REDIS_PORT', 6379),
    ],

    'storage' => [
        'documents_path' => Env::get('STORAGE_DOCUMENTS_PATH', dirname(__DIR__) . '/storage/documents'),
        'max_upload_size_mb' => Env::int('MAX_UPLOAD_SIZE_MB', 25),
    ],

    'ai' => [
        'openai_api_key' => Env::get('OPENAI_API_KEY', ''),
        'gemini_api_key' => Env::get('GEMINI_API_KEY', ''),
    ],

    // Phase B.1: server-to-server client config for the RAG API on .220.
    // RAG_INTERNAL_TOKEN must match the SAME value configured in
    // dt-rag/.env's RAG_INTERNAL_TOKEN -- see app/Services/RagApiClient.php,
    // the only class permitted to read this value. Never referenced from
    // any view/JS.
    'rag' => [
        'api_base' => Env::get('RAG_API_BASE', 'http://192.168.1.220:8500'),
        'internal_token' => Env::get('RAG_INTERNAL_TOKEN', ''),
        'timeout_seconds' => Env::int('RAG_TIMEOUT_SECONDS', 8),
    ],

    'auth' => [
        'login_max_attempts' => Env::int('LOGIN_MAX_ATTEMPTS', 5),
        'login_lockout_minutes' => Env::int('LOGIN_LOCKOUT_MINUTES', 15),

        // Phase D: dedicated server-to-server token used by trusted
        // callers such as the .215 frontend/backend to access /api/chat.
        // This is intentionally separate from RAG_INTERNAL_TOKEN.
        'chat_gateway_token' => Env::get('CHAT_GATEWAY_TOKEN', ''),
    ],

    'widget' => [
        'enabled' => Env::bool('CHAT_WIDGET_ENABLED', true),
        'allowed_origins' => array_values(array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            explode(',', Env::get('CHAT_WIDGET_ALLOWED_ORIGINS', ''))
        ))),
        'max_attempts' => Env::int('CHAT_WIDGET_MAX_ATTEMPTS', 20),
        'window_minutes' => Env::int('CHAT_WIDGET_WINDOW_MINUTES', 5),
    ],
];
