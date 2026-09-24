<?php use function App\Core\e; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><?= e($document['title']) ?></h1>
    <div>
        <a href="/admin/documents/<?= (int) $document['id'] ?>/edit" class="btn btn-outline-secondary">Edit Metadata</a>
        <form method="POST" action="/admin/documents/<?= (int) $document['id'] ?>/publish" class="d-inline">
            <?= \App\Auth\Csrf::field() ?>
            <button type="submit" class="btn btn-outline-primary">
                <?= (int) $document['is_published'] === 1 ? 'Unpublish' : 'Publish' ?>
            </button>
        </form>
        <form method="POST" action="/admin/documents/<?= (int) $document['id'] ?>/delete" class="d-inline"
              onsubmit="return confirm('Delete this document and all its versions? This cannot be undone.');">
            <?= \App\Auth\Csrf::field() ?>
            <button type="submit" class="btn btn-outline-danger">Delete</button>
        </form>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($status): ?><div class="alert alert-success"><?= e($status) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header">Current Content (v<?= $document['current_version'] !== null ? (int) $document['current_version'] : '—' ?>)</div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    Content hash: <code><?= e(substr((string) ($document['current_content_hash'] ?? ''), 0, 16)) ?>…</code>
                    <?php if (!empty($document['current_canonical_url'])): ?>
                        &middot; Source: <a href="<?= e($document['current_canonical_url']) ?>" target="_blank" rel="noopener"><?= e($document['current_canonical_url']) ?></a>
                    <?php endif; ?>
                </p>
                <pre class="border rounded p-3 bg-light" style="max-height: 300px; overflow-y: auto; white-space: pre-wrap;"><?= e($document['current_content'] ?? '') ?></pre>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Save a New Version</div>
            <div class="card-body">
                <form method="POST" action="/admin/documents/<?= (int) $document['id'] ?>/versions">
                    <?= \App\Auth\Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label">Canonical URL (optional)</label>
                        <input type="url" name="canonical_url" class="form-control" maxlength="2000"
                               value="<?= e($document['current_canonical_url'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Content</label>
                        <textarea name="normalized_content" class="form-control" rows="10" required><?= e($document['current_content'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Save New Version</button>
                    <div class="form-text">Identical content is detected and skipped automatically (no duplicate version created).</div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Version History</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th>Version</th><th>Status</th><th>Created</th></tr></thead>
                    <tbody>
                        <?php foreach ($versions as $v): ?>
                            <tr>
                                <td>v<?= (int) $v['version'] ?></td>
                                <td>
                                    <?php if ($v['status'] === 'active'): ?>
                                        <span class="badge text-bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Superseded</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?= e($v['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
