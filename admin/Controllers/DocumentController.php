<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use App\Services\RagApiClient;
use PDO;

final class DocumentController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $kbFilter = isset($_GET['kb']) ? (int) $_GET['kb'] : null;
        $categoryFilter = isset($_GET['category']) ? (int) $_GET['category'] : null;

        $sql = 'SELECT d.*, kb.name AS kb_name, c.name AS category_name,
                       dv.version AS current_version, dv.content_hash AS current_content_hash,
                       dv.canonical_url AS current_canonical_url
                FROM documents d
                INNER JOIN knowledge_bases kb ON kb.id = d.knowledge_base_id
                LEFT JOIN categories c ON c.id = d.category_id
                LEFT JOIN document_versions dv ON dv.id = d.current_version_id
                WHERE 1 = 1';
        $params = [];
        if ($kbFilter !== null) {
            $sql .= ' AND d.knowledge_base_id = :kb';
            $params[':kb'] = $kbFilter;
        }
        if ($categoryFilter !== null) {
            $sql .= ' AND d.category_id = :category';
            $params[':category'] = $categoryFilter;
        }
        $sql .= ' ORDER BY d.updated_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll();

        $knowledgeBases = $pdo->query('SELECT id, name FROM knowledge_bases ORDER BY name')->fetchAll();

        View::render('documents/index', [
            'title' => 'Documents',
            'documents' => $documents,
            'knowledgeBases' => $knowledgeBases,
            'kbFilter' => $kbFilter,
            'categoryFilter' => $categoryFilter,
            'error' => $_SESSION['_flash_error'] ?? null,
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error'], $_SESSION['_flash_status']);
    }

    public function show(int $id): void
    {
        $document = $this->findDocumentOrFail($id);
        if ($document === null) {
            return;
        }

        $pdo = Database::connection();
        $versionsStmt = $pdo->prepare(
            'SELECT * FROM document_versions WHERE document_id = :id ORDER BY version DESC'
        );
        $versionsStmt->execute([':id' => $id]);
        $versions = $versionsStmt->fetchAll();

        View::render('documents/show', [
            'title' => $document['title'],
            'document' => $document,
            'versions' => $versions,
            'status' => $_SESSION['_flash_status'] ?? null,
            'error' => $_SESSION['_flash_error'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error'], $_SESSION['_flash_status']);
    }

    public function create(): void
    {
        $pdo = Database::connection();
        $knowledgeBases = $pdo->query('SELECT id, name FROM knowledge_bases WHERE is_active = 1 ORDER BY name')->fetchAll();
        $categories = $pdo->query('SELECT id, knowledge_base_id, name FROM categories ORDER BY name')->fetchAll();

        View::render('documents/create', [
            'title' => 'New Document',
            'knowledgeBases' => $knowledgeBases,
            'categories' => $categories,
            'error' => $_SESSION['_flash_error'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error']);
    }

    public function store(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/documents/create');
            exit;
        }

        $knowledgeBaseId = (int) ($_POST['knowledge_base_id'] ?? 0);
        $categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;
        $title = trim((string) ($_POST['title'] ?? ''));
        $canonicalUrl = trim((string) ($_POST['canonical_url'] ?? '')) ?: null;
        $normalizedContent = (string) ($_POST['normalized_content'] ?? '');

        if ($knowledgeBaseId <= 0 || $title === '' || trim($normalizedContent) === '') {
            $_SESSION['_flash_error'] = 'Please choose a knowledge base and provide a title and content.';
            header('Location: /admin/documents/create');
            exit;
        }

        $pdo = Database::connection();
        $userId = AuthService::userId();

        $pdo->beginTransaction();
        try {
            $docStmt = $pdo->prepare(
                'INSERT INTO documents (knowledge_base_id, category_id, title, source_type, is_published, created_by, created_at)
                 VALUES (:kb_id, :category_id, :title, \'manual\', 1, :created_by, NOW())'
            );
            $docStmt->execute([
                ':kb_id' => $knowledgeBaseId,
                ':category_id' => $categoryId,
                ':title' => $title,
                ':created_by' => $userId,
            ]);
            $documentId = (int) $pdo->lastInsertId();

            $versionId = $this->insertFirstVersion($pdo, $documentId, $normalizedContent, $canonicalUrl, $userId);

            $updateStmt = $pdo->prepare('UPDATE documents SET current_version_id = :version_id WHERE id = :id');
            $updateStmt->execute([':version_id' => $versionId, ':id' => $documentId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Logger::error('document.create_failed', ['message' => $e->getMessage()]);
            $_SESSION['_flash_error'] = 'Could not create the document. Please try again.';
            header('Location: /admin/documents/create');
            exit;
        }

        $ragIndexed = $this->reindexDocumentVersion(
            [
                'id' => $documentId,
                'knowledge_base_id' => $knowledgeBaseId,
                'title' => $title,
                'is_published' => 1,
            ],
            $versionId,
            $normalizedContent,
            hash('sha256', $normalizedContent),
            $canonicalUrl
        );

        Logger::audit('document.created', $userId, 'document', (string) $documentId, [
            'title' => $title, 'knowledge_base_id' => $knowledgeBaseId,
        ]);

        if ($ragIndexed) {
            $_SESSION['_flash_status'] = "Document \"{$title}\" created and indexed for AI search.";
        } else {
            $_SESSION['_flash_status'] =
                "Document \"{$title}\" was created, but AI indexing could not be completed. "
                . "The document is safely stored in MySQL and can be reindexed later.";
        }
        header("Location: /admin/documents/{$documentId}");
        exit;
    }

    public function edit(int $id): void
    {
        $document = $this->findDocumentOrFail($id);
        if ($document === null) {
            return;
        }

        $pdo = Database::connection();
        $knowledgeBases = $pdo->query('SELECT id, name FROM knowledge_bases WHERE is_active = 1 ORDER BY name')->fetchAll();
        $categoriesStmt = $pdo->prepare('SELECT id, name FROM categories WHERE knowledge_base_id = :kb_id ORDER BY name');
        $categoriesStmt->execute([':kb_id' => $document['knowledge_base_id']]);
        $categories = $categoriesStmt->fetchAll();

        View::render('documents/edit', [
            'title' => 'Edit Document Metadata',
            'document' => $document,
            'knowledgeBases' => $knowledgeBases,
            'categories' => $categories,
            'error' => $_SESSION['_flash_error'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error']);
    }

    /** Metadata-only update (title, category). Does NOT touch document text — see storeVersion(). */
    public function update(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header("Location: /admin/documents/{$id}/edit");
            exit;
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $categoryId = !empty($_POST['category_id']) ? (int) $_POST['category_id'] : null;

        if ($title === '') {
            $_SESSION['_flash_error'] = 'Please provide a title.';
            header("Location: /admin/documents/{$id}/edit");
            exit;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE documents SET title = :title, category_id = :category_id, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':title' => $title, ':category_id' => $categoryId, ':id' => $id]);

        $document = $this->findDocumentOrFail($id);

        $ragIndexed = false;
        if ($document !== null && !empty($document['current_version_id'])) {
            $ragIndexed = $this->reindexDocumentVersion(
                $document,
                (int) $document['current_version_id'],
                (string) $document['current_content'],
                (string) $document['current_content_hash'],
                $document['current_canonical_url'] !== null
                    ? (string) $document['current_canonical_url']
                    : null
            );
        }

        Logger::audit('document.metadata_updated', AuthService::userId(), 'document', (string) $id, ['title' => $title]);

        if ($ragIndexed) {
            $_SESSION['_flash_status'] =
                'Document metadata updated and AI search refreshed.';
        } else {
            $_SESSION['_flash_status'] =
                'Document metadata was updated in MySQL, but AI search could not be refreshed. '
                . 'The document can be reindexed later.';
        }

        header("Location: /admin/documents/{$id}");
        exit;
    }

    /**
     * Creates a new document_versions row inside one transaction, per the
     * approved safeguard: insert new version -> mark previous superseded ->
     * update documents.current_version_id -> commit. Requires documents.upload.
     */
    public function storeVersion(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header("Location: /admin/documents/{$id}");
            exit;
        }

        $document = $this->findDocumentOrFail($id);
        if ($document === null) {
            return;
        }

        $canonicalUrl = trim((string) ($_POST['canonical_url'] ?? '')) ?: null;
        $normalizedContent = (string) ($_POST['normalized_content'] ?? '');

        if (trim($normalizedContent) === '') {
            $_SESSION['_flash_error'] = 'Please provide content for the new version.';
            header("Location: /admin/documents/{$id}");
            exit;
        }

        $contentHash = hash('sha256', $normalizedContent);
        if ($contentHash === ($document['current_content_hash'] ?? null)) {
            // UNIQUE(document_id, content_hash) would reject this anyway —
            // short-circuit with a clear message instead of a raw DB error.
            $_SESSION['_flash_status'] = 'No changes detected — content is identical to the current version.';
            header("Location: /admin/documents/{$id}");
            exit;
        }

        $pdo = Database::connection();
        $userId = AuthService::userId();
        $previousVersionId = (int) ($document['current_version_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $nextVersion = $this->nextVersionNumber($pdo, $id);

            $insertStmt = $pdo->prepare(
                'INSERT INTO document_versions
                    (document_id, canonical_url, normalized_content, content_hash, version, status, created_by, created_at)
                 VALUES (:document_id, :canonical_url, :content, :hash, :version, \'active\', :created_by, NOW())'
            );
            $insertStmt->execute([
                ':document_id' => $id,
                ':canonical_url' => $canonicalUrl,
                ':content' => $normalizedContent,
                ':hash' => $contentHash,
                ':version' => $nextVersion,
                ':created_by' => $userId,
            ]);
            $newVersionId = (int) $pdo->lastInsertId();

            $supersedeStmt = $pdo->prepare(
                "UPDATE document_versions SET status = 'superseded'
                 WHERE document_id = :document_id AND status = 'active' AND id != :new_id"
            );
            $supersedeStmt->execute([':document_id' => $id, ':new_id' => $newVersionId]);

            $updateDocStmt = $pdo->prepare(
                'UPDATE documents SET current_version_id = :version_id, updated_at = NOW() WHERE id = :id'
            );
            $updateDocStmt->execute([':version_id' => $newVersionId, ':id' => $id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Logger::error('document.version_create_failed', ['document_id' => $id, 'message' => $e->getMessage()]);
            $_SESSION['_flash_error'] = 'Could not save the new version. Please try again.';
            header("Location: /admin/documents/{$id}");
            exit;
        }

        $ragIndexed = $this->reindexDocumentVersion(
            $document,
            $newVersionId,
            $normalizedContent,
            $contentHash,
            $canonicalUrl
        );

        $oldVectorsRemoved = true;

        if ($ragIndexed && $previousVersionId > 0 && $previousVersionId !== $newVersionId) {
            $oldVectorsRemoved = $this->deleteDocumentVersionFromRag(
                $id,
                $previousVersionId
            );
        }

        Logger::audit('document.version_created', $userId, 'document', (string) $id, [
            'version' => $nextVersion,
        ]);

        if (!$ragIndexed) {
            $_SESSION['_flash_status'] =
                "New version (v{$nextVersion}) was saved to MySQL, but AI indexing failed. "
                . "The previous indexed version has been kept available.";
        } elseif (!$oldVectorsRemoved) {
            $_SESSION['_flash_status'] =
                "New version (v{$nextVersion}) was indexed, but the previous version could not be removed "
                . "from AI search. Please retry cleanup.";
        } else {
            $_SESSION['_flash_status'] =
                "New version (v{$nextVersion}) saved and indexed for AI search.";
        }
        header("Location: /admin/documents/{$id}");
        exit;
    }

    public function togglePublish(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header("Location: /admin/documents/{$id}");
            exit;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT is_published FROM documents WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            http_response_code(404);
            echo '404 — Document not found';
            return;
        }

        $newValue = ((int) $current) === 1 ? 0 : 1;
        $update = $pdo->prepare('UPDATE documents SET is_published = :v, updated_at = NOW() WHERE id = :id');
        $update->execute([':v' => $newValue, ':id' => $id]);

        $document = $this->findDocumentOrFail($id);

        $ragIndexed = false;
        if ($document !== null && !empty($document['current_version_id'])) {
            $ragIndexed = $this->reindexDocumentVersion(
                $document,
                (int) $document['current_version_id'],
                (string) $document['current_content'],
                (string) $document['current_content_hash'],
                $document['current_canonical_url'] !== null
                    ? (string) $document['current_canonical_url']
                    : null
            );
        }

        Logger::audit(
            $newValue === 1 ? 'document.published' : 'document.unpublished',
            AuthService::userId(),
            'document',
            (string) $id,
            []
        );

        if ($ragIndexed) {
            $_SESSION['_flash_status'] =
                $newValue === 1
                    ? 'Document published and AI search refreshed.'
                    : 'Document unpublished and removed from AI retrieval.';
        } else {
            $_SESSION['_flash_status'] =
                ($newValue === 1 ? 'Document published' : 'Document unpublished')
                . ' in MySQL, but AI search could not be refreshed. Please retry later.';
        }

        header("Location: /admin/documents/{$id}");
        exit;
    }

    public function delete(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/documents');
            exit;
        }

        $pdo = Database::connection();

        $versionsStmt = $pdo->prepare(
            'SELECT id FROM document_versions WHERE document_id = :id ORDER BY id'
        );
        $versionsStmt->execute([':id' => $id]);
        $versionIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $versionsStmt->fetchAll()
        );

        foreach ($versionIds as $versionId) {
            if (!$this->deleteDocumentVersionFromRag($id, $versionId)) {
                $_SESSION['_flash_error'] =
                    'Document deletion was not completed because AI search cleanup failed. '
                    . 'The MySQL document has been kept unchanged. Please retry later.';
                header("Location: /admin/documents/{$id}");
                exit;
            }
        }

        // document_versions has ON DELETE CASCADE from documents, so this
        // removes the full version history along with the parent row.
        $stmt = $pdo->prepare('DELETE FROM documents WHERE id = :id');
        $stmt->execute([':id' => $id]);

        Logger::audit('document.deleted', AuthService::userId(), 'document', (string) $id, [
            'vector_versions_removed' => $versionIds,
        ]);

        $_SESSION['_flash_status'] = 'Document deleted and removed from AI search.';
        header('Location: /admin/documents');
        exit;
    }

    /**
     * Best-effort synchronization of one authoritative MySQL document
     * version to the RAG execution plane.
     *
     * MySQL remains authoritative: callers may save successfully even if
     * this synchronization temporarily fails.
     */
    private function reindexDocumentVersion(
        array $document,
        int $versionId,
        string $normalizedContent,
        string $contentHash,
        ?string $canonicalUrl
    ): bool {
        $ragClient = new RagApiClient();

        $result = $ragClient->reindexDocumentVersion([
            'knowledge_base_id' => (int) $document['knowledge_base_id'],
            'document_id' => (int) $document['id'],
            'document_version_id' => $versionId,
            'title' => (string) $document['title'],
            'canonical_url' => $canonicalUrl,
            'normalized_content' => $normalizedContent,
            'content_hash' => $contentHash,
            'is_published' => ((int) $document['is_published']) === 1,
        ]);

        if (empty($result['ok'])) {
            Logger::warning('document.rag_reindex_failed', [
                'document_id' => (int) $document['id'],
                'document_version_id' => $versionId,
                'reason' => $result['error'] ?? 'unknown',
            ]);
            return false;
        }

        Logger::info('document.rag_reindex_succeeded', [
            'document_id' => (int) $document['id'],
            'document_version_id' => $versionId,
            'chunks_indexed' => $result['chunks_indexed'] ?? null,
        ]);

        return true;
    }

    /**
     * Remove all Qdrant points belonging to one document version.
     */
    private function deleteDocumentVersionFromRag(
        int $documentId,
        int $versionId
    ): bool {
        $ragClient = new RagApiClient();
        $result = $ragClient->deleteDocumentVersion($versionId);

        if (empty($result['ok'])) {
            Logger::warning('document.rag_delete_failed', [
                'document_id' => $documentId,
                'document_version_id' => $versionId,
                'reason' => $result['error'] ?? 'unknown',
            ]);
            return false;
        }

        Logger::info('document.rag_delete_succeeded', [
            'document_id' => $documentId,
            'document_version_id' => $versionId,
        ]);

        return true;
    }

    /** @return array<string, mixed>|null */
    private function findDocumentOrFail(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT d.*, dv.normalized_content AS current_content, dv.content_hash AS current_content_hash,
                    dv.canonical_url AS current_canonical_url, dv.version AS current_version
             FROM documents d
             LEFT JOIN document_versions dv ON dv.id = d.current_version_id
             WHERE d.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $document = $stmt->fetch();

        if (!$document) {
            http_response_code(404);
            echo '404 — Document not found';
            return null;
        }

        return $document;
    }

    private function nextVersionNumber(PDO $pdo, int $documentId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM document_versions WHERE document_id = :id');
        $stmt->execute([':id' => $documentId]);
        return ((int) $stmt->fetchColumn()) + 1;
    }

    private function insertFirstVersion(PDO $pdo, int $documentId, string $content, ?string $canonicalUrl, ?int $userId): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO document_versions
                (document_id, canonical_url, normalized_content, content_hash, version, status, created_by, created_at)
             VALUES (:document_id, :canonical_url, :content, :hash, 1, \'active\', :created_by, NOW())'
        );
        $stmt->execute([
            ':document_id' => $documentId,
            ':canonical_url' => $canonicalUrl,
            ':content' => $content,
            ':hash' => hash('sha256', $content),
            ':created_by' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }
}
