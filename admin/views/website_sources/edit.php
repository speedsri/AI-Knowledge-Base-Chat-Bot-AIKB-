<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Edit Website Source</h1>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php $excludedText = implode("\n", json_decode($source['excluded_path_patterns'] ?? '[]', true) ?: []); ?>
<?php $locked = $importedCount > 0; ?>

<?php if ($locked): ?>
    <div class="alert alert-warning">
        <?= (int) $importedCount ?> document(s) have been imported from this source.
        Knowledge Base and Origin URL cannot be changed after documents have been
        imported — create a new Website Source instead. All other settings below
        can still be changed freely.
    </div>
<?php endif; ?>

<div class="card" style="max-width: 800px;">
    <div class="card-body">
        <form method="POST" action="/admin/website-sources/<?= (int) $source['id'] ?>" class="row g-3">
            <?= \App\Auth\Csrf::field() ?>
            <div class="col-md-6">
                <label class="form-label">Knowledge Base</label>
                <?php if ($locked): ?>
                    <select class="form-select" disabled>
                        <?php foreach ($knowledgeBases as $kb): ?>
                            <option <?= (int) $kb['id'] === (int) $source['knowledge_base_id'] ? 'selected' : '' ?>>
                                <?= e($kb['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="knowledge_base_id" value="<?= (int) $source['knowledge_base_id'] ?>">
                <?php else: ?>
                    <select name="knowledge_base_id" class="form-select" required>
                        <?php foreach ($knowledgeBases as $kb): ?>
                            <option value="<?= (int) $kb['id'] ?>" <?= (int) $kb['id'] === (int) $source['knowledge_base_id'] ? 'selected' : '' ?>>
                                <?= e($kb['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active" <?= $source['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="paused" <?= $source['status'] === 'paused' ? 'selected' : '' ?>>Paused</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">URL</label>
                <input type="url" name="origin_url" class="form-control" required maxlength="2000"
                       value="<?= e($source['origin_url']) ?>" <?= $locked ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-4">
                <label class="form-label">Label</label>
                <input type="text" name="label" class="form-control" maxlength="200" value="<?= e($source['label'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Max pages</label>
                <input type="number" name="max_pages" class="form-control" value="<?= (int) $source['max_pages'] ?>" min="1" max="2000">
            </div>
            <div class="col-md-3">
                <label class="form-label">Crawl delay (s)</label>
                <input type="number" step="0.1" name="crawl_delay_seconds" class="form-control" value="<?= e((string) $source['crawl_delay_seconds']) ?>" min="0.5" max="30">
            </div>
            <div class="col-md-3">
                <label class="form-label">Timeout (s)</label>
                <input type="number" name="request_timeout_seconds" class="form-control" value="<?= (int) $source['request_timeout_seconds'] ?>" min="5" max="120">
            </div>
            <div class="col-md-3">
                <label class="form-label">Max size (KB)</label>
                <input type="number" name="max_document_size_kb" class="form-control" value="<?= (int) $source['max_document_size_kb'] ?>" min="64" max="20480">
            </div>
            <div class="col-md-4">
                <label class="form-label">Crawl frequency</label>
                <select name="crawl_frequency" class="form-select">
                    <?php foreach (['manual' => 'Manual only', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $source['crawl_frequency'] === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Excluded path patterns (one per line)</label>
                <textarea name="excluded_path_patterns" class="form-control" rows="3"><?= e($excludedText) ?></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="/admin/website-sources" class="btn btn-link">Cancel</a>
            </div>
        </form>
    </div>
</div>
