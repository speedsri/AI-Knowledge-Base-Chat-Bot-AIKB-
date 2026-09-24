<?php

use App\Auth\Csrf;
use App\Core\SystemSettings;
use function App\Core\e;

$themeColor = SystemSettings::validColor(
    $settings['admin_theme_color'] ?? null
);

$baseUrl = SystemSettings::baseUrl($settings);

$loginUrl = SystemSettings::loginUrl($settings);
$chatUrl = SystemSettings::chatUrl($settings);
$widgetScriptUrl = SystemSettings::widgetScriptUrl($settings);
$widgetApiUrl = SystemSettings::widgetApiUrl($settings);

$widgetTitle = (string) (
    $settings['widget_title']
    ?? 'AI Assistant'
);

$widgetGreeting = (string) (
    $settings['widget_greeting']
    ?? 'Hello. How can I help you today?'
);

$widgetPosition = (string) (
    $settings['widget_position']
    ?? 'right'
);

$widgetLanguage = (string) (
    $settings['widget_language']
    ?? 'en-US'
);

$widgetEnabled =
    (bool) ($settings['widget_enabled'] ?? false);

$publicChatEnabled =
    (bool) ($settings['public_chat_enabled'] ?? true);

$widgetVoiceEnabled =
    (bool) ($settings['widget_voice_enabled'] ?? true);

$allowedOrigins = (string) (
    $settings['widget_allowed_origins']
    ?? ''
);

$maxAttempts = (int) (
    $settings['widget_max_attempts']
    ?? 20
);

$windowMinutes = (int) (
    $settings['widget_window_minutes']
    ?? 5
);
?>

<div class="mb-4">
    <h1 class="h3 mb-1">
        System Settings
    </h1>

    <p class="text-secondary mb-0">
        Configure branding, public access,
        application URLs and the embeddable chat widget.
    </p>
</div>

<?php if (!empty($status)): ?>
    <div class="alert alert-success">
        <?= e((string) $status) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <?= e((string) $error) ?>
    </div>
<?php endif; ?>

<form
    method="POST"
    action="/admin/system-settings"
