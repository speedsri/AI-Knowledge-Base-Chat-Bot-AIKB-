<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Migrator
{
    private string $migrationsPath;

    public function __construct(?string $migrationsPath = null)
    {
        $this->migrationsPath = $migrationsPath ?? dirname(__DIR__, 2) . '/database/migrations';
    }

    public function run(): void
    {
        $pdo = Database::connection();
        $this->ensureMigrationsTable($pdo);

        $applied = $this->appliedMigrations($pdo);
        $files = $this->migrationFiles();

        $ranAny = false;

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Could not read migration file: {$file}");
            }

            echo "Applying migration: {$name}\n";

            try {
                // NOTE: DDL statements (CREATE TABLE, etc.) cause an implicit
                // commit in MySQL/MariaDB, so migrations are NOT wrapped in a
                // transaction — each statement is effectively its own unit.
                // Migrations should be written to be safe to re-run manually
                // (CREATE TABLE IF NOT EXISTS, etc.) if a later statement in
                // the same file fails partway through.
                foreach ($this->splitStatements($sql) as $statement) {
                    if (trim($statement) === '') {
                        continue;
                    }
                    $pdo->exec($statement);
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO migrations (migration, applied_at) VALUES (:name, NOW())'
                );
                $stmt->execute([':name' => $name]);

                $ranAny = true;
            } catch (\Throwable $e) {
                throw new \RuntimeException("Migration failed [{$name}]: " . $e->getMessage(), 0, $e);
            }
        }

        echo $ranAny ? "All pending migrations applied.\n" : "Nothing to migrate — already up to date.\n";
    }

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return string[] */
    private function appliedMigrations(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT migration FROM migrations');
        return array_column($stmt->fetchAll(), 'migration');
    }

    /** @return string[] absolute paths, sorted by filename (numeric prefix order) */
    private function migrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    /** Split on semicolons that end a line, ignoring those inside string literals is
     *  intentionally NOT attempted here — migrations must avoid embedding ';' inside
     *  string literals in a single statement to keep this splitter simple and auditable. */
    private function splitStatements(string $sql): array
    {
        return array_filter(array_map('trim', explode(";\n", str_replace(";\r\n", ";\n", $sql))));
    }
}
