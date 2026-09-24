<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Auth\Middleware\AuthMiddleware;
use App\Auth\Middleware\RoleMiddleware;
use App\Auth\Session;
use App\Core\Router;
use Admin\Controllers\AuthController;
use Admin\Controllers\DashboardController;
use Admin\Controllers\UserController;
use Admin\Controllers\RoleController;
use Admin\Controllers\KnowledgeBaseController;
use Admin\Controllers\CategoryController;
use Admin\Controllers\DocumentController;
use Admin\Controllers\WebsiteSourceController;
use Admin\Controllers\AiProviderController;
use Admin\Controllers\RagSettingsController;
use Admin\Controllers\VoiceSettingsController;
use Admin\Controllers\SystemHealthController;
use Admin\Controllers\RagDiagnosticsController;
use Admin\Controllers\ConversationController;
use Admin\Controllers\FeedbackController;
use Admin\Controllers\AnalyticsController;
use Admin\Controllers\AuditLogController;
use Admin\Controllers\SystemSettingsController;
use Admin\Controllers\BackupController;
use Admin\Controllers\ChatGatewayController;
use Admin\Controllers\PublicChatController;
use Admin\Controllers\WidgetController;

Session::start();

$router = new Router();

// --- Guest routes -------------------------------------------------------------
$auth = new AuthController();

$router->get('/login', fn () => $auth->showLogin());
$router->post('/login', fn () => $auth->login());
$router->post('/logout', fn () => $auth->logout());
$router->get('/forgot-password', fn () => $auth->showForgotPassword());
$router->post('/forgot-password', fn () => $auth->sendResetLink());

// --- Public browser chat -------------------------------------------------------
$router->get('/chat', function () {
    (new PublicChatController())->page();
});

$router->post('/chat/message', function () {
    (new PublicChatController())->message();
});

// --- Embeddable public chat widget --------------------------------------------
$router->post('/widget/message', function () {
    (new WidgetController())->message();
});

// --- Phase D server-to-server Chat Gateway ------------------------------------
$router->post('/api/chat', function () {
    (new ChatGatewayController())->chat();
});

// --- Authenticated routes -------------------------------------------------------
$router->get('/', function () {
    header('Location: /admin');
    exit;
});

$router->get('/admin', function () {
    AuthMiddleware::handle();
    (new DashboardController())->index();
});

$router->get('/admin/users', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('users.manage');
    (new UserController())->index();
});

$router->post('/admin/users', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('users.manage');
    (new UserController())->store();
});


// --- Roles (permission: users.manage) -----------------------------------------
$router->get('/admin/roles', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('users.manage');
    (new RoleController())->index();
});

$router->post('/admin/roles/{id}', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('users.manage');
    (new RoleController())->update(
        (int) $p['id']
    );
});

// --- Knowledge Bases (permission: kb.manage) -------------------------------
$router->get('/admin/knowledge-bases', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new KnowledgeBaseController())->index();
});
$router->post('/admin/knowledge-bases', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new KnowledgeBaseController())->store();
});
$router->get('/admin/knowledge-bases/{id}/edit', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new KnowledgeBaseController())->edit((int) $p['id']);
});
$router->post('/admin/knowledge-bases/{id}', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new KnowledgeBaseController())->update((int) $p['id']);
});

// --- Categories (permission: kb.manage) -------------------------------------
$router->get('/admin/categories', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new CategoryController())->index();
});
$router->post('/admin/categories', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new CategoryController())->store();
});
$router->get('/admin/categories/{id}/edit', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new CategoryController())->edit((int) $p['id']);
});
$router->post('/admin/categories/{id}', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new CategoryController())->update((int) $p['id']);
});
$router->post('/admin/categories/{id}/delete', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new CategoryController())->delete((int) $p['id']);
});

// --- Documents ----------------------------------------------------------------
// View/edit-metadata/publish/delete: kb.manage. Create/new version: documents.upload.
$router->get('/admin/documents', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new DocumentController())->index();
});
$router->get('/admin/documents/create', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('documents.upload');
    (new DocumentController())->create();
});
$router->post('/admin/documents', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('documents.upload');
    (new DocumentController())->store();
});
$router->get('/admin/documents/{id}', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new DocumentController())->show((int) $p['id']);
});
$router->get('/admin/documents/{id}/edit', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new DocumentController())->edit((int) $p['id']);
});
$router->post('/admin/documents/{id}', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new DocumentController())->update((int) $p['id']);
});
$router->post('/admin/documents/{id}/versions', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('documents.upload');
    (new DocumentController())->storeVersion((int) $p['id']);
});
$router->post('/admin/documents/{id}/publish', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new DocumentController())->togglePublish((int) $p['id']);
});
$router->post('/admin/documents/{id}/delete', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('kb.manage');
    (new DocumentController())->delete((int) $p['id']);
});

