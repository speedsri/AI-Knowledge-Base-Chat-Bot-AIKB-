#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Logger;
use App\Services\Encryptor;
use App\Services\RagApiClient;

$stateFile = dirname(__DIR__) . '/storage/cache/provider-sync-state.json';
$failureCooldownSeconds = 300;

try {
    $pdo = Database::connection();

    $stmt = $pdo->query(
        "SELECT id, api_key_encrypted
         FROM ai_providers
         WHERE provider_type = 'gemini'
           AND is_enabled = 1
           AND api_key_encrypted IS NOT NULL
         ORDER BY id ASC
         LIMIT 1"
    );

    $provider = $stmt->fetch();

    if (!$provider || empty($provider['api_key_encrypted'])) {
        exit(0);
    }

    $client = new RagApiClient();

    /*
     * Lightweight check only.
     * This endpoint does NOT make a Gemini API request.
     */
    $runtime = $client->providerRuntime();

    if (empty($runtime['ok'])) {
        exit(0);
    }

    /*
     * Dashboard credential is already active in this RAG process.
     * Nothing to do.
     */
    if (
        ($runtime['credential_source'] ?? '') === 'dashboard'
        && !empty($runtime['configured'])
    ) {
        exit(0);
    }

    /*
     * Avoid repeatedly hitting Gemini every minute if a bad credential,
     * network problem, quota problem, etc. causes validation failures.
     */
    $state = [];

    if (is_file($stateFile)) {
        $decoded = json_decode(
            (string) file_get_contents($stateFile),
            true
        );

        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    $lastFailure = (int) ($state['last_failure'] ?? 0);

    if (
        $lastFailure > 0
        && (time() - $lastFailure) < $failureCooldownSeconds
    ) {
        exit(0);
    }

    try {
        $apiKey = Encryptor::decrypt(
            (string) $provider['api_key_encrypted']
        );
    } catch (\Throwable $e) {
        Logger::error(
            'provider_sync.decrypt_failed',
            [
                'provider_id' => (int) $provider['id'],
                'error_type' => get_class($e),
            ]
        );

        exit(0);
    }

    $result = $client->providerConfigure($apiKey);

    unset($apiKey);

    $healthy =
        !empty($result['ok'])
        && !empty($result['healthy'])
        && !empty($result['configured']);

    if (!$healthy) {
        @file_put_contents(
            $stateFile,
            json_encode(
                [
                    'last_failure' => time(),
                    'boot_id' => $runtime['boot_id'] ?? null,
                ],
                JSON_PRETTY_PRINT
            ),
            LOCK_EX
        );

        Logger::info(
            'provider_sync.failed',
            [
                'provider_id' => (int) $provider['id'],
                'error_code' =>
                    $result['error_code']
                    ?? $result['error']
                    ?? 'unknown',
            ]
        );

        exit(0);
    }

    @file_put_contents(
        $stateFile,
        json_encode(
            [
                'last_success' => time(),
                'last_failure' => 0,
                'boot_id' => $runtime['boot_id'] ?? null,
            ],
            JSON_PRETTY_PRINT
        ),
        LOCK_EX
    );

    Logger::info(
        'provider_sync.applied',
        [
            'provider_id' => (int) $provider['id'],
            'boot_id' => $runtime['boot_id'] ?? null,
        ]
    );
} catch (\Throwable $e) {
    /*
     * Cron must never crash the application container.
     * Never log credentials or request bodies.
     */
    Logger::info(
        'provider_sync.unavailable',
        [
            'error_type' => get_class($e),
        ]
    );
}

exit(0);
