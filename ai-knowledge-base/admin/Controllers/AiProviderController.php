<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use App\Services\Encryptor;
use App\Services\RagApiClient;

final class AiProviderController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $providers = $pdo->query(
            'SELECT * FROM ai_providers ORDER BY name'
        )->fetchAll();

        View::render('ai_providers/index', [
            'title' => 'AI Providers',
            'providers' => $providers,
            'error' => $_SESSION['_flash_error'] ?? null,
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset(
            $_SESSION['_flash_error'],
            $_SESSION['_flash_status']
        );
    }

    public function store(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] =
                'Your session expired. Please try again.';
            header('Location: /admin/ai-providers');
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $displayName = trim(
            (string) ($_POST['display_name'] ?? '')
        ) ?: null;

        $modelsRaw = trim(
            (string) ($_POST['model_registry'] ?? '')
        );

        if ($name === '') {
            $_SESSION['_flash_error'] =
                'Please provide a provider name.';
            header('Location: /admin/ai-providers');
            exit;
        }

        $models = $modelsRaw === ''
            ? []
            : array_values(
                array_filter(
                    array_map('trim', explode("\n", $modelsRaw))
                )
            );

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'INSERT INTO ai_providers
                (
                    name,
                    display_name,
                    provider_type,
                    model_registry,
                    is_enabled,
                    created_at
                )
             VALUES
                (
                    :name,
                    :display_name,
                    \'gemini\',
                    :models,
                    1,
                    NOW()
                )'
        );

        $stmt->execute([
            ':name' => $name,
            ':display_name' => $displayName,
            ':models' => json_encode(
                $models,
                JSON_UNESCAPED_SLASHES
            ),
        ]);

        $id = (int) $pdo->lastInsertId();

        Logger::audit(
            'ai_provider.created',
            AuthService::userId(),
            'ai_provider',
            (string) $id,
            ['name' => $name]
        );

        $_SESSION['_flash_status'] =
            "Provider \"{$name}\" added.";

        header('Location: /admin/ai-providers');
        exit;
    }

    public function edit(int $id): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT * FROM ai_providers WHERE id = :id'
        );

        $stmt->execute([':id' => $id]);

        $provider = $stmt->fetch();

        if (!$provider) {
            http_response_code(404);
            echo '404 — Provider not found';
            return;
        }

        View::render('ai_providers/edit', [
            'title' => 'Edit AI Provider',
            'provider' => $provider,
            'credentialConfigured' =>
                !empty($provider['api_key_encrypted']),
            'error' => $_SESSION['_flash_error'] ?? null,
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset(
            $_SESSION['_flash_error'],
            $_SESSION['_flash_status']
        );
    }

    public function update(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] =
                'Your session expired. Please try again.';
            header("Location: /admin/ai-providers/{$id}/edit");
            exit;
        }

        $displayName = trim(
            (string) ($_POST['display_name'] ?? '')
        ) ?: null;

        $modelsRaw = trim(
            (string) ($_POST['model_registry'] ?? '')
        );

        $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;

        $apiKey = trim(
            (string) ($_POST['api_key'] ?? '')
        );

        if (strlen($apiKey) > 1024) {
            $_SESSION['_flash_error'] =
                'API key is too long.';
            header("Location: /admin/ai-providers/{$id}/edit");
            exit;
        }

        $models = $modelsRaw === ''
            ? []
            : array_values(
                array_filter(
                    array_map('trim', explode("\n", $modelsRaw))
                )
            );

        $pdo = Database::connection();

        $credentialChanged = $apiKey !== '';

        try {
            if ($credentialChanged) {
                $encrypted = Encryptor::encrypt($apiKey);
                $last4 = substr($apiKey, -4);

                $stmt = $pdo->prepare(
                    'UPDATE ai_providers
                     SET
                        display_name = :display_name,
                        model_registry = :models,
                        is_enabled = :enabled,
                        api_key_encrypted = :encrypted,
                        api_key_last4 = :last4,
                        credential_updated_at = NOW(),
                        updated_at = NOW()
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':display_name' => $displayName,
                    ':models' => json_encode(
                        $models,
                        JSON_UNESCAPED_SLASHES
                    ),
                    ':enabled' => $isEnabled,
                    ':encrypted' => $encrypted,
                    ':last4' => $last4,
                    ':id' => $id,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE ai_providers
                     SET
                        display_name = :display_name,
                        model_registry = :models,
                        is_enabled = :enabled,
                        updated_at = NOW()
                     WHERE id = :id'
                );

                $stmt->execute([
                    ':display_name' => $displayName,
                    ':models' => json_encode(
                        $models,
                        JSON_UNESCAPED_SLASHES
                    ),
                    ':enabled' => $isEnabled,
                    ':id' => $id,
                ]);
            }
        } catch (\Throwable $e) {
            Logger::error(
                'ai_provider.update_failed',
                [
                    'provider_id' => $id,
                    'credential_changed' => $credentialChanged,
                    'error_type' => get_class($e),
                ]
            );

            $_SESSION['_flash_error'] =
                'Provider could not be updated.';

            header("Location: /admin/ai-providers/{$id}/edit");
            exit;
        }

        Logger::audit(
            'ai_provider.updated',
            AuthService::userId(),
            'ai_provider',
            (string) $id,
            [
                'is_enabled' => $isEnabled,
                'credential_changed' => $credentialChanged,
            ]
        );

        $_SESSION['_flash_status'] = $credentialChanged
            ? 'Provider updated and API credential encrypted successfully.'
            : 'Provider updated. Existing API credential was unchanged.';

        header("Location: /admin/ai-providers/{$id}/edit");
        exit;
    }

    public function test(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] =
                'Your session expired. Please try again.';

            header(
                "Location: /admin/ai-providers/{$id}/edit"
            );
            exit;
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT id, name, api_key_encrypted
             FROM ai_providers
             WHERE id = :id'
        );

        $stmt->execute([':id' => $id]);

        $provider = $stmt->fetch();

        if (!$provider) {
            http_response_code(404);
            echo '404 — Provider not found';
            return;
        }

        if (empty($provider['api_key_encrypted'])) {
            $_SESSION['_flash_error'] =
                'No encrypted API credential is stored.';

            header(
                "Location: /admin/ai-providers/{$id}/edit"
            );
            exit;
        }

        try {
            $apiKey = Encryptor::decrypt(
                (string) $provider['api_key_encrypted']
            );
        } catch (\Throwable $e) {
            Logger::error(
                'ai_provider.credential_decrypt_failed',
                [
                    'provider_id' => $id,
                    'error_type' => get_class($e),
                ]
            );

            $_SESSION['_flash_error'] =
                'Stored credential could not be decrypted.';

            header(
                "Location: /admin/ai-providers/{$id}/edit"
            );
            exit;
        }

        $result = (new RagApiClient())
            ->providerConfigure($apiKey);

        unset($apiKey);

        $healthy =
            !empty($result['ok'])
            && !empty($result['healthy'])
            && !empty($result['configured']);

        Logger::audit(
            'ai_provider.credential_tested',
            AuthService::userId(),
            'ai_provider',
            (string) $id,
            [
                'healthy' => $healthy,
                'error_code' =>
                    $result['error_code']
                    ?? $result['error']
                    ?? null,
            ]
        );

        if (!$healthy) {
            $reason =
                $result['error_code']
                ?? $result['error']
                ?? 'provider_test_failed';

            $_SESSION['_flash_error'] =
                'Gemini credential test failed: '
                . $reason
                . '. The existing RAG runtime credential '
                . 'was left unchanged.';
        } else {
            $latency = isset($result['latency_ms'])
                ? (int) $result['latency_ms']
                : null;

            $model =
                (string) (
                    $result['generation_model']
                    ?? 'Gemini'
                );

            $_SESSION['_flash_status'] =
                'Gemini credential validated and applied '
                . 'to the running RAG service'
                . ($model !== ''
                    ? " ({$model})"
                    : '')
                . ($latency !== null
                    ? " — {$latency} ms."
                    : '.');
        }

        header(
            "Location: /admin/ai-providers/{$id}/edit"
        );
        exit;
    }


}
