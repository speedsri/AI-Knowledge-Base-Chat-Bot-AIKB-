<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Categories</h1>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($status): ?><div class="alert alert-success"><?= e($status) ?></div><?php endif; ?>

<form method="GET" action="/admin/categories" class="mb-3 row g-2">
    <div class="col-auto">
        <select name="kb" class="form-select" onchange="this.form.submit()">
            <option value="">All knowledge bases</option>
            <?php foreach ($knowledgeBases as $kb): ?>
                <option value="<?= (int) $kb['id'] ?>" <?= $kbFilter === (int) $kb['id'] ? 'selected' : '' ?>><?= e($kb['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="card mb-4">
    <div class="card-header">Add a Category</div>
    <div class="card-body">
        <form method="POST" action="/admin/categories" class="row g-3">
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
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" required maxlength="150">
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
            <thead><tr><th>Name</th><th>Knowledge Base</th><th>Documents</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><?= e($cat['name']) ?></td>
                        <td class="text-muted"><?= e($cat['kb_name']) ?></td>
                        <td><?= (int) $cat['document_count'] ?></td>
                        <td class="text-end">
                            <a href="/admin/categories/<?= (int) $cat['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <form method="POST" action="/admin/categories/<?= (int) $cat['id'] ?>/delete" class="d-inline"
                                  onsubmit="return confirm('Delete this category?');">
                                <?= \App\Auth\Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No categories yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