>
    <?= Csrf::field() ?>

    <!-- ========================================================= -->
    <!-- Branding -->
    <!-- ========================================================= -->

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-body">
            <strong>
                <i class="bi bi-palette me-1"></i>
                Application Branding
            </strong>
        </div>

        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-8">
                    <label
                        for="site_name"
                        class="form-label"
                    >
                        Admin Site Name
                    </label>

                    <input
                        type="text"
                        id="site_name"
                        name="site_name"
                        maxlength="120"
                        required
                        class="form-control"
                        value="<?= e(
                            (string) (
                                $settings['site_name']
                                ?? 'AI Knowledge Base'
                            )
                        ) ?>"
                    >

                    <div class="form-text">
                        Appears in the admin interface
                        and browser title.
                    </div>
                </div>

                <div class="col-lg-4">
                    <label
                        for="admin_theme_color"
                        class="form-label"
                    >
                        Admin Theme Color
                    </label>

                    <div
                        class="d-flex align-items-center gap-3"
                    >
                        <input
                            type="color"
                            id="admin_theme_color"
                            name="admin_theme_color"
                            class="form-control form-control-color"
                            value="<?= e($themeColor) ?>"
                            title="Choose theme color"
                        >

                        <code id="theme-color-value">
                            <?= e($themeColor) ?>
                        </code>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- Application URLs & Public Chat -->
    <!-- ========================================================= -->

    <div class="card shadow-sm mb-4">
        <div
            class="card-header bg-body d-flex
                   justify-content-between
                   align-items-center"
        >
            <strong>
                <i class="bi bi-globe2 me-1"></i>
                Application URLs & Public Access
            </strong>

            <span class="badge text-bg-light border">
                First-time setup
            </span>
        </div>

        <div class="card-body">
            <div class="mb-4">
                <label
                    for="public_base_url"
                    class="form-label fw-semibold"
                >
                    Public Base URL
                </label>

                <div class="input-group">
                    <input
                        type="url"
                        id="public_base_url"
                        name="public_base_url"
                        maxlength="255"
                        class="form-control"
                        placeholder="https://ai.example.com"
                        value="<?= e($baseUrl) ?>"
                    >

                    <button
                        type="button"
                        id="use-current-url"
                        class="btn btn-outline-secondary"
                    >
                        <i class="bi bi-crosshair"></i>
                        Use Current URL
                    </button>
                </div>

                <div class="form-text">
                    Enter only the main AIKB address,
                    for example
                    <code>https://ai.example.com</code>.
                    Do not add <code>/login</code>
                    or <code>/chat</code>.
                </div>

                <div class="alert alert-warning mt-3 mb-0 py-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Saving this URL does not create DNS,
                    SSL, Cloudflare or reverse-proxy
                    configuration. The address must already
                    point to this AIKB installation.
                </div>
            </div>

            <div class="mb-4">
                <div class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        role="switch"
                        id="public_chat_enabled"
                        name="public_chat_enabled"
                        value="1"
                        <?= $publicChatEnabled
                            ? 'checked'
                            : '' ?>
                    >

                    <label
                        class="form-check-label fw-semibold"
                        for="public_chat_enabled"
                    >
                        Enable Public Chat
                    </label>
                </div>

                <div class="form-text">
                    Controls public access to
                    <code>/chat</code>.
                    This is independent from the
                    embedded widget.
                </div>
            </div>

            <h6 class="mb-3">
                Generated Application URLs
            </h6>

            <div class="row g-3">
                <?php
                $generatedUrls = [
                    [
                        'label' => 'Admin Login URL',
                        'id' => 'generated-login-url',
                        'value' => $loginUrl,
                    ],
                    [
                        'label' => 'Public Chat URL',
                        'id' => 'generated-chat-url',
                        'value' => $chatUrl,
                    ],
                    [
                        'label' => 'Widget Script URL',
                        'id' => 'generated-widget-script-url',
                        'value' => $widgetScriptUrl,
                    ],
                    [
                        'label' => 'Widget API URL',
                        'id' => 'generated-widget-api-url',
                        'value' => $widgetApiUrl,
                    ],
                ];
                ?>

                <?php foreach ($generatedUrls as $item): ?>
                    <div class="col-lg-6">
                        <label
                            class="form-label"
                            for="<?= e($item['id']) ?>"
                        >
                            <?= e($item['label']) ?>
                        </label>

                        <div class="input-group">
                            <input
                                type="text"
                                readonly
                                class="form-control generated-url"
                                id="<?= e($item['id']) ?>"
                                value="<?= e($item['value']) ?>"
                            >

                            <button
                                type="button"
                                class="btn btn-outline-secondary copy-field"
                                data-copy-target="<?= e($item['id']) ?>"
                                title="Copy"
                            >
                                <i class="bi bi-copy"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- Widget -->
    <!-- ========================================================= -->

    <div class="card shadow-sm mb-4">
        <div
            class="card-header bg-body d-flex
                   justify-content-between
                   align-items-center"
        >
            <strong>
                <i class="bi bi-chat-dots me-1"></i>
                Embedded Chat Widget
            </strong>

            <span
                id="widget-status-badge"
                class="badge <?= $widgetEnabled
                    ? 'text-bg-success'
                    : 'text-bg-secondary' ?>"
            >
                <?= $widgetEnabled
                    ? 'Enabled'
                    : 'Disabled' ?>
            </span>
        </div>

        <div class="card-body">
            <div class="form-check form-switch mb-4">
                <input
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    id="widget_enabled"
                    name="widget_enabled"
                    value="1"
                    <?= $widgetEnabled
                        ? 'checked'
                        : '' ?>
                >

                <label
                    class="form-check-label fw-semibold"
                    for="widget_enabled"
                >
                    Enable Embedded Widget
                </label>

                <div class="form-text">
                    Allows authorized external websites
                    to send messages through the
                    AIKB widget endpoint.
                </div>
            </div>

            <div class="mb-4">
                <label
                    for="widget_allowed_origins"
                    class="form-label fw-semibold"
                >
                    Allowed Website Origins
                </label>

                <textarea
                    id="widget_allowed_origins"
                    name="widget_allowed_origins"
                    rows="4"
                    class="form-control"
                    placeholder="https://example.com&#10;https://www.example.com"
                ><?= e($allowedOrigins) ?></textarea>

                <div class="form-text">
                    Enter one origin per line or
                    separate origins with commas.
                    Use only
                    <code>scheme + hostname + optional port</code>.
                    Do not enter page paths such as
                    <code>/index.php</code>.
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-lg-6">
                    <label
                        for="widget_title"
                        class="form-label"
                    >
                        Widget Title
                    </label>

                    <input
                        type="text"
                        id="widget_title"
                        name="widget_title"
                        maxlength="120"
                        required
                        class="form-control"
                        value="<?= e($widgetTitle) ?>"
                    >
                </div>

                <div class="col-lg-3">
                    <label
                        for="widget_position"
                        class="form-label"
                    >
                        Position
                    </label>

                    <select
                        id="widget_position"
                        name="widget_position"
                        class="form-select"
                    >
                        <option
                            value="right"
                            <?= $widgetPosition === 'right'
                                ? 'selected'
                                : '' ?>
                        >
                            Right
                        </option>

                        <option
                            value="left"
                            <?= $widgetPosition === 'left'
                                ? 'selected'
                                : '' ?>
                        >
                            Left
                        </option>
                    </select>
                </div>

                <div class="col-lg-3">
                    <label
                        for="widget_language"
                        class="form-label"
                    >
                        Language
                    </label>

                    <input
                        type="text"
                        id="widget_language"
                        name="widget_language"
                        maxlength="20"
                        class="form-control"
                        value="<?= e($widgetLanguage) ?>"
                        placeholder="en-US"
                    >
                </div>
            </div>

            <div class="mb-4">
                <label
                    for="widget_greeting"
                    class="form-label"
                >
                    Greeting Message
                </label>

                <textarea
                    id="widget_greeting"
                    name="widget_greeting"
                    rows="3"
                    maxlength="500"
                    class="form-control"
                ><?= e($widgetGreeting) ?></textarea>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-lg-4">
                    <div class="form-check form-switch mt-lg-4 pt-lg-2">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            role="switch"
                            id="widget_voice_enabled"
                            name="widget_voice_enabled"
                            value="1"
                            <?= $widgetVoiceEnabled
                                ? 'checked'
                                : '' ?>
                        >

                        <label
                            class="form-check-label"
                            for="widget_voice_enabled"
                        >
                            Enable Widget Voice
                        </label>
                    </div>
                </div>

                <div class="col-lg-4">
                    <label
                        for="widget_max_attempts"
                        class="form-label"
                    >
                        Max Requests
                    </label>

                    <input
                        type="number"
                        min="1"
                        max="1000"
                        id="widget_max_attempts"
                        name="widget_max_attempts"
                        class="form-control"
                        value="<?= e((string) $maxAttempts) ?>"
                    >
                </div>

                <div class="col-lg-4">
                    <label
                        for="widget_window_minutes"
                        class="form-label"
                    >
                        Rate-limit Window
                    </label>

                    <div class="input-group">
                        <input
                            type="number"
                            min="1"
                            max="1440"
                            id="widget_window_minutes"
                            name="widget_window_minutes"
                            class="form-control"
                            value="<?= e(
                                (string) $windowMinutes
                            ) ?>"
                        >

                        <span class="input-group-text">
                            minutes
                        </span>
                    </div>
                </div>
            </div>

            <hr>

            <div
                class="d-flex justify-content-between
                       align-items-center mb-2"
            >
                <h6 class="mb-0">
                    Website Embed Snippet
                </h6>

                <button
                    type="button"
                    id="copy-widget-snippet"
                    class="btn btn-sm btn-outline-primary"
                >
                    <i class="bi bi-copy me-1"></i>
                    Copy Snippet
                </button>
            </div>

            <textarea
                id="widget-snippet"
                class="form-control font-monospace"
                rows="9"
                readonly
            ></textarea>

            <div class="form-text">
                Paste this code before the website's
                closing <code>&lt;/body&gt;</code> tag
                or inside its shared footer.
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- Actions -->
    <!-- ========================================================= -->

    <div class="d-flex gap-2 mb-5">
        <button
            type="submit"
            class="btn btn-primary"
        >
            <i class="bi bi-save me-1"></i>
            Save System Settings
        </button>

        <a
            href="/admin"
            class="btn btn-outline-secondary"
        >
            Cancel
        </a>
    </div>
