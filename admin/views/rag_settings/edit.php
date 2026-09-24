<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">RAG Settings</h1>

<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if ($status): ?><div class="alert alert-success"><?= e($status) ?></div><?php endif; ?>

<div class="alert alert-info">
    Saving these settings updates MySQL (authoritative) and attempts to push
    them live to the RAG backend immediately via <code>/v1/config/sync</code>.
    If the RAG backend is unreachable, MySQL is still updated — see the
    message after saving, and check System Health for current backend status.
    Note: <strong>Active Model</strong> and <strong>Fallback Model</strong> are
    stored here for reference but are configured directly on the RAG backend
    itself (its own <code>GENERATION_MODEL</code>/<code>GENERATION_FALLBACK_MODEL</code>
    environment values) — only System Prompt, Temperature, Top-K, Similarity
    Threshold, and Escalation Keywords are synced live.
</div>

<?php $keywords = implode(', ', json_decode($settings['escalation_keywords'] ?? '[]', true) ?: []); ?>

<div class="card" style="max-width: 720px;">
    <div class="card-body">
        <form method="POST" action="/admin/rag-settings">
            <?= \App\Auth\Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label">Active Provider</label>
                <select name="active_provider_id" class="form-select">
                    <option value="">None selected</option>
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($settings['active_provider_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['display_name'] ?: $p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">Active Model</label>
                    <input type="text" name="active_model" class="form-control" required maxlength="60" value="<?= e($settings['active_model']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Fallback Model</label>
                    <input type="text" name="fallback_model" class="form-control" required maxlength="60" value="<?= e($settings['fallback_model']) ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">System Prompt</label>
                <textarea name="system_prompt" class="form-control" rows="5" required><?= e($settings['system_prompt']) ?></textarea>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Temperature</label>
                    <input type="number" step="0.05" min="0" max="1" name="temperature" class="form-control" value="<?= e((string) $settings['temperature']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Top-K Retrieval</label>
                    <input type="number" min="1" max="20" name="top_k_retrieval" class="form-control" value="<?= (int) $settings['top_k_retrieval'] ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Similarity Threshold</label>
                    <input type="number" step="0.01" min="0" max="1" name="similarity_threshold" class="form-control" value="<?= e((string) $settings['similarity_threshold']) ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Escalation Keywords (comma-separated)</label>
                <input type="text" name="escalation_keywords" class="form-control" value="<?= e($keywords) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Save</button>
        </form>
    </div>
</div>

<div class="card mt-4" style="max-width: 720px;">
    <div class="card-header">Test Live Connection</div>
    <div class="card-body">
        <p class="text-muted">
            Calls the RAG backend's provider diagnostic directly, without saving
            anything. The Gemini API key is never sent to or displayed by this
            browser — only the result of the RAG backend's own check.
        </p>
        <button type="button" id="rag-test-connection-btn" class="btn btn-outline-secondary">Test Connection</button>
        <div id="rag-test-connection-result" class="mt-3"></div>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('rag-test-connection-btn');
    var resultEl = document.getElementById('rag-test-connection-result');
    var csrfInput = document.querySelector('input[name="csrf_token"]');
    var csrfToken = csrfInput ? csrfInput.value : '';

    function showResult(message, alertClass) {
        var alert = document.createElement('div');
        alert.className = 'alert ' + alertClass + ' mb-0';
        alert.textContent = message;

        resultEl.replaceChildren(alert);
    }

    btn.addEventListener('click', function () {
        btn.disabled = true;
        btn.textContent = 'Testing...';
        resultEl.replaceChildren();

        fetch('/admin/rag-settings/test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'csrf_token=' + encodeURIComponent(csrfToken)
        })
            .then(function (resp) {
                if (!resp.ok) {
                    throw new Error('HTTP ' + resp.status);
                }

                return resp.json();
            })
            .then(function (data) {
                var alertClass;
                var message;

                if (!data.ok) {
                    alertClass = 'alert-danger';
                    message = 'Could not reach the RAG backend.';
                } else if (!data.configured) {
                    alertClass = 'alert-warning';
                    message = 'RAG backend reachable, but the Gemini API key is not configured there.';
                } else if (data.healthy) {
                    alertClass = 'alert-success';
                    message =
                        'Healthy — ' +
                        String(data.embedding_model || 'unknown') +
                        ' (dim=' +
                        String(data.embedding_dimension || 'unknown') +
                        '), ' +
                        String(data.latency_ms || 'unknown') +
                        'ms. Generation model: ' +
                        String(data.generation_model || 'unknown') +
                        '.';
                } else {
                    alertClass = 'alert-warning';
                    message =
                        'RAG backend configured but reported unhealthy (' +
                        String(data.error_code || 'unknown') +
                        ').';
                }

                showResult(message, alertClass);
            })
            .catch(function () {
                showResult('Request failed.', 'alert-danger');
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = 'Test Connection';
            });
    });
})();
</script>
