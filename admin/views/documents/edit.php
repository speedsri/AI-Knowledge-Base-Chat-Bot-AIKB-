<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Edit Document Metadata</h1>
<p class="text-muted">To change the document's text content, use "Save a New Version" on the document page instead.</p>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width: 640px;">
    <div class="card-body">
        <form method="POST" action="/admin/documents/<?= (int) $document['id'] ?>">
            <?= \App\Auth\Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label">Knowledge Base</label>
                <input type="text" class="form-control" disabled
                       value="<?php foreach ($knowledgeBases as $kb) { if ((int)$kb['id'] === (int)$document['knowledge_base_id']) { echo e($kb['name']); break; } } ?>">
                <div class="form-text">Documents cannot be moved to a different knowledge base in this phase.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control" required maxlength="300" value="<?= e($document['title']) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Category</label>
                <select name="category_id" class="form-select">
                    <option value="">None</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= (int) ($document['category_id'] ?? 0) === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="/admin/documents/<?= (int) $document['id'] ?>" class="btn btn-link">Cancel</a>
        </form>
    </div>
</div>