</form>

<script>
document.addEventListener(
    'DOMContentLoaded',
    function () {
        const picker =
            document.getElementById(
                'admin_theme_color'
            );

        const colorValue =
            document.getElementById(
                'theme-color-value'
            );

        if (picker && colorValue) {
            picker.addEventListener(
                'input',
                function () {
                    colorValue.textContent =
                        picker.value;
                }
            );
        }

        const baseInput =
            document.getElementById(
                'public_base_url'
            );

        const titleInput =
            document.getElementById(
                'widget_title'
            );

        const greetingInput =
            document.getElementById(
                'widget_greeting'
            );

        const positionInput =
            document.getElementById(
                'widget_position'
            );

        const languageInput =
            document.getElementById(
                'widget_language'
            );

        const voiceInput =
            document.getElementById(
                'widget_voice_enabled'
            );

        const snippet =
            document.getElementById(
                'widget-snippet'
            );

        function cleanBaseUrl(value) {
            return String(value || '')
                .trim()
                .replace(/\/+$/, '');
        }

        function updateGeneratedValues() {
            const base =
                cleanBaseUrl(
                    baseInput
                        ? baseInput.value
                        : ''
                );

            const login =
                base
                    ? base + '/login'
                    : '/login';

            const chat =
                base
                    ? base + '/chat'
                    : '/chat';

            const script =
                base
                    ? base + '/assets/chat-widget.js'
                    : '/assets/chat-widget.js';

            const api =
                base
                    ? base
                    : '';

            const endpoint =
                base
                    ? base + '/widget/message'
                    : '/widget/message';

            const fields = {
                'generated-login-url': login,
                'generated-chat-url': chat,
                'generated-widget-script-url': script,
                'generated-widget-api-url': endpoint
            };

            Object.keys(fields).forEach(
                function (id) {
                    const el =
                        document.getElementById(id);

                    if (el) {
                        el.value = fields[id];
                    }
                }
            );

            if (snippet) {
                const title =
                    titleInput
                        ? titleInput.value
                        : 'AI Assistant';

                const greeting =
                    greetingInput
                        ? greetingInput.value
                        : 'Hello. How can I help you today?';

                const position =
                    positionInput
                        ? positionInput.value
                        : 'right';

                const language =
                    languageInput
                        ? languageInput.value
                        : 'en-US';

                const voiceEnabled =
                    voiceInput
                        ? voiceInput.checked
                        : true;

                snippet.value =
`<script
    src="${script}"
    data-api="${api}"
    data-title="${title}"
    data-greeting="${greeting}"
    data-position="${position}"
    data-language="${language}"
    data-voice="${voiceEnabled ? 'true' : 'false'}">
<\/script>`;
            }
        }

        [
            baseInput,
            titleInput,
            greetingInput,
            positionInput,
            languageInput,
            voiceInput
        ].forEach(
            function (element) {
                if (!element) {
                    return;
                }

                element.addEventListener(
                    'input',
                    updateGeneratedValues
                );

                element.addEventListener(
                    'change',
                    updateGeneratedValues
                );
            }
        );

        const useCurrentUrl =
            document.getElementById(
                'use-current-url'
            );

        if (useCurrentUrl && baseInput) {
            useCurrentUrl.addEventListener(
                'click',
                function () {
                    baseInput.value =
                        window.location.origin;

                    updateGeneratedValues();
                }
            );
        }

        document
            .querySelectorAll(
                '.copy-field'
            )
            .forEach(
                function (button) {
                    button.addEventListener(
                        'click',
                        async function () {
                            const id =
                                button.getAttribute(
                                    'data-copy-target'
                                );

                            const field =
                                document.getElementById(id);

                            if (!field) {
                                return;
                            }

                            try {
                                await navigator.clipboard.writeText(
                                    field.value
                                );

                                const icon =
                                    button.querySelector('i');

                                if (icon) {
                                    icon.className =
                                        'bi bi-check-lg';
                                }

                                setTimeout(
                                    function () {
                                        if (icon) {
                                            icon.className =
                                                'bi bi-copy';
                                        }
                                    },
                                    1200
                                );
                            } catch (error) {
                                field.select();
                            }
                        }
                    );
                }
            );

        const copySnippet =
            document.getElementById(
                'copy-widget-snippet'
            );

        if (copySnippet && snippet) {
            copySnippet.addEventListener(
                'click',
                async function () {
                    try {
                        await navigator.clipboard.writeText(
                            snippet.value
                        );

                        copySnippet.innerHTML =
                            '<i class="bi bi-check-lg me-1"></i>Copied';

                        setTimeout(
                            function () {
                                copySnippet.innerHTML =
                                    '<i class="bi bi-copy me-1"></i>Copy Snippet';
                            },
                            1400
                        );
                    } catch (error) {
                        snippet.select();
                    }
                }
            );
        }

        const widgetEnabled =
            document.getElementById(
                'widget_enabled'
            );

        const widgetBadge =
            document.getElementById(
                'widget-status-badge'
            );

        if (widgetEnabled && widgetBadge) {
            widgetEnabled.addEventListener(
                'change',
                function () {
                    if (widgetEnabled.checked) {
                        widgetBadge.textContent =
                            'Enabled';

                        widgetBadge.className =
                            'badge text-bg-success';
                    } else {
                        widgetBadge.textContent =
                            'Disabled';

                        widgetBadge.className =
                            'badge text-bg-secondary';
                    }
                }
            );
        }

        updateGeneratedValues();
    }
);
</script>
