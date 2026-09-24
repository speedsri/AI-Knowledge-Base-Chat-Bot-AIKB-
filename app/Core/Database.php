<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * PDO connection singleton. Always uses prepared statements upstream
 * (see repositories) — this class never builds raw SQL from user input.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = Config::get('db.host');
        $port = Config::get('db.port');
        $name = Config::get('db.database');
        $charset = Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$connection = new PDO(
                $dsn,
                (string) Config::get('db.username'),
                (string) Config::get('db.password'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}, time_zone = '+00:00'",
                ]
            );
        } catch (PDOException $e) {
            // Never leak DSN/credentials to the client. Log internally, throw a generic exception.
            Logger::error('database.connection_failed', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Unable to connect to the database. Please try again later.');
        }

        return self::$connection;
    }

    /** For tests: allow forcing a fresh connection (e.g. after truncating tables). */
    public static function reset(): void
    {
        self::$connection = null;
    }
}
