<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">AI Providers</h1>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($status): ?><div class="alert alert-success"><?= e($status) ?></div><?php endif; ?>

<div class="alert alert-info">
    <strong>Provider credentials are encrypted at rest.</strong>
    Open a provider with <em>Edit</em> to configure or replace its API
    credential and to validate/apply it to the running RAG service.
    The full saved credential is never displayed after saving.
</div>

<div class="card mb-4">
    <div class="card-header">Add a Provider</div>
    <div class="card-body">
        <form method="POST" action="/admin/ai-providers" class="row g-3">
            <?= \App\Auth\Csrf::field() ?>
            <div class="col-md-4">
                <label class="form-label">Name (internal key)</label>
                <input type="text" name="name" class="form-control" required maxlength="100" placeholder="gemini-primary">
            </div>
            <div class="col-md-4">
                <label class="form-label">Display name</label>
                <input type="text" name="display_name" class="form-control" maxlength="150" placeholder="Google Gemini">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
            <div class="col-12">
                <label class="form-label">Model registry (one model id per line, optional)</label>
                <textarea name="model_registry" class="form-control" rows="2" placeholder="gemini-2.5-flash&#10;gemini-2.5-pro"></textarea>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Name</th><th>Type</th><th>Models</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($providers as $p): ?>
                    <tr>
                        <td><?= e($p['display_name'] ?: $p['name']) ?></td>
                        <td class="text-muted"><?= e($p['provider_type']) ?></td>
                        <td class="text-muted"><?= e(implode(', ', json_decode($p['model_registry'] ?? '[]', true) ?: [])) ?></td>
                        <td>
                            <?php if ((int) $p['is_enabled'] === 1): ?>
                                <span class="badge text-bg-success">Enabled</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Disabled</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="/admin/ai-providers/<?= (int) $p['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($providers)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No providers configured yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
