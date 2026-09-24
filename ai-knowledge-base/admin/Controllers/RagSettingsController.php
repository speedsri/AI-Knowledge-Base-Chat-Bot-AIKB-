<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use App\Services\RagApiClient;

final class RagSettingsController
{
    public function edit(): void
    {
        $pdo = Database::connection();
        $settings = $pdo->query('SELECT * FROM rag_settings WHERE id = 1')->fetch();
        $providers = $pdo->query('SELECT id, name, display_name FROM ai_providers WHERE is_enabled = 1 ORDER BY name')->fetchAll();

        View::render('rag_settings/edit', [
            'title' => 'RAG Settings',
            'settings' => $settings,
            'providers' => $providers,
            'error' => $_SESSION['_flash_error'] ?? null,
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error'], $_SESSION['_flash_status']);
    }

    public function update(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/rag-settings');
            exit;
        }

        $activeProviderId = !empty($_POST['active_provider_id']) ? (int) $_POST['active_provider_id'] : null;
        $activeModel = trim((string) ($_POST['active_model'] ?? ''));
        $fallbackModel = trim((string) ($_POST['fallback_model'] ?? ''));
        $systemPrompt = trim((string) ($_POST['system_prompt'] ?? ''));
        $temperature = max(0.0, min(1.0, (float) ($_POST['temperature'] ?? 0.4)));
        $topK = max(1, min(20, (int) ($_POST['top_k_retrieval'] ?? 5)));
        $similarityThreshold = max(0.0, min(1.0, (float) ($_POST['similarity_threshold'] ?? 0.72)));

        $keywordsRaw = trim((string) ($_POST['escalation_keywords'] ?? ''));
        $keywords = $keywordsRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $keywordsRaw))));

        if ($activeModel === '' || $fallbackModel === '' || $systemPrompt === '') {
            $_SESSION['_flash_error'] = 'Please provide an active model, fallback model, and system prompt.';
            header('Location: /admin/rag-settings');
            exit;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE rag_settings SET
                active_provider_id = :provider_id, active_model = :active_model, fallback_model = :fallback_model,
                system_prompt = :system_prompt, temperature = :temperature, top_k_retrieval = :top_k,
                similarity_threshold = :similarity_threshold, escalation_keywords = :keywords,
                updated_by = :updated_by, updated_at = NOW()
             WHERE id = 1'
        );
        $stmt->execute([
            ':provider_id' => $activeProviderId,
            ':active_model' => $activeModel,
            ':fallback_model' => $fallbackModel,
            ':system_prompt' => $systemPrompt,
            ':temperature' => $temperature,
            ':top_k' => $topK,
            ':similarity_threshold' => $similarityThreshold,
            ':keywords' => json_encode($keywords, JSON_UNESCAPED_SLASHES),
            ':updated_by' => AuthService::userId(),
        ]);

        Logger::audit('rag_settings.updated', AuthService::userId(), 'rag_settings', '1', [
            'active_model' => $activeModel, 'fallback_model' => $fallbackModel,
        ]);

        // Phase B.1: best-effort push to the live RAG API so the change
        // takes effect immediately, without making MySQL's save depend on
        // the RAG API being reachable. MySQL (above) is unconditionally
        // the source of truth -- this push is a convenience so an admin
        // doesn't have to wait for some future poll/resync cycle.
        $ragClient = new RagApiClient();
        $syncResult = $ragClient->configSync([
            'system_prompt' => $systemPrompt,
            'temperature' => $temperature,
            'top_k_retrieval' => $topK,
            'similarity_threshold' => $similarityThreshold,
            'escalation_keywords' => $keywords,
        ]);

        if (!empty($syncResult['ok'])) {
            Logger::audit('rag_settings.synced_to_rag_api', AuthService::userId(), 'rag_settings', '1', []);
            $_SESSION['_flash_status'] = 'RAG settings updated and synced to the live RAG backend.';
        } else {
            // Non-fatal by design -- the settings ARE saved in MySQL
            // regardless. Logger::error already recorded the specific
            // reason inside RagApiClient; the flash message here is
            // deliberately generic (no internal error codes shown to the
            // browser) but honest that live sync did not happen.
            Logger::warning('rag_settings.sync_failed', ['reason' => $syncResult['error'] ?? 'unknown']);
            $_SESSION['_flash_status'] =
            'RAG settings were saved to MySQL, but the live RAG backend could not be synced. '
            . 'The backend may still be using its previous runtime settings. '
            . 'Please retry Save when the RAG backend is reachable, and check System Health.';

        }

        header('Location: /admin/rag-settings');
        exit;
    }
}

