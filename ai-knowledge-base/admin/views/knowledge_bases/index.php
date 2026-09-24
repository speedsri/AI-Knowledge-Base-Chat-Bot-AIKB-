<?php use function App\Core\e; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Knowledge Bases</h1>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($status): ?><div class="alert alert-success"><?= e($status) ?></div><?php endif; ?>

<div class="card mb-4">
    <div class="card-header">Add a Knowledge Base</div>
    <div class="card-body">
        <form method="POST" action="/admin/knowledge-bases" class="row g-3">
            <?= \App\Auth\Csrf::field() ?>
            <div class="col-md-4">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" required maxlength="150">
            </div>
            <div class="col-md-6">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" maxlength="1000">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Create</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Documents</th>
                    <th>Status</th>
                    <th>Created by</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($knowledgeBases as $kb): ?>
                    <tr>
                        <td><?= e($kb['name']) ?></td>
                        <td class="text-muted"><?= e($kb['description'] ?? '') ?></td>
                        <td><?= (int) $kb['document_count'] ?></td>
                        <td>
                            <?php if ((int) $kb['is_active'] === 1): ?>
                                <span class="badge text-bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= e($kb['created_by_name'] ?? '—') ?></td>
                        <td class="text-end">
                            <a href="/admin/knowledge-bases/<?= (int) $kb['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($knowledgeBases)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No knowledge bases yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
