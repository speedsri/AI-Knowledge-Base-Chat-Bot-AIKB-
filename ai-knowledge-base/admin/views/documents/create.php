<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">New Document</h1>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="max-width: 800px;">
    <div class="card-body">
        <form method="POST" action="/admin/documents">
            <?= \App\Auth\Csrf::field() ?>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Knowledge Base</label>
                    <select name="knowledge_base_id" class="form-select" required id="kb-select">
                        <option value="">Choose...</option>
                        <?php foreach ($knowledgeBases as $kb): ?>
                            <option value="<?= (int) $kb['id'] ?>"><?= e($kb['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Category (optional)</label>
                    <select name="category_id" class="form-select" id="category-select">
                        <option value="">None</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" data-kb="<?= (int) $cat['knowledge_base_id'] ?>">
                                <?= e($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control" required maxlength="300">
            </div>
            <div class="mb-3">
                <label class="form-label">Canonical URL (optional)</label>
                <input type="url" name="canonical_url" class="form-control" maxlength="2000">
            </div>
            <div class="mb-3">
                <label class="form-label">Content</label>
                <textarea name="normalized_content" class="form-control" rows="12" required
                          placeholder="Plain text content for this document. This becomes version 1."></textarea>
                <div class="form-text">This is stored as the authoritative document text (document_versions, version 1).</div>
            </div>
            <button type="submit" class="btn btn-primary">Create Document</button>
            <a href="/admin/documents" class="btn btn-link">Cancel</a>
        </form>
    </div>
</div>

<script>
document.getElementById('kb-select').addEventListener('change', function () {
    var kbId = this.value;
    var options = document.querySelectorAll('#category-select option[data-kb]');
    options.forEach(function (opt) {
        opt.hidden = kbId !== '' && opt.getAttribute('data-kb') !== kbId;
    });
    document.getElementById('category-select').value = '';
});
</script>
