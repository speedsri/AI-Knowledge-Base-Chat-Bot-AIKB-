#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Background job worker — intended to be invoked by cron every minute
 * (see docs/cron.md for the exact crontab entry). Each invocation claims
 * a small batch of due jobs, processes them, and exits — no long-running
 * daemon, no extra infrastructure required.
 *
 * Handlers are registered here by job_type. Phase 1 ships no real handlers
 * yet (document/embedding/crawl jobs land in Phases 2–4); this file exists
 * now so the cron wiring, locking, and retry/backoff behavior are already
 * correct and testable before real jobs are pushed onto the queue.
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\JobQueue;
use App\Core\Logger;

/** @var array<string, callable(array $payload): void> $handlers */
$handlers = [
    // 'document.process' => [\App\Documents\DocumentProcessor::class, 'handle'],
    // 'website.crawl'    => [\App\Website\Importer::class, 'handle'],
    // 'embedding.generate' => [\App\AI\RAG\EmbeddingJob::class, 'handle'],
];

// A simple lock file prevents overlapping runs if a previous invocation is
// still processing a slow job when cron fires again a minute later.
$lockFile = sys_get_temp_dir() . '/aikb_worker.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Worker already running — skipping this invocation.\n");
    exit(0);
}

try {
    $jobs = JobQueue::claimBatch(10);

    if (empty($jobs)) {
        fwrite(STDOUT, "No pending jobs.\n");
        exit(0);
    }

    foreach ($jobs as $job) {
        $jobId = (int) $job['id'];
        $type = $job['job_type'];
        $attempts = (int) $job['attempts'] + 1;
        $maxAttempts = (int) $job['max_attempts'];
        $payload = json_decode($job['payload_json'], true) ?? [];

        if (!isset($handlers[$type])) {
            $error = "No handler registered for job_type [{$type}]";
            Logger::error('worker.no_handler', ['job_id' => $jobId, 'job_type' => $type]);
            JobQueue::markFailed($jobId, $attempts, $maxAttempts, $error);
            continue;
        }

        try {
            fwrite(STDOUT, "Processing job #{$jobId} ({$type})...\n");
            call_user_func($handlers[$type], $payload);
            JobQueue::markDone($jobId);
            fwrite(STDOUT, "Job #{$jobId} done.\n");
        } catch (\Throwable $e) {
            Logger::error('worker.job_failed', [
                'job_id' => $jobId,
                'job_type' => $type,
                'attempt' => $attempts,
                'message' => $e->getMessage(),
            ]);
            JobQueue::markFailed($jobId, $attempts, $maxAttempts, $e->getMessage());
            fwrite(STDOUT, "Job #{$jobId} failed (attempt {$attempts}/{$maxAttempts}): {$e->getMessage()}\n");
        }
    }
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
