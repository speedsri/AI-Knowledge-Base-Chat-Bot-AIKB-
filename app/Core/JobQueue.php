<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal DB-backed job queue. Phase 2+ handlers (document indexing,
 * embedding, website crawling) register themselves in Worker::HANDLERS
 * and are invoked by scripts/worker.php, which is triggered by cron.
 *
 * This is intentionally not a full queue system (no priorities, no
 * multi-worker locking beyond a simple UPDATE...LIMIT claim) — appropriate
 * for the single-worker cron model described in the architecture doc.
 */
final class JobQueue
{
    public static function push(string $jobType, array $payload, int $maxAttempts = 3, ?\DateTimeInterface $availableAt = null): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO background_jobs (job_type, payload_json, max_attempts, available_at, created_at)
             VALUES (:type, :payload, :max_attempts, :available_at, NOW())'
        );
        $stmt->execute([
            ':type' => $jobType,
            ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':max_attempts' => $maxAttempts,
            ':available_at' => $availableAt ? $availableAt->format('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Claim up to $limit pending jobs whose available_at has passed,
     * marking them 'running' so a concurrent cron invocation won't also pick them up.
     *
     * @return array<int, array{id:int, job_type:string, payload_json:string, attempts:int, max_attempts:int}>
     */
    public static function claimBatch(int $limit = 10): array
    {
        $pdo = Database::connection();

        $select = $pdo->prepare(
            "SELECT id FROM background_jobs
             WHERE status = 'pending' AND available_at <= NOW()
             ORDER BY available_at ASC
             LIMIT :limit"
        );
        $select->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $select->execute();
        $ids = array_column($select->fetchAll(), 'id');

        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $claim = $pdo->prepare(
            "UPDATE background_jobs SET status = 'running', updated_at = NOW()
             WHERE id IN ({$placeholders}) AND status = 'pending'"
        );
        $claim->execute($ids);

        $fetch = $pdo->prepare(
            "SELECT * FROM background_jobs WHERE id IN ({$placeholders})"
        );
        $fetch->execute($ids);
        return $fetch->fetchAll();
    }

    public static function markDone(int $jobId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("UPDATE background_jobs SET status = 'done', updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $jobId]);
    }

    public static function markFailed(int $jobId, int $attempts, int $maxAttempts, string $error): void
    {
        $pdo = Database::connection();

        if ($attempts >= $maxAttempts) {
            $stmt = $pdo->prepare(
                "UPDATE background_jobs
                 SET status = 'failed', attempts = :attempts, last_error = :error, updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([':attempts' => $attempts, ':error' => $error, ':id' => $jobId]);
            return;
        }

        // Exponential backoff before the job becomes eligible again: 1m, 5m, 15m...
        $delayMinutes = (int) (1 * (5 ** ($attempts - 1)));
        $stmt = $pdo->prepare(
            "UPDATE background_jobs
             SET status = 'pending', attempts = :attempts, last_error = :error,
                 available_at = DATE_ADD(NOW(), INTERVAL :delay MINUTE), updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ':attempts' => $attempts,
            ':error' => $error,
            ':delay' => $delayMinutes,
            ':id' => $jobId,
        ]);
    }
}
