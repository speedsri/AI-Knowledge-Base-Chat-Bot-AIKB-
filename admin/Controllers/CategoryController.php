<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;

final class CategoryController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $knowledgeBases = $pdo->query('SELECT id, name FROM knowledge_bases ORDER BY name')->fetchAll();

        $kbFilter = isset($_GET['kb']) ? (int) $_GET['kb'] : null;

        $sql = 'SELECT c.*, kb.name AS kb_name,
                       (SELECT COUNT(*) FROM documents d WHERE d.category_id = c.id) AS document_count
                FROM categories c
                INNER JOIN knowledge_bases kb ON kb.id = c.knowledge_base_id';
        $params = [];
        if ($kbFilter !== null) {
            $sql .= ' WHERE c.knowledge_base_id = :kb';
            $params[':kb'] = $kbFilter;
        }
        $sql .= ' ORDER BY kb.name, c.name';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $categories = $stmt->fetchAll();

        View::render('categories/index', [
            'title' => 'Categories',
            'categories' => $categories,
            'knowledgeBases' => $knowledgeBases,
            'kbFilter' => $kbFilter,
            'error' => $_SESSION['_flash_error'] ?? null,
            'status' => $_SESSION['_flash_status'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error'], $_SESSION['_flash_status']);
    }

    public function store(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/categories');
            exit;
        }

        $knowledgeBaseId = (int) ($_POST['knowledge_base_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;

        if ($knowledgeBaseId <= 0 || $name === '') {
            $_SESSION['_flash_error'] = 'Please choose a knowledge base and provide a name.';
            header('Location: /admin/categories');
            exit;
        }

        $slug = $this->uniqueSlug($knowledgeBaseId, $name);

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO categories (knowledge_base_id, parent_id, name, slug, created_at)
             VALUES (:kb_id, :parent_id, :name, :slug, NOW())'
        );
        $stmt->execute([
            ':kb_id' => $knowledgeBaseId,
            ':parent_id' => $parentId,
            ':name' => $name,
            ':slug' => $slug,
        ]);
        $id = (int) $pdo->lastInsertId();

        Logger::audit('category.created', AuthService::userId(), 'category', (string) $id, [
            'name' => $name, 'knowledge_base_id' => $knowledgeBaseId,
        ]);

        $_SESSION['_flash_status'] = "Category \"{$name}\" created.";
        header('Location: /admin/categories');
        exit;
    }

    public function edit(int $id): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $category = $stmt->fetch();

        if (!$category) {
            http_response_code(404);
            echo '404 — Category not found';
            return;
        }

        $knowledgeBases = $pdo->query('SELECT id, name FROM knowledge_bases ORDER BY name')->fetchAll();

        $siblingsStmt = $pdo->prepare(
            'SELECT id, name FROM categories WHERE knowledge_base_id = :kb_id AND id != :id ORDER BY name'
        );
        $siblingsStmt->execute([':kb_id' => $category['knowledge_base_id'], ':id' => $id]);
        $possibleParents = $siblingsStmt->fetchAll();

        View::render('categories/edit', [
            'title' => 'Edit Category',
            'category' => $category,
            'knowledgeBases' => $knowledgeBases,
            'possibleParents' => $possibleParents,
            'error' => $_SESSION['_flash_error'] ?? null,
        ], 'layouts/base');

        unset($_SESSION['_flash_error']);
    }

    public function update(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header("Location: /admin/categories/{$id}/edit");
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;

        if ($name === '') {
            $_SESSION['_flash_error'] = 'Please provide a name.';
            header("Location: /admin/categories/{$id}/edit");
            exit;
        }

        if ($parentId === $id) {
            $_SESSION['_flash_error'] = 'A category cannot be its own parent.';
            header("Location: /admin/categories/{$id}/edit");
            exit;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE categories SET name = :name, parent_id = :parent_id, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':name' => $name, ':parent_id' => $parentId, ':id' => $id]);

        Logger::audit('category.updated', AuthService::userId(), 'category', (string) $id, ['name' => $name]);

        $_SESSION['_flash_status'] = "Category \"{$name}\" updated.";
        header('Location: /admin/categories');
        exit;
    }

    public function delete(int $id): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            $_SESSION['_flash_error'] = 'Your session expired. Please try again.';
            header('Location: /admin/categories');
            exit;
        }

        $pdo = Database::connection();

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE category_id = :id');
        $countStmt->execute([':id' => $id]);
        if ((int) $countStmt->fetchColumn() > 0) {
            $_SESSION['_flash_error'] = 'This category still has documents assigned to it. Move or delete them first.';
            header('Location: /admin/categories');
            exit;
        }

        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute([':id' => $id]);

        Logger::audit('category.deleted', AuthService::userId(), 'category', (string) $id, []);

        $_SESSION['_flash_status'] = 'Category deleted.';
        header('Location: /admin/categories');
        exit;
    }

    private function uniqueSlug(int $knowledgeBaseId, string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        $base = $base !== '' ? $base : 'category';

        $pdo = Database::connection();
        $slug = $base;
        $suffix = 2;
        while (true) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM categories WHERE knowledge_base_id = :kb_id AND slug = :slug'
            );
            $stmt->execute([':kb_id' => $knowledgeBaseId, ':slug' => $slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    }
}
