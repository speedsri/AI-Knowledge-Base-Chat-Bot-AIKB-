<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use App\Services\RagApiClient;
use App\Services\WebsiteCrawlImporter;

final class WebsiteSourceController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $sources = $pdo->query(
            'SELECT ws.*, kb.name AS kb_name
             FROM website_sources ws
             INNER JOIN knowledge_bases kb ON kb.id = ws.knowledge_base_id
             ORDER BY ws.created_at DESC'
        )->fetchAll();
        $knowledgeBases = $pdo->query('SELECT id, name FROM knowledge_bases WHERE is_active = 1 ORDER BY name')->fetchAll();

        // PHASE C: attach the latest crawl run (if any) for each source so
        // the index view can show a Crawl Now / Check Status button and a
        // one-line summary without a second round-trip per row.
        $latestRuns = [];
        if (!empty($sources)) {
            $sourceIds = array_column($sources, 'id');
            $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
            $runsStmt = $pdo->prepare(
                "SELECT r1.* FROM website_crawl_runs r1
                 INNER JOIN (
                     SELECT website_source_id, MAX(id) AS max_id
                     FROM website_crawl_runs
                     WHERE website_source_id IN ({$placeholders})
                     GROUP BY website_source_id
                 ) r2 ON r2.website_source_id = r1.website_source_id AND r2.max_id = r1.id"
            );
            $runsStmt->execute($sourceIds);
            foreach ($runsStmt->fetchAll() as $run) {
                $latestRuns[(int) $run['website_source_id']] = $run;
            }
        }

        View::render('website_sources/index', [
            'title' => 'Website Sources',
            'sources' => $sources,
            'knowledgeBases' => $knowledgeBases,
            'latestRuns' => $latestRuns,
            'error' => $_SESSION['_flash_error'] ?? null,
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error'], $_SESSION['_flash_status']);
    }

    public function store(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/website-sources');
            exit;
        }

        [$data, $validationError] = $this->extractAndValidate($_POST);
        if ($validationError !== null) {
            $_SESSION['_flash_error'] = $validationError;
            header('Location: /admin/website-sources');
            exit;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO website_sources
                (knowledge_base_id, origin_url, label, max_pages, crawl_delay_seconds,
                 request_timeout_seconds, max_document_size_kb, excluded_path_patterns,
                 crawl_frequency, status, created_by, created_at)
             VALUES
                (:kb_id, :origin_url, :label, :max_pages, :crawl_delay,
                 :request_timeout, :max_doc_size, :excluded, :frequency, \'active\', :created_by, NOW())'
        );
        $stmt->execute([
            ':kb_id' => $data['knowledge_base_id'],
            ':origin_url' => $data['origin_url'],
            ':label' => $data['label'],
            ':max_pages' => $data['max_pages'],
            ':crawl_delay' => $data['crawl_delay_seconds'],
            ':request_timeout' => $data['request_timeout_seconds'],
            ':max_doc_size' => $data['max_document_size_kb'],
            ':excluded' => json_encode($data['excluded_path_patterns'], JSON_UNESCAPED_SLASHES),
            ':frequency' => $data['crawl_frequency'],
            ':created_by' => AuthService::userId(),
        ]);
        $id = (int) $pdo->lastInsertId();

        Logger::audit('website_source.created', AuthService::userId(), 'website_source', (string) $id, [
            'origin_url' => $data['origin_url'],
        ]);

        $_SESSION['_flash_status'] = 'Website source added. Use "Crawl Now" to fetch and index its pages.';
        header('Location: /admin/website-sources');
        exit;
    }

    /**
     * PHASE C: POST /admin/website-sources/{id}/crawl -- starts a crawl.
     * Server-to-server only (via WebsiteCrawlImporter -> RagApiClient);
     * RAG_INTERNAL_TOKEN never reaches this method's caller (the browser).
     */
    public function crawl(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/website-sources');
            exit;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM website_sources WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $source = $stmt->fetch();

        if (!$source) {
            http_response_code(404);
            echo '404 — Website source not found';
            return;
        }

        if ($source['status'] === 'paused') {
            $_SESSION['_flash_error'] = 'This source is paused. Resume it (edit -> Status -> Active) before crawling.';
            header('Location: /admin/website-sources');
            exit;
        }

        $importer = new WebsiteCrawlImporter(new RagApiClient());
        $result = $importer->startCrawl($source, AuthService::userId());

        if (empty($result['ok'])) {
            if (($result['error'] ?? null) === 'crawl_already_running') {
                $_SESSION['_flash_error'] = 'A crawl is already running for this source. Use "Check Status" to see its progress.';
            } else {
                $_SESSION['_flash_error'] = 'Could not start the crawl (RAG backend unreachable or rejected the request). '
                    . 'See System Health for current RAG backend status.';
            }
        } else {
            $_SESSION['_flash_status'] = 'Crawl started. Click "Check Status" below once it has had time to run.';
        }

        header('Location: /admin/website-sources');
        exit;
    }

    /**
     * PHASE C: POST /admin/website-sources/{id}/crawl-check -- the
     * explicit, server-rendered "admin refresh" polling action (no
     * JavaScript required). Safe to click repeatedly: pollAndImport() is a
     * no-op once a run is already finalized.
     */
    public function crawlCheck(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/website-sources');
            exit;
        }

        $pdo = Database::connection();
        $runStmt = $pdo->prepare(
            'SELECT id FROM website_crawl_runs WHERE website_source_id = :id ORDER BY id DESC LIMIT 1'
        );
        $runStmt->execute([':id' => $id]);
        $run = $runStmt->fetch();

        if (!$run) {
            $_SESSION['_flash_error'] = 'No crawl has been started for this source yet.';
            header('Location: /admin/website-sources');
            exit;
        }

        $importer = new WebsiteCrawlImporter(new RagApiClient());
        $result = $importer->pollAndImport((int) $run['id']);

        if (empty($result['ok'])) {
            $_SESSION['_flash_error'] = $result['error'] ?? 'Could not check crawl status right now. Try again shortly.';
        } elseif ($result['status'] === 'running') {
            $_SESSION['_flash_status'] = 'Crawl is still running. Check again in a moment.';
        } elseif ($result['status'] === 'failed') {
            $_SESSION['_flash_error'] = 'Crawl failed: ' . ($result['summary']['error_message'] ?? 'unknown error');
        } else {
            $summary = $result['summary'];
            $message = sprintf(
                'Crawl completed — %d new, %d changed, %d unchanged, %d failed, %d unpublished (stale).',
                $summary['documents_new'] ?? 0,
                $summary['documents_changed'] ?? 0,
                $summary['documents_unchanged'] ?? 0,
                $summary['documents_failed'] ?? 0,
                $summary['documents_stale'] ?? 0,
            );
            if (!empty($summary['documents_indexing_failed'])) {
                $message .= sprintf(
                    ' %d page(s) imported into MySQL successfully but failed to index for search — check System Health/logs.',
                    $summary['documents_indexing_failed']
                );
            }
            if (!empty($summary['cleanup_needed'])) {
                $message .= " Note: cleanup of one or more superseded versions' old search index entries failed and may need manual attention (content itself is correct).";
            }
            if (!empty($summary['documents_stale_failed'])) {
                $message .= sprintf(
                    ' %d removed page(s) could NOT be safely unpublished from search — they remain published and searchable, unchanged; this will be retried on the next crawl.',
                    $summary['documents_stale_failed']
                );
            }
            $_SESSION['_flash_status'] = $message;
        }

        header('Location: /admin/website-sources');
        exit;
    }

    public function edit(int $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM website_sources WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $source = $stmt->fetch();

        if (!$source) {
            http_response_code(404);
            echo '404 — Website source not found';
            return;
        }

        $knowledgeBases = $pdo->query('SELECT id, name FROM knowledge_bases ORDER BY name')->fetchAll();

        // BLOCKER C (V3): tells the view whether to lock the Knowledge
        // Base / Origin URL fields for UX. The server-side refusal in
        // update() is authoritative regardless of what this renders.
        $importer = new WebsiteCrawlImporter(new RagApiClient());
        $importedCount = $importer->countImportedDocuments($id);

        View::render('website_sources/edit', [
            'title' => 'Edit Website Source',
            'source' => $source,
            'knowledgeBases' => $knowledgeBases,
            'importedCount' => $importedCount,
            'error' => $_SESSION['_flash_error'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error']);
    }

    public function update(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header("Location: /admin/website-sources/{$id}/edit");
            exit;
        }

        [$data, $validationError] = $this->extractAndValidate($_POST);
        if ($validationError !== null) {
            $_SESSION['_flash_error'] = $validationError;
            header("Location: /admin/website-sources/{$id}/edit");
            exit;
        }

        $status = in_array($_POST['status'] ?? '', ['active', 'paused'], true) ? $_POST['status'] : 'active';

        $pdo = Database::connection();

        // BLOCKER C (V3): once a source has imported documents,
        // knowledge_base_id and origin_url must never change -- a later
        // reindex would then use a knowledge_base_id/origin_url that
        // disagrees with the documents already authoritatively stored
        // under the OLD values, silently corrupting which knowledge base
        // Qdrant associates this content with. Enforced server-side
        // (not merely by disabling the fields in the edit form), by
        // comparing against the CURRENT stored row, not trusting any
        // hidden/disabled form field value the browser might submit.
        $importer = new WebsiteCrawlImporter(new RagApiClient());
        $importedCount = $importer->countImportedDocuments($id);
        if ($importedCount > 0) {
            $currentStmt = $pdo->prepare('SELECT knowledge_base_id, origin_url FROM website_sources WHERE id = :id');
            $currentStmt->execute([':id' => $id]);
            $current = $currentStmt->fetch();

            if ($current && (
                (int) $current['knowledge_base_id'] !== (int) $data['knowledge_base_id']
                || $current['origin_url'] !== $data['origin_url']
            )) {
                $_SESSION['_flash_error'] = 'Knowledge Base and Origin URL cannot be changed after documents have '
                    . 'been imported. Create a new Website Source instead.';
                header("Location: /admin/website-sources/{$id}/edit");
                exit;
            }

            // Force these two fields to their existing stored values
            // regardless of what was submitted -- belt-and-suspenders on
            // top of the explicit refusal above, in case validation logic
            // upstream ever changes.
            $data['knowledge_base_id'] = (int) $current['knowledge_base_id'];
            $data['origin_url'] = $current['origin_url'];
        }

        $stmt = $pdo->prepare(
            'UPDATE website_sources SET
                knowledge_base_id = :kb_id, origin_url = :origin_url, label = :label,
                max_pages = :max_pages, crawl_delay_seconds = :crawl_delay,
                request_timeout_seconds = :request_timeout, max_document_size_kb = :max_doc_size,
                excluded_path_patterns = :excluded, crawl_frequency = :frequency,
                status = :status, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':kb_id' => $data['knowledge_base_id'],
            ':origin_url' => $data['origin_url'],
            ':label' => $data['label'],
            ':max_pages' => $data['max_pages'],
            ':crawl_delay' => $data['crawl_delay_seconds'],
            ':request_timeout' => $data['request_timeout_seconds'],
            ':max_doc_size' => $data['max_document_size_kb'],
            ':excluded' => json_encode($data['excluded_path_patterns'], JSON_UNESCAPED_SLASHES),
            ':frequency' => $data['crawl_frequency'],
            ':status' => $status,
            ':id' => $id,
        ]);

        Logger::audit('website_source.updated', AuthService::userId(), 'website_source', (string) $id, [
            'origin_url' => $data['origin_url'], 'status' => $status,
        ]);

        $_SESSION['_flash_status'] = 'Website source updated.';
        header('Location: /admin/website-sources');
        exit;
    }

    public function delete(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/website-sources');
            exit;
        }

        // BLOCKER 2: refuse deletion while imported documents still
        // reference this source, rather than silently orphaning them via
        // documents.website_source_id's ON DELETE SET NULL. An admin must
        // explicitly deal with those documents first (unpublish/reassign/
        // delete them individually via the Documents screen).
        $importer = new WebsiteCrawlImporter(new RagApiClient());
        $importedCount = $importer->countImportedDocuments($id);
        if ($importedCount > 0) {
            $_SESSION['_flash_error'] = sprintf(
                'Cannot delete this source: %d document(s) were imported from it. '
                . 'Unpublish or reassign them via the Documents screen first.',
                $importedCount
            );
            header('Location: /admin/website-sources');
            exit;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM website_sources WHERE id = :id');
        $stmt->execute([':id' => $id]);

        Logger::audit('website_source.deleted', AuthService::userId(), 'website_source', (string) $id, []);

        $_SESSION['_flash_status'] = 'Website source deleted.';
        header('Location: /admin/website-sources');
        exit;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{0: array<string, mixed>, 1: string|null} [data, validationError]
     */
    private function extractAndValidate(array $post): array
    {
        $knowledgeBaseId = (int) ($post['knowledge_base_id'] ?? 0);
        $originUrl = trim((string) ($post['origin_url'] ?? ''));
        $label = trim((string) ($post['label'] ?? '')) ?: null;
        $maxPages = max(1, min(2000, (int) ($post['max_pages'] ?? 200)));
        $crawlDelay = max(0.5, min(30.0, (float) ($post['crawl_delay_seconds'] ?? 1.5)));
        $requestTimeout = max(5, min(120, (int) ($post['request_timeout_seconds'] ?? 20)));
        $maxDocSize = max(64, min(20480, (int) ($post['max_document_size_kb'] ?? 2048)));
        $frequency = in_array($post['crawl_frequency'] ?? '', ['manual', 'daily', 'weekly', 'monthly'], true)
            ? $post['crawl_frequency'] : 'weekly';

        $excludedRaw = trim((string) ($post['excluded_path_patterns'] ?? ''));
        $excluded = $excludedRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode("\n", $excludedRaw))));

        if ($knowledgeBaseId <= 0 || $originUrl === '') {
            return [[], 'Please choose a knowledge base and provide a URL.'];
        }
        if (!filter_var($originUrl, FILTER_VALIDATE_URL)) {
            return [[], 'Please provide a valid URL.'];
        }

        return [[
            'knowledge_base_id' => $knowledgeBaseId,
            'origin_url' => $originUrl,
            'label' => $label,
            'max_pages' => $maxPages,
            'crawl_delay_seconds' => $crawlDelay,
            'request_timeout_seconds' => $requestTimeout,
            'max_document_size_kb' => $maxDocSize,
            'excluded_path_patterns' => $excluded,
            'crawl_frequency' => $frequency,
        ], null];
    }
}
