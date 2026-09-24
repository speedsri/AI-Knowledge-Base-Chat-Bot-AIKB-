<?php use function App\Core\e; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Documents</h1>
    <a href="/admin/documents/create" class="btn btn-primary">New Document</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($status): ?><div class="alert alert-success"><?= e($status) ?></div><?php endif; ?>

<form method="GET" action="/admin/documents" class="mb-3 row g-2">
    <div class="col-auto">
        <select name="kb" class="form-select" onchange="this.form.submit()">
            <option value="">All knowledge bases</option>
            <?php foreach ($knowledgeBases as $kb): ?>
                <option value="<?= (int) $kb['id'] ?>" <?= $kbFilter === (int) $kb['id'] ? 'selected' : '' ?>><?= e($kb['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr><th>Title</th><th>Knowledge Base</th><th>Category</th><th>Version</th><th>Status</th><th>Updated</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td><a href="/admin/documents/<?= (int) $doc['id'] ?>"><?= e($doc['title']) ?></a></td>
                        <td class="text-muted"><?= e($doc['kb_name']) ?></td>
                        <td class="text-muted"><?= e($doc['category_name'] ?? '—') ?></td>
                        <td><?= $doc['current_version'] !== null ? 'v' . (int) $doc['current_version'] : '—' ?></td>
                        <td>
                            <?php if ((int) $doc['is_published'] === 1): ?>
                                <span class="badge text-bg-success">Published</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Unpublished</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= e($doc['updated_at']) ?></td>
                        <td class="text-end">
                            <a href="/admin/documents/<?= (int) $doc['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($documents)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No documents yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
