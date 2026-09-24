<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\Csrf;
use App\Services\RagApiClient;

/**
 * Admin-only diagnostic action: "Test Connection" on the RAG Settings page.
 * Deliberately separate from RagSettingsController so a diagnostic call
 * (which may legitimately fail if the RAG API is down) can never interfere
 * with the settings-save flow. Gated by the SAME ai.configure permission
 * as the rest of the RAG Settings page (see public/index.php) -- no new
 * permission key introduced.
 *
 * Returns JSON only. Never includes the Gemini API key or
 * RAG_INTERNAL_TOKEN in the response -- the underlying RagApiClient call
 * (POST /v1/provider/test) does not return the key either.
 */
final class RagDiagnosticsController
{
    public function test(): void
    {
        header('Content-Type: application/json');

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'csrf_token_invalid']);
            return;
        }

        $ragClient = new RagApiClient();
        $result = $ragClient->providerTest();

        // Explicit allow-list of fields returned to the browser -- even
        // though RagApiClient/the RAG API itself never include the key,
        // this is a second, independent safeguard against any future
        // field ever being added upstream and accidentally forwarded here.
        $safeFields = [
            'ok' => $result['ok'] ?? false,
            'configured' => $result['configured'] ?? null,
            'healthy' => $result['healthy'] ?? null,
            'generation_model' => $result['generation_model'] ?? null,
            'embedding_model' => $result['embedding_model'] ?? null,
            'embedding_dimension' => $result['embedding_dimension'] ?? null,
            'latency_ms' => $result['latency_ms'] ?? null,
            'error_code' => $result['error_code'] ?? ($result['error'] ?? null),
        ];

        echo json_encode($safeFields);
    }
}
