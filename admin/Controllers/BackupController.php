<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Auth\AuthService;
use App\Auth\Csrf;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use PDO;
use Throwable;

final class BackupController
{
    private function backupDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/backups';
    }

    private function ensureBackupDir(): string
    {
        $dir = $this->backupDir();

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException(
                    'Unable to create backup directory.'
                );
            }
        }

        return $dir;
    }

    public function index(): void
    {
        $dir = $this->ensureBackupDir();
        $files = [];

        foreach (glob($dir . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $name = basename($path);

            if (!preg_match(
                '/^(database|application)-[A-Za-z0-9._-]+\.(sql\.gz|tar\.gz)$/',
                $name
            )) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'type' => str_starts_with(
                    $name,
                    'database-'
                ) ? 'Database' : 'Application',
                'size' => filesize($path) ?: 0,
                'created_at' => filemtime($path) ?: 0,
            ];
        }

        usort(
            $files,
            fn (array $a, array $b): int =>
                $b['created_at'] <=> $a['created_at']
        );

        View::render('backups/index', [
            'title' => 'Backups',
            'backups' => $files,
            'status' =>
                $_SESSION['_flash_status'] ?? null,
            'error' =>
                $_SESSION['_flash_error'] ?? null,
        ], 'layouts/base');

        unset(
            $_SESSION['_flash_status'],
            $_SESSION['_flash_error']
        );
    }

    public function createDatabase(): void
    {
        $this->verifyCsrf();

        try {
            $dir = $this->ensureBackupDir();

            $filename =
                'database-'
                . date('Ymd-His')
                . '.sql.gz';

            $path = $dir . '/' . $filename;

            $this->dumpDatabase($path);

            Logger::audit(
                'backup.database_created',
                AuthService::userId(),
                'backup',
                $filename,
                [
                    'filename' => $filename,
                    'size_bytes' =>
                        filesize($path) ?: 0,
                ]
            );

            $_SESSION['_flash_status'] =
                'Database backup created successfully.';
        } catch (Throwable $e) {
            Logger::error(
                'backup.database_failed',
                [
                    'message' => $e->getMessage(),
                ]
            );

            $_SESSION['_flash_error'] =
                'Unable to create database backup.';
        }

        header('Location: /admin/backups');
        exit;
    }

    public function createApplication(): void
    {
        $this->verifyCsrf();

        try {
            $dir = $this->ensureBackupDir();

            $filename =
                'application-'
                . date('Ymd-His')
                . '.tar.gz';

            $path = $dir . '/' . $filename;

            $root = dirname(__DIR__, 2);

            $safeItems = [
                'admin',
                'app',
                'config',
                'database',
                'docker',
                'public',
                'scripts',
                'bootstrap.php',
                'composer.json',
                '.env.example',
                '.dockerignore',
                '.gitignore',
            ];

            $existing = [];

            foreach ($safeItems as $item) {
                if (file_exists($root . '/' . $item)) {
                    $existing[] = $item;
                }
            }

            if (!$existing) {
                throw new \RuntimeException(
                    'No application files found to archive.'
                );
            }

            $parts = array_map(
                'escapeshellarg',
                $existing
            );

            $command =
                'cd '
                . escapeshellarg($root)
                . ' && tar '
                . '--exclude="*.pre-*" '
                . '--exclude="*.backup-*" '
                . '-czf '
                . escapeshellarg($path)
                . ' '
                . implode(' ', $parts)
                . ' 2>&1';

            exec(
                $command,
                $output,
                $exitCode
            );

            if (
                $exitCode !== 0
                || !is_file($path)
                || filesize($path) === 0
            ) {
                @unlink($path);

                throw new \RuntimeException(
                    'Application archive failed: '
                    . implode("\n", $output)
                );
            }

            Logger::audit(
                'backup.application_created',
                AuthService::userId(),
                'backup',
                $filename,
                [
                    'filename' => $filename,
                    'size_bytes' =>
                        filesize($path) ?: 0,
                ]
            );

            $_SESSION['_flash_status'] =
                'Application backup created successfully.';
        } catch (Throwable $e) {
            Logger::error(
                'backup.application_failed',
                [
                    'message' => $e->getMessage(),
                ]
            );

            $_SESSION['_flash_error'] =
                'Unable to create application backup.';
        }

        header('Location: /admin/backups');
        exit;
    }

    public function download(string $filename): void
    {
        if (
            $filename !== basename($filename)
            || !preg_match(
                '/^(database|application)-[A-Za-z0-9._-]+\.(sql\.gz|tar\.gz)$/',
                $filename
            )
        ) {
            http_response_code(400);
            echo 'Invalid backup filename.';
            return;
        }

        $path =
            $this->backupDir()
            . '/'
            . $filename;

        if (!is_file($path)) {
            http_response_code(404);
            echo 'Backup not found.';
            return;
        }

        Logger::audit(
            'backup.downloaded',
            AuthService::userId(),
            'backup',
            $filename,
            [
                'filename' => $filename,
                'size_bytes' =>
                    filesize($path) ?: 0,
            ]
        );

        header(
            'Content-Type: application/octet-stream'
        );
        header(
            'Content-Disposition: attachment; filename="'
            . $filename
            . '"'
        );
        header(
            'Content-Length: '
            . (string) filesize($path)
        );
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }

    private function verifyCsrf(): void
    {
        if (!Csrf::verify(
            $_POST['csrf_token'] ?? null
        )) {
            $_SESSION['_flash_error'] =
                'Your session expired. Please try again.';

            header('Location: /admin/backups');
            exit;
        }
    }

    private function dumpDatabase(
        string $path
    ): void {
        if (!function_exists('gzopen')) {
            throw new \RuntimeException(
                'PHP zlib support is unavailable.'
            );
        }

        $pdo = Database::connection();

        $handle = gzopen($path, 'wb9');

        if ($handle === false) {
            throw new \RuntimeException(
                'Unable to create compressed dump.'
            );
        }

        try {
            gzwrite(
                $handle,
                "-- AI Knowledge Base database backup\n"
                . '-- Created: '
                . date('c')
                . "\n\n"
                . "SET FOREIGN_KEY_CHECKS=0;\n"
                . "SET NAMES utf8mb4;\n\n"
            );

            $tables = $pdo->query(
                'SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'
            )->fetchAll(PDO::FETCH_NUM);

            foreach ($tables as $tableRow) {
                $table = (string) $tableRow[0];
                $quotedTable =
                    '`'
                    . str_replace('`', '``', $table)
                    . '`';

                $createStmt = $pdo->query(
                    'SHOW CREATE TABLE '
                    . $quotedTable
                );

                $create = $createStmt->fetch(
                    PDO::FETCH_NUM
                );

                if (!$create || !isset($create[1])) {
                    throw new \RuntimeException(
                        'Unable to read schema for '
                        . $table
                    );
                }

                gzwrite(
                    $handle,
                    "DROP TABLE IF EXISTS "
                    . $quotedTable
                    . ";\n"
                    . $create[1]
                    . ";\n\n"
                );

                $rows = $pdo->query(
                    'SELECT * FROM '
                    . $quotedTable
                );

                while (
                    $row = $rows->fetch(
                        PDO::FETCH_ASSOC
                    )
                ) {
                    $columns = [];
                    $values = [];

                    foreach ($row as $column => $value) {
                        $columns[] =
                            '`'
                            . str_replace(
                                '`',
                                '``',
                                (string) $column
                            )
                            . '`';

                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] =
                                $pdo->quote(
                                    (string) $value
                                );
                        }
                    }

                    gzwrite(
                        $handle,
                        'INSERT INTO '
                        . $quotedTable
                        . ' ('
                        . implode(', ', $columns)
                        . ') VALUES ('
                        . implode(', ', $values)
                        . ");\n"
                    );
                }

                gzwrite($handle, "\n");
            }

            gzwrite(
                $handle,
                "SET FOREIGN_KEY_CHECKS=1;\n"
            );
        } finally {
            gzclose($handle);
        }

        if (
            !is_file($path)
            || filesize($path) === 0
        ) {
            throw new \RuntimeException(
                'Database backup file is empty.'
            );
        }
    }
}
