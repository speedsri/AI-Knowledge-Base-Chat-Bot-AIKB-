<?php use function App\Core\e; ?>

<h1 class="h3 mb-4">Edit AI Provider</h1>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($status): ?>
    <div class="alert alert-success"><?= e($status) ?></div>
<?php endif; ?>

<div class="card" style="max-width: 720px;">
    <div class="card-body">

        <form
            method="POST"
            action="/admin/ai-providers/<?= (int) $provider['id'] ?>"
        >
            <?= \App\Auth\Csrf::field() ?>

            <div class="mb-3">
                <label class="form-label">
                    Name (internal key)
                </label>

                <input
                    type="text"
                    class="form-control"
                    disabled
                    value="<?= e($provider['name']) ?>"
                >
            </div>

            <div class="mb-3">
                <label class="form-label">
                    Display name
                </label>

                <input
                    type="text"
                    name="display_name"
                    class="form-control"
                    maxlength="150"
                    value="<?= e($provider['display_name'] ?? '') ?>"
                >
            </div>

            <div class="mb-3">
                <label class="form-label">
                    Model registry
                </label>

                <textarea
                    name="model_registry"
                    class="form-control"
                    rows="4"
                ><?= e(
                    implode(
                        "\n",
                        json_decode(
                            $provider['model_registry'] ?? '[]',
                            true
                        ) ?: []
                    )
                ) ?></textarea>

                <div class="form-text">
                    One model ID per line.
                </div>
            </div>

            <hr>

            <h5>API Credential</h5>

            <?php if ($credentialConfigured): ?>

                <div class="alert alert-success">
                    <strong>Credential configured.</strong><br>

                    Saved key:
                    ••••••••••••<?= e(
                        $provider['api_key_last4'] ?? ''
                    ) ?>

                    <?php if (!empty(
                        $provider['credential_updated_at']
                    )): ?>
                        <br>
                        Last updated:
                        <?= e(
                            $provider['credential_updated_at']
                        ) ?>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        Replace API Key
                    </label>

                    <input
                        type="password"
                        name="api_key"
                        class="form-control"
                        maxlength="1024"
                        autocomplete="new-password"
                        placeholder="Leave blank to keep existing credential"
                    >

                    <div class="form-text">
                        The existing key is never displayed.
                        Enter a new key only when replacing it.
                    </div>
                </div>

            <?php else: ?>

                <div class="alert alert-warning">
                    No encrypted API credential is currently
                    stored for this provider.
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        Gemini API Key
                    </label>

                    <input
                        type="password"
                        name="api_key"
                        class="form-control"
                        maxlength="1024"
                        autocomplete="new-password"
                        placeholder="Enter Gemini API key"
                    >

                    <div class="form-text">
                        The key will be encrypted with libsodium
                        before it is stored in MySQL.
                    </div>
                </div>

            <?php endif; ?>

            <div class="form-check mb-3">
                <input
                    type="checkbox"
                    name="is_enabled"
                    id="is_enabled"
                    class="form-check-input"
                    <?= (int) $provider['is_enabled'] === 1
                        ? 'checked'
                        : '' ?>
                >

                <label
                    class="form-check-label"
                    for="is_enabled"
                >
                    Enabled
                </label>
            </div>

            <button
                type="submit"
                class="btn btn-primary"
            >
                Save
            </button>

            <a
                href="/admin/ai-providers"
                class="btn btn-link"
            >
                Cancel
            </a>

        </form>

        <?php if ($credentialConfigured): ?>

            <hr>

            <form
                method="POST"
                action="/admin/ai-providers/<?= (int) $provider['id'] ?>/test"
            >
                <?= \App\Auth\Csrf::field() ?>

                <button
                    type="submit"
                    class="btn btn-outline-primary"
                >
                    Test &amp; Apply to RAG
                </button>

                <div class="form-text mt-2">
                    Validates the saved encrypted credential with
                    Gemini using a real embedding request. The
                    current RAG credential is replaced only after
                    successful validation.
                </div>
            </form>

        <?php endif; ?>

    </div>
</div>
