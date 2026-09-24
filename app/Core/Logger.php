<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Simple structured JSON-lines logger.
 * Callers are responsible for not passing secrets (API keys, passwords,
 * raw conversation content) into $context — see SKILL/architecture notes.
 */
final class Logger
{
    private const SENSITIVE_KEYS = ['password', 'api_key', 'apikey', 'secret', 'token', 'authorization'];

    private static function path(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir . '/app-' . date('Y-m-d') . '.log';
    }

    private static function write(string $level, string $event, array $context = []): void
    {
        $context = self::scrub($context);

        $line = [
            'ts' => date('c'),
            'level' => $level,
            'event' => $event,
            'context' => $context,
        ];

        $encoded = json_encode($line, JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // Fail silently on write errors — logging must never break the request.
        try {
            file_put_contents(self::path(), $encoded, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // intentionally swallowed
        }
    }

    private static function scrub(array $context): array
    {
        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);
            foreach (self::SENSITIVE_KEYS as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    $context[$key] = '[REDACTED]';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $context[$key] = self::scrub($value);
            }
        }
        return $context;
    }

    public static function info(string $event, array $context = []): void
    {
        self::write('INFO', $event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        self::write('WARNING', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('ERROR', $event, $context);
    }

    public static function security(string $event, array $context = []): void
    {
        self::write('SECURITY', $event, $context);
    }

    /**
     * Writes a durable audit trail row to the `audit_logs` table, in
     * addition to the existing JSON-lines log.
     *
     * PHASE A FIX: prior to this method, `audit_logs` (created in migration
     * 0002) had zero writers — Logger::security() only ever wrote to the
     * JSON log file. This method is the first thing that actually populates
     * the table, so the "Audit Logs" admin screen (still disabled/"soon" in
     * Phase A) will have real data once it's built in a later phase.
     *
     * Defensive by design: an audit logging failure (e.g. a transient DB
     * hiccup) must NEVER cause an otherwise-successful administrative CRUD
     * operation to fail. The `audit_logs` insert is wrapped in a try/catch
     * that falls back to the JSON logger (which itself already swallows its
     * own write errors) — callers can treat this method as infallible.
     *
     * Never pass secrets, passwords, bearer tokens, session identifiers, or
     * complete document contents in $meta — this is enforced by the same
     * SENSITIVE_KEYS scrub used for the JSON log, applied here too, but
     * callers are still responsible for not passing large document bodies
     * (the scrub only redacts by key name, it does not truncate long values).
     *
     * @param string      $action     e.g. 'knowledge_base.created', 'document.version_created'
     * @param int|null    $userId     acting user id, or null for system/unauthenticated actions
     * @param string|null $entityType e.g. 'knowledge_base', 'document', 'website_source'
     * @param string|null $entityId   the affected row's id, as a string
     * @param array       $meta       small, non-sensitive metadata (e.g. ['name' => $name])
     */
    public static function audit(
        string $action,
        ?int $userId,
        ?string $entityType = null,
        ?string $entityId = null,
        array $meta = []
    ): void {
        $meta = self::scrub($meta);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, meta_json, ip, created_at)
                 VALUES (:user_id, :action, :entity_type, :entity_id, :meta_json, :ip, NOW())'
            );
            $stmt->execute([
                ':user_id' => $userId,
                ':action' => $action,
                ':entity_type' => $entityType,
                ':entity_id' => $entityId,
                ':meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            // Never let audit logging break the calling request. Fall back
            // to the JSON log so the event isn't lost entirely, and record
            // that the durable audit insert itself failed (without ever
            // throwing further — this catch is the end of the line).
            self::write('ERROR', 'audit_logs.insert_failed', [
                'original_action' => $action,
                'message' => $e->getMessage(),
            ]);
        }

        // Always also write the JSON-lines log, independent of whether the
        // DB insert above succeeded — this preserves the existing behavior
        // every other caller of Logger::* already relies on.
        self::write('AUDIT', $action, [
            'user_id' => $userId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip' => $ip,
            'meta' => $meta,
        ]);
    }
}
