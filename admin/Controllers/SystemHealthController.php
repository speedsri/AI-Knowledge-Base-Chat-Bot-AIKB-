<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\View;
use App\Services\RagApiClient;

/**
 * V2 correction (Phase B.1 review item 1): storage path fixed back to
 * dirname(__DIR__, 2) -- this file lives at admin/Controllers/, so two
 * levels up is the application root, matching the already-live Phase A
 * fix. The V1 delivery of this file regressed this to dirname(__DIR__, 3),
 * an old known bug -- corrected here, not reintroduced.
 *
 * V2 correction (Phase B.1 review item 4): the Gemini provider row is now
 * derived ENTIRELY from GET /health's `dependencies.gemini` field
 * (`configured` / `not_configured` -- a cheap, no-cost check the RAG API
 * already does on every /health call), NOT from POST /v1/provider/test.
 * The V1 delivery called providerTest() on every single System Health
 * page load, which performs a real embedding call against Gemini and
 * consumes API usage on every refresh -- removed entirely from this
 * routine path. The full, real provider health check (embedding call,
 * latency, dimension confirmation) remains available on-demand via the
 * existing "Test Connection" button on the RAG Settings page
 * (RagDiagnosticsController::test(), unchanged), which is the ONLY place
 * in this codebase that calls /v1/provider/test.
 *
 * rag_api / qdrant rows still call GET /health only -- unchanged from V1,
 * already cost-free (no Gemini call is made by the RAG API's /health
 * handler itself).
 */
final class SystemHealthController
{
    public function index(): void
    {
        $ragClient = new RagApiClient();
        $health = $ragClient->health();

        $checks = [
            'mysql' => $this->checkMysql(),
            'storage' => $this->checkStorageWritable(),
            'background_jobs' => $this->checkBackgroundJobs(),
            'environment' => $this->checkEnvironment(),
        ];

        $ragChecks = [
            'rag_api' => $this->deriveRagApiStatus($health),
            'qdrant' => $this->deriveQdrantStatus($health),
            'gemini_provider' => $this->deriveProviderConfiguredStatus($health),
        ];

        View::render('system_health/index', [
            'title' => 'System Health',
            'checks' => $checks,
            'ragChecks' => $ragChecks,
        ], 'layouts/base');
    }

    /** @return array{status: string, detail: string} */
    private function deriveRagApiStatus(array $health): array
    {
        if (empty($health['ok'])) {
            return ['status' => 'error', 'detail' => 'Unreachable at ' . (string) Config::get('rag.api_base')];
        }
        $overall = $health['status'] ?? 'unknown';
        return [
            'status' => $overall === 'ok' ? 'ok' : 'warning',
            'detail' => "rag-api reports status={$overall}",
        ];
    }

    /** @return array{status: string, detail: string} */
    private function deriveQdrantStatus(array $health): array
    {
        if (empty($health['ok'])) {
            return ['status' => 'error', 'detail' => 'Unknown -- rag-api unreachable'];
        }
        $qdrantStatus = $health['dependencies']['qdrant'] ?? 'unknown';
        return [
            'status' => $qdrantStatus === 'ok' ? 'ok' : 'error',
            'detail' => "Reported by rag-api: {$qdrantStatus}",
        ];
    }

    /**
     * V2: configuration-presence check ONLY (no real Gemini call). Derived
     * from /health's dependencies.gemini field, which the RAG API already
     * computes cheaply (checks whether GEMINI_API_KEY is set, does not
     * call the Gemini API itself). For an actual live health check
     * (embedding call, latency, dimension confirmation), use "Test
     * Connection" on the RAG Settings page.
     * @return array{status: string, detail: string}
     */
    private function deriveProviderConfiguredStatus(array $health): array
    {
        if (empty($health['ok'])) {
            return ['status' => 'error', 'detail' => 'Unable to reach rag-api to check provider configuration'];
        }
        $geminiStatus = $health['dependencies']['gemini'] ?? 'unknown';
        if ($geminiStatus === 'configured') {
            return ['status' => 'ok', 'detail' => 'Configured. Use "Test Connection" on RAG Settings for a live health check.'];
        }
        if ($geminiStatus === 'not_configured') {
            return ['status' => 'warning', 'detail' => 'Gemini API key not configured on the RAG backend'];
        }
        return ['status' => 'warning', 'detail' => "Reported by rag-api: {$geminiStatus}"];
    }

    /** @return array{status: string, detail: string} */
    private function checkMysql(): array
    {
        try {
            Database::connection()->query('SELECT 1');
            return ['status' => 'ok', 'detail' => 'Connected'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'detail' => 'Unable to connect'];
        }
    }

    /** @return array{status: string, detail: string} */
    private function checkStorageWritable(): array
    {
        // V2 CORRECTION (review item 1): this file is at admin/Controllers/,
        // so 2 levels up (not 3) is the application root. Restored to
        // match the already-live Phase A fix -- do not change back to 3.
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return ['status' => 'error', 'detail' => "Cannot create {$dir}"];
        }
        return is_writable($dir)
            ? ['status' => 'ok', 'detail' => 'storage/logs is writable']
            : ['status' => 'error', 'detail' => 'storage/logs is not writable'];
    }

    /** @return array{status: string, detail: string} */
    private function checkBackgroundJobs(): array
    {
        try {
            $pdo = Database::connection();
            $counts = $pdo->query(
                "SELECT status, COUNT(*) AS c FROM background_jobs GROUP BY status"
            )->fetchAll();

            $byStatus = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
            foreach ($counts as $row) {
                $byStatus[$row['status']] = (int) $row['c'];
            }

            $detail = sprintf(
                'pending: %d, running: %d, done: %d, failed: %d',
                $byStatus['pending'], $byStatus['running'], $byStatus['done'], $byStatus['failed']
            );

            $status = $byStatus['failed'] > 0 ? 'warning' : 'ok';
            return ['status' => $status, 'detail' => $detail];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'detail' => 'Unable to read background_jobs'];
        }
    }

    /** @return array{status: string, detail: string} */
    private function checkEnvironment(): array
    {
        $env = (string) Config::get('app.env', 'unknown');
        $debug = (bool) Config::get('app.debug', false);
        $detail = "env={$env}, debug=" . ($debug ? 'on' : 'off');
        $status = ($env === 'production' && $debug) ? 'warning' : 'ok';
        return ['status' => $status, 'detail' => $detail];
    }
}
