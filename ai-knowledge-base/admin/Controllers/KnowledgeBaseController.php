<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;

final class KnowledgeBaseController
{
    public function index(): void
    {
        $pdo = Database::connection();
        $kbs = $pdo->query(
            'SELECT kb.*, u.name AS created_by_name,
                    (SELECT COUNT(*) FROM documents d WHERE d.knowledge_base_id = kb.id) AS document_count
             FROM knowledge_bases kb
             LEFT JOIN users u ON u.id = kb.created_by
             ORDER BY kb.created_at DESC'
        )->fetchAll();

        View::render('knowledge_bases/index', [
            'title' => 'Knowledge Bases',
            'knowledgeBases' => $kbs,
            'error' => $_SESSION['_flash_error'] ?? null,
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error'], $_SESSION['_flash_status']);
    }

    public function store(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/knowledge-bases');
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($name === '') {
            $_SESSION['_flash_error'] = 'Please provide a name.';
            header('Location: /admin/knowledge-bases');
            exit;
        }

        $slug = $this->uniqueSlug($name);
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO knowledge_bases (name, slug, description, is_active, created_by, created_at)
             VALUES (:name, :slug, :description, 1, :created_by, NOW())'
        );
        $stmt->execute([
            ':name' => $name,
            ':slug' => $slug,
            ':description' => $description !== '' ? $description : null,
            ':created_by' => AuthService::userId(),
        ]);
        $id = (int) $pdo->lastInsertId();

        Logger::audit('knowledge_base.created', AuthService::userId(), 'knowledge_base', (string) $id, ['name' => $name]);

        $_SESSION['_flash_status'] = "Knowledge base \"{$name}\" created.";
        header('Location: /admin/knowledge-bases');
        exit;
    }

    public function edit(int $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM knowledge_bases WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $kb = $stmt->fetch();

        if (!$kb) {
            http_response_code(404);
            echo '404 — Knowledge base not found';
            return;
        }

        View::render('knowledge_bases/edit', [
            'title' => 'Edit Knowledge Base',
            'kb' => $kb,
            'error' => $_SESSION['_flash_error'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error']);
    }

    public function update(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header("Location: /admin/knowledge-bases/{$id}/edit");
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $_SESSION['_flash_error'] = 'Please provide a name.';
            header("Location: /admin/knowledge-bases/{$id}/edit");
            exit;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE knowledge_bases SET name = :name, description = :description, is_active = :is_active, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':description' => $description !== '' ? $description : null,
            ':is_active' => $isActive,
            ':id' => $id,
        ]);

        Logger::audit('knowledge_base.updated', AuthService::userId(), 'knowledge_base', (string) $id, [
            'name' => $name, 'is_active' => $isActive,
        ]);

        $_SESSION['_flash_status'] = "Knowledge base \"{$name}\" updated.";
        header('Location: /admin/knowledge-bases');
        exit;
    }

    /** Generates a URL-safe slug, appending -2/-3/... on collision. */
    private function uniqueSlug(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        $base = $base !== '' ? $base : 'knowledge-base';

        $pdo = Database::connection();
        $slug = $base;
        $suffix = 2;
        while (true) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM knowledge_bases WHERE slug = :slug');
            $stmt->execute([':slug' => $slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    }
}
