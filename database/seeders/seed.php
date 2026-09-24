<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Auth\AuthService;
use App\Auth\UserRepository;
use App\Core\Database;

$pdo = Database::connection();

$permissions = [
    'kb.manage' => 'Create/edit/disable knowledge bases and categories',
    'documents.upload' => 'Upload and manage documents',
    'documents.reindex' => 'Trigger re-indexing of documents',
    'sources.manage' => 'Manage website sources and crawling',
    'ai.configure' => 'Configure AI providers and RAG settings',
    'users.manage' => 'Manage users and role assignments',
    'conversations.view' => 'View conversation history',
    'analytics.view' => 'View analytics dashboards',
    'settings.manage' => 'Manage system settings',
    'chat.use' => 'Use the chat/voice assistant',
];

$rolePermissions = [
    'Administrator' => array_keys($permissions), // everything
    'Editor' => ['kb.manage', 'documents.upload', 'documents.reindex', 'sources.manage', 'conversations.view', 'chat.use'],
    'Viewer' => ['chat.use'],
];

echo "Seeding permissions...\n";
foreach ($permissions as $key => $description) {
    $stmt = $pdo->prepare(
        'INSERT INTO permissions (key_name, description) VALUES (:key, :description)
         ON DUPLICATE KEY UPDATE description = VALUES(description)'
    );
    $stmt->execute([':key' => $key, ':description' => $description]);
}

echo "Seeding roles...\n";
foreach (array_keys($rolePermissions) as $roleName) {
    $stmt = $pdo->prepare(
        'INSERT INTO roles (name) VALUES (:name) ON DUPLICATE KEY UPDATE name = VALUES(name)'
    );
    $stmt->execute([':name' => $roleName]);
}

echo "Linking role permissions...\n";
foreach ($rolePermissions as $roleName => $permissionKeys) {
    $roleId = (int) $pdo->query(
        'SELECT id FROM roles WHERE name = ' . $pdo->quote($roleName)
    )->fetchColumn();

    foreach ($permissionKeys as $permKey) {
        $permId = (int) $pdo->query(
            'SELECT id FROM permissions WHERE key_name = ' . $pdo->quote($permKey)
        )->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:r, :p)'
        );
        $stmt->execute([':r' => $roleId, ':p' => $permId]);
    }
}

$adminEmail = getenv('SEED_ADMIN_EMAIL') ?: 'admin@example.com';
$adminPassword = getenv('SEED_ADMIN_PASSWORD') ?: null;

if (!$adminPassword) {
    $adminPassword = bin2hex(random_bytes(9));
    echo "No SEED_ADMIN_PASSWORD provided — generated one: {$adminPassword}\n";
    echo "CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN.\n";
}

$existing = UserRepository::findByEmail($adminEmail);
if ($existing) {
    echo "Administrator user already exists ({$adminEmail}), skipping creation.\n";
    $adminId = (int) $existing['id'];
} else {
    echo "Creating administrator user: {$adminEmail}\n";
    $adminId = UserRepository::create('Administrator', $adminEmail, AuthService::hashPassword($adminPassword));
}

UserRepository::assignRole($adminId, 'Administrator');

echo "Seeding complete.\n";
