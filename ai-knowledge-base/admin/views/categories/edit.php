<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Edit Category</h1>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width: 640px;">
    <div class="card-body">
        <form method="POST" action="/admin/categories/<?= (int) $category['id'] ?>">
            <?= \App\Auth\Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label">Knowledge Base</label>
                <input type="text" class="form-control" disabled
                       value="<?php foreach ($knowledgeBases as $kb) { if ((int)$kb['id'] === (int)$category['knowledge_base_id']) { echo e($kb['name']); break; } } ?>">
                <div class="form-text">Category cannot be moved to a different knowledge base in this phase.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Name</label>
                <input type="text" name="name" class="form-control" required maxlength="150" value="<?= e($category['name']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Parent category (optional)</label>
                <select name="parent_id" class="form-select">
                    <option value="">None</option>
                    <?php foreach ($possibleParents as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($category['parent_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="/admin/categories" class="btn btn-link">Cancel</a>
        </form>
    </div>
</div>
