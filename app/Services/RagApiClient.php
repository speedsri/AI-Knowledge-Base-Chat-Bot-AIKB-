<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Sole server-to-server client from AIKB to the RAG API (192.168.1.220:8500).
 *
 * SECURITY: RAG_INTERNAL_TOKEN is read once from Config (which itself reads
 * from .env via Env::get(), never hardcoded) and held only inside this
 * class, server-side. No caller of this class ever receives the token back
 * -- every public method here returns already-processed data, never the
 * raw Authorization header or config values. Nothing in admin/views/*
 * should ever construct a request to the RAG API directly; always go
 * through this class so there is exactly one place the token is used.
 *
 * FAIL-SAFE: every method catches its own transport/timeout errors and
 * returns a structured ['ok' => false, 'error' => ...] array rather than
 * throwing -- callers (SystemHealthController, RagSettingsController,
 * RagDiagnosticsController) must all work correctly when the RAG API is
 * completely offline, per the explicit "fail gracefully if RAG is
 * offline" requirement.
 */
final class RagApiClient
{
    private string $baseUrl;
    private string $internalToken;
    private int $timeoutSeconds;

    /** @var (callable(string,string,?array,bool):array)|null Injected only by tests. */
    private $transportOverride;

    public function __construct(?callable $transportOverride = null)
    {
        $this->baseUrl = rtrim((string) Config::get('rag.api_base', 'http://192.168.1.220:8500'), '/');
        $this->internalToken = (string) Config::get('rag.internal_token', '');
        $this->timeoutSeconds = (int) Config::get('rag.timeout_seconds', 8);
        $this->transportOverride = $transportOverride;
    }

    /**
     * GET /health -- no auth required by the RAG API for this endpoint.
     * @return array{ok: bool, status?: string, dependencies?: array, error?: string}
     */
    public function health(): array
    {
        return $this->request('GET', '/health', null, requiresAuth: false);
    }

    /**
     * POST /v1/provider/test -- requires auth. Never returns the Gemini key
     * (the RAG API itself never includes it in this response either).
     * @return array{ok: bool, provider?: string, configured?: bool, healthy?: bool,
     *               generation_model?: string, embedding_model?: string,
     *               embedding_dimension?: int, latency_ms?: int|null,
     *               error_code?: string|null, error?: string}
     */
    public function providerTest(): array
    {
        return $this->request('POST', '/v1/provider/test', []);
    }

    /**
     * Validates and applies a dashboard-managed Gemini credential
     * to the running RAG process.
     *
     * SECURITY:
     * - The key is sent only server-to-server.
     * - RagApiClient never logs request bodies.
     * - The RAG API never returns the key.
     */
    /**
     * Lightweight runtime credential-state check.
     * Does not contact Gemini and never returns a credential.
     */
    public function providerRuntime(): array
    {
        return $this->request('GET', '/v1/provider/runtime');
    }

    public function providerConfigure(string $apiKey): array
    {
        return $this->request(
            'POST',
            '/v1/provider/configure',
            ['api_key' => $apiKey]
        );
    }

    /**
     * POST /v1/chat -- sends one user turn to the RAG service.
     *
     * Conversation persistence is deliberately NOT handled by the RAG API.
     * AIKB/MySQL remains authoritative for conversation history.
     *
     * @param array{knowledge_base_id:int,conversation_ref:string,channel:string,message:string,history?:array} $payload
     */
    public function chat(array $payload): array
    {
        return $this->request('POST', '/v1/chat', $payload);
    }

    /**
     * POST /v1/config/sync -- pushes AIKB's authoritative rag_settings row
     * to the RAG API's in-process runtime config. Best-effort: the caller
     * (RagSettingsController) must treat a failure here as non-fatal --
     * MySQL remains the source of truth regardless of whether this push
     * succeeded.
     *
     * @param array{system_prompt?:string,temperature?:float,top_k_retrieval?:int,
     *              similarity_threshold?:float,escalation_keywords?:array} $fields
     */
    public function configSync(array $fields): array
    {
        return $this->request('POST', '/v1/config/sync', $fields);
    }

    /**
     * GET /v1/config/current -- diagnostic read of whatever the RAG API
     * currently has synced (not used in the main save flow, available for
     * a future "verify sync" admin action).
     */
    public function configCurrent(): array
    {
        return $this->request('GET', '/v1/config/current');
    }

    // =====================================================================
    // V2 RECONCILIATION: per the confirmed live-server report,
    // reindexDocumentVersion() and deleteDocumentVersion() already exist
    // on the live server as part of Phase B.2. Both are reconstructed here
    // with the EXACT names/signatures reported (reindexDocumentVersion(array
    // $payload), deleteDocumentVersion(int $documentVersionId)) so this
    // file is a drop-in match rather than a guess. health(), providerTest(),
    // configSync(), configCurrent() are unchanged from Phase B.1. Only
    // ingestionStart(), ingestionStatus(), ingestionResult() are genuinely
    // new in Phase C.
    // =====================================================================

