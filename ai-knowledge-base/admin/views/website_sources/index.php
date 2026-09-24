<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Website Sources</h1>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($status): ?><div class="alert alert-success"><?= e($status) ?></div><?php endif; ?>

<div class="alert alert-info">
    "Crawl Now" fetches and normalizes pages via the RAG backend, then AIKB
    imports the results into MySQL (the authoritative store) and indexes
    them for retrieval. Crawling is manual only in this phase — nothing
    runs automatically on a schedule yet, regardless of the configured
    Crawl Frequency below (that field is reserved for a later phase).
</div>

<div class="card mb-4">
    <div class="card-header">Add a Website Source</div>
    <div class="card-body">
        <form method="POST" action="/admin/website-sources" class="row g-3">
            <?= \App\Auth\Csrf::field() ?>
            <div class="col-md-4">
                <label class="form-label">Knowledge Base</label>
                <select name="knowledge_base_id" class="form-select" required>
                    <option value="">Choose...</option>
                    <?php foreach ($knowledgeBases as $kb): ?>
                        <option value="<?= (int) $kb['id'] ?>"><?= e($kb['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">URL</label>
                <input type="url" name="origin_url" class="form-control" required maxlength="2000" placeholder="https://example.com">
            </div>
            <div class="col-md-4">
                <label class="form-label">Label (optional)</label>
                <input type="text" name="label" class="form-control" maxlength="200">
            </div>
            <div class="col-md-2">
                <label class="form-label">Max pages</label>
                <input type="number" name="max_pages" class="form-control" value="200" min="1" max="2000">
            </div>
            <div class="col-md-2">
                <label class="form-label">Crawl delay (s)</label>
                <input type="number" step="0.1" name="crawl_delay_seconds" class="form-control" value="1.5" min="0.5" max="30">
            </div>
            <div class="col-md-2">
                <label class="form-label">Timeout (s)</label>
                <input type="number" name="request_timeout_seconds" class="form-control" value="20" min="5" max="120">
            </div>
            <div class="col-md-2">
                <label class="form-label">Max size (KB)</label>
                <input type="number" name="max_document_size_kb" class="form-control" value="2048" min="64" max="20480">
            </div>
            <div class="col-md-4">
                <label class="form-label">Crawl frequency</label>
                <select name="crawl_frequency" class="form-select">
                    <option value="manual">Manual only</option>
                    <option value="daily">Daily</option>
                    <option value="weekly" selected>Weekly</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Excluded path patterns (one per line, optional)</label>
                <textarea name="excluded_path_patterns" class="form-control" rows="2" placeholder="/login&#10;/logout&#10;.pdf"></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Add Source</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Label / URL</th><th>Knowledge Base</th><th>Max pages</th><th>Status</th><th>Last crawled</th><th>Latest crawl</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($sources as $src): ?>
                    <?php $latestRun = $latestRuns[(int) $src['id']] ?? null; ?>
                    <tr>
                        <td>
                            <div><?= e($src['label'] ?: $src['origin_url']) ?></div>
                            <div class="text-muted small"><?= e($src['origin_url']) ?></div>
                        </td>
                        <td class="text-muted"><?= e($src['kb_name']) ?></td>
                        <td><?= (int) $src['max_pages'] ?></td>
                        <td>
                            <?php $statusColors = ['active' => 'success', 'paused' => 'secondary', 'error' => 'danger']; ?>
                            <span class="badge text-bg-<?= $statusColors[$src['status']] ?? 'secondary' ?>"><?= e($src['status']) ?></span>
                            <?php if ($src['status'] === 'error' && !empty($src['last_error'])): ?>
                                <div class="text-danger small mt-1"><?= e($src['last_error']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= e($src['last_crawled_at'] ?? 'Never') ?></td>
                        <td class="text-muted">
                            <?php if (!$latestRun): ?>
                                <span class="text-muted">No crawls yet</span>
                            <?php elseif ($latestRun['status'] === 'running'): ?>
                                <span class="badge text-bg-info">RUNNING</span>
                                <div class="small">started <?= e($latestRun['started_at']) ?></div>
                            <?php elseif ($latestRun['status'] === 'failed'): ?>
                                <span class="badge text-bg-danger">FAILED</span>
                                <div class="small text-danger"><?= e($latestRun['error_message'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="badge text-bg-success">COMPLETED</span>
                                <div class="small">
                                    <?= (int) $latestRun['documents_new'] ?> new,
                                    <?= (int) $latestRun['documents_changed'] ?> changed,
                                    <?= (int) $latestRun['documents_unchanged'] ?> unchanged,
                                    <?= (int) $latestRun['documents_failed'] ?> failed,
                                    <?= (int) $latestRun['documents_stale'] ?> stale
                                    <?php if ((int) $latestRun['documents_indexing_failed'] > 0): ?>
                                        <span class="text-danger">, <?= (int) $latestRun['documents_indexing_failed'] ?> indexing failed</span>
                                    <?php endif; ?>
                                    <?php if ((int) $latestRun['documents_stale_failed'] > 0): ?>
                                        <span class="text-danger">, <?= (int) $latestRun['documents_stale_failed'] ?> stale-unpublish failed</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ((int) $latestRun['cleanup_needed'] === 1): ?>
                                    <div class="small text-warning">⚠ old version cleanup pending</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($latestRun && $latestRun['status'] === 'running'): ?>
                                <form method="POST" action="/admin/website-sources/<?= (int) $src['id'] ?>/crawl-check" class="d-inline">
                                    <?= \App\Auth\Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Check Status</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="/admin/website-sources/<?= (int) $src['id'] ?>/crawl" class="d-inline"
                                      onsubmit="return confirm('Start a crawl of <?= e($src['origin_url']) ?>?');">
                                    <?= \App\Auth\Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-primary" <?= $src['status'] === 'paused' ? 'disabled title="Source is paused"' : '' ?>>Crawl Now</button>
                                </form>
                            <?php endif; ?>
                            <a href="/admin/website-sources/<?= (int) $src['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="POST" action="/admin/website-sources/<?= (int) $src['id'] ?>/delete" class="d-inline"
                                  onsubmit="return confirm('Delete this website source?');">
                                <?= \App\Auth\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($sources)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No website sources yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
