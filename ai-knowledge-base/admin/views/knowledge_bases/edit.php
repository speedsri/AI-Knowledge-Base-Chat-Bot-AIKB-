<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Edit Knowledge Base</h1>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width: 640px;">
    <div class="card-body">
        <form method="POST" action="/admin/knowledge-bases/<?= (int) $kb['id'] ?>">
            <?= \App\Auth\Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" required maxlength="150" value="<?= e($kb['name']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3" maxlength="1000"><?= e($kb['description'] ?? '') ?></textarea>
            </div>
            <div class="form-check mb-3">
                <input type="checkbox" name="is_active" id="is_active" class="form-check-input" <?= (int) $kb['is_active'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_active">Active</label>
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="/admin/knowledge-bases" class="btn btn-link">Cancel</a>
        </form>
    </div>
</div>