    /**
     * POST /v1/ingestion/start -- triggers a crawl on the RAG backend.
     * Returns immediately with a job_id; the crawl itself runs
     * asynchronously on the RAG side. Pass $externalJobId (typically the
     * website_crawl_runs.id, as a string) so the RAG job can be
     * cross-referenced without the RAG API ever writing to MySQL itself.
     *
     * @param array{knowledge_base_id:int, origin_url:string, max_pages?:int,
     *              crawl_delay_seconds?:float, request_timeout_seconds?:float,
     *              max_document_size_kb?:int, excluded_path_patterns?:array} $params
     */
    public function ingestionStart(array $params, ?string $externalJobId = null): array
    {
        if ($externalJobId !== null) {
            $params['external_job_id'] = $externalJobId;
        }
        return $this->request('POST', '/v1/ingestion/start', $params);
    }

    /** GET /v1/ingestion/status/{job_id} */
    public function ingestionStatus(string $jobId): array
    {
        return $this->request('GET', '/v1/ingestion/status/' . rawurlencode($jobId));
    }

    /**
     * GET /v1/ingestion/result/{job_id} -- the handshake point: fetches
     * prepared (crawled + normalized + hashed) pages for AIKB to import
     * into MySQL. Never returns anything Qdrant-related; the RAG side has
     * not indexed any of this yet.
     */
    public function ingestionResult(string $jobId): array
    {
        return $this->request('GET', '/v1/ingestion/result/' . rawurlencode($jobId));
    }

    /**
     * POST /v1/reindex -- indexes ONE authoritative MySQL document
     * version into Qdrant. Must only ever be called AFTER the
     * corresponding MySQL write (document + document_versions row) has
     * been committed -- see app/Services/WebsiteCrawlImporter.php, which
     * is the only caller of this method in the Phase C code path.
     *
     * @param array{knowledge_base_id:int, document_id:int, document_version_id:int,
     *              title:string, canonical_url:?string, normalized_content:string,
     *              content_hash:string, is_published:bool} $payload
     */
    public function reindexDocumentVersion(array $payload): array
    {
        return $this->request('POST', '/v1/reindex', $payload);
    }

    /**
     * POST /v1/document-version/delete -- explicit removal of a SPECIFIC
     * document_version_id's vectors. Reconstructed here to match the
     * live-server report exactly (method name and signature). CRITICAL
     * ordering requirement (see app/Services/WebsiteCrawlImporter.php):
     * this must ONLY be called AFTER a replacement version's own
     * reindexDocumentVersion() call has already succeeded -- /v1/reindex
     * does NOT clean up any OTHER document_version_id's vectors, only
     * stale chunks belonging to the version_id it was just given.
     */
    public function deleteDocumentVersion(int $documentVersionId): array
    {
        return $this->request('POST', '/v1/document-version/delete', ['document_version_id' => $documentVersionId]);
    }

    private function request(string $method, string $path, ?array $body = null, bool $requiresAuth = true): array
    {
        if ($requiresAuth && $this->internalToken === '') {
            // Fail safe and loud in logs (not to the browser) if the token
            // was never configured -- this is a deployment/config error,
            // not a "RAG is offline" condition, and the two should not be
            // confused when reading logs later.
            Logger::error('rag_api.token_not_configured', ['path' => $path]);
            return ['ok' => false, 'error' => 'rag_internal_token_not_configured'];
        }

        if ($this->transportOverride !== null) {
            // Test-only path: bypasses curl entirely so unit tests never
            // touch the network. Production code never sets this.
            return ($this->transportOverride)($method, $path, $body, $requiresAuth);
        }

        return $this->sendViaCurl($method, $path, $body, $requiresAuth);
    }

    private function sendViaCurl(string $method, string $path, ?array $body, bool $requiresAuth): array
    {
        $url = $this->baseUrl . $path;
        $headers = ['Content-Type: application/json'];
        if ($requiresAuth) {
            $headers[] = 'Authorization: Bearer ' . $this->internalToken;
        }

        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErrNo !== 0) {
            // This is the expected, normal path when the RAG API is
            // offline -- log at INFO, not ERROR, since it is not
            // necessarily a misconfiguration (the service may simply be
            // down for maintenance).
            Logger::info('rag_api.unreachable', ['path' => $path, 'curl_errno' => $curlErrNo]);
            return ['ok' => false, 'error' => 'rag_api_unreachable'];
        }

        if ($httpCode === 401 || $httpCode === 403) {
            // Never log the token itself, only the fact that auth failed.
            Logger::error('rag_api.auth_failed', ['path' => $path, 'http_code' => $httpCode]);
            return ['ok' => false, 'error' => 'rag_api_auth_failed', 'http_code' => $httpCode];
        }

        if ($httpCode >= 400) {
            Logger::error('rag_api.error_response', ['path' => $path, 'http_code' => $httpCode]);
            return ['ok' => false, 'error' => 'rag_api_error', 'http_code' => $httpCode];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            Logger::error('rag_api.invalid_json', ['path' => $path]);
            return ['ok' => false, 'error' => 'rag_api_invalid_response'];
        }

        $decoded['ok'] = true;
        return $decoded;
    }
}