// --- Website Sources (permission: sources.manage) ----------------------------
$router->get('/admin/website-sources', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('sources.manage');
    (new WebsiteSourceController())->index();
});
$router->post('/admin/website-sources', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('sources.manage');
    (new WebsiteSourceController())->store();
});
$router->get('/admin/website-sources/{id}/edit', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('sources.manage');
    (new WebsiteSourceController())->edit((int) $p['id']);
});
$router->post('/admin/website-sources/{id}', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('sources.manage');
    (new WebsiteSourceController())->update((int) $p['id']);
});
$router->post('/admin/website-sources/{id}/delete', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('sources.manage');
    (new WebsiteSourceController())->delete((int) $p['id']);
});


// --- PHASE C: crawl actions (permission: sources.manage) --------------------
$router->post('/admin/website-sources/{id}/crawl', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('sources.manage');
    (new WebsiteSourceController())->crawl((int) $p['id']);
});

$router->post('/admin/website-sources/{id}/crawl-check', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('sources.manage');
    (new WebsiteSourceController())->crawlCheck((int) $p['id']);
});

// --- AI Providers (permission: ai.configure) — encrypted credential management
$router->get('/admin/ai-providers', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new AiProviderController())->index();
});
$router->post('/admin/ai-providers', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new AiProviderController())->store();
});
$router->get('/admin/ai-providers/{id}/edit', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new AiProviderController())->edit((int) $p['id']);
});
$router->post('/admin/ai-providers/{id}', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new AiProviderController())->update((int) $p['id']);
});

$router->post('/admin/ai-providers/{id}/test', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new AiProviderController())->test((int) $p['id']);
});

// --- RAG Settings (permission: ai.configure) — singleton ----------------------
$router->get('/admin/rag-settings', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new RagSettingsController())->edit();
});
$router->post('/admin/rag-settings', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new RagSettingsController())->update();
});

// --- RAG diagnostics (Phase B.1, permission: ai.configure) --------------------
// Admin-only "Test Connection" action on the RAG Settings page. Same
// permission as the page itself -- no new permission key.
$router->post('/admin/rag-settings/test', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new RagDiagnosticsController())->test();
});

// --- Voice Settings (permission: ai.configure) -------------------------------
$router->get('/admin/voice-settings', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new VoiceSettingsController())->edit();
});

$router->post('/admin/voice-settings', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('ai.configure');
    (new VoiceSettingsController())->update();
});

// --- Conversations (permission: conversations.view) ---------------------------
$router->get('/admin/conversations', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('conversations.view');
    (new ConversationController())->index();
});

$router->get('/admin/conversations/{id}', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('conversations.view');
    (new ConversationController())->show((int) $p['id']);
});

// --- Audit Logs (permission: settings.manage) ---------------------------------
$router->get('/admin/audit-logs', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission(
        'settings.manage'
    );
    (new AuditLogController())->index();
});

// --- Analytics (permission: conversations.view) -------------------------------
$router->get('/admin/analytics', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('conversations.view');
    (new AnalyticsController())->index();
});

// --- Feedback (permission: conversations.view) --------------------------------
$router->get('/admin/feedback', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('conversations.view');
    (new FeedbackController())->index();
});

// --- Backups (permission: settings.manage) ------------------------------------
$router->get('/admin/backups', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission(
        'settings.manage'
    );
    (new BackupController())->index();
});

$router->post('/admin/backups/database', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission(
        'settings.manage'
    );
    (new BackupController())->createDatabase();
});

$router->post('/admin/backups/application', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission(
        'settings.manage'
    );
    (new BackupController())->createApplication();
});

$router->get('/admin/backups/download/{filename}', function ($p) {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission(
        'settings.manage'
    );
    (new BackupController())->download(
        (string) $p['filename']
    );
});

// --- System Settings (permission: settings.manage) ----------------------------
$router->get('/admin/system-settings', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission(
        'settings.manage'
    );
    (new SystemSettingsController())->index();
});

$router->post('/admin/system-settings', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission(
        'settings.manage'
    );
    (new SystemSettingsController())->update();
});

// --- System Health (permission: settings.manage) -------------------------------
// Phase B.1: RAG API / Qdrant / Gemini rows are now live checks against
// the running RAG backend (192.168.1.220:8500) via RagApiClient.
$router->get('/admin/system-health', function () {
    AuthMiddleware::handle();
    RoleMiddleware::requirePermission('settings.manage');
    (new SystemHealthController())->index();
});

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
