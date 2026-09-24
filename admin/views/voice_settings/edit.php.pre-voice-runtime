<?php use function App\Core\e; ?>

<h1 class="h3 mb-4">
    <i class="bi bi-mic me-2"></i>Voice Settings
</h1>

<?php if ($error): ?>
<div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($status): ?>
<div class="alert alert-success"><?= e($status) ?></div>
<?php endif; ?>

<div class="alert alert-info" style="max-width:900px;">
    <strong>Phase E1:</strong>
    Configure the voice assistant here. Microphone recording,
    Speech-to-Text and Text-to-Speech runtime will be connected in Phase E2.
</div>

<form method="POST" action="/admin/voice-settings">
<?= \App\Auth\Csrf::field() ?>

<div class="card mb-4" style="max-width:900px;">
<div class="card-header"><strong>Voice Assistant</strong></div>
<div class="card-body">

<div class="form-check form-switch mb-4">
<input
    class="form-check-input"
    type="checkbox"
    id="is_enabled"
    name="is_enabled"
    value="1"
    <?= !empty($settings['is_enabled']) ? 'checked' : '' ?>
>
<label class="form-check-label" for="is_enabled">
    Enable Voice Assistant
</label>
</div>

<div class="row g-3">
<div class="col-md-6">
<label class="form-label">Primary Voice Provider</label>
<input
    class="form-control"
    name="provider"
    maxlength="60"
    placeholder="Example: browser, google"
    value="<?= e((string) ($settings['provider'] ?? '')) ?>"
>
</div>

<div class="col-md-6">
<label class="form-label">Voice ID</label>
<input
    class="form-control"
    name="voice_id"
    maxlength="100"
    placeholder="Provider-specific voice name or ID"
    value="<?= e((string) ($settings['voice_id'] ?? '')) ?>"
>
</div>
</div>

</div>
</div>

<div class="card mb-4" style="max-width:900px;">
<div class="card-header"><strong>Speech Recognition — STT</strong></div>
<div class="card-body">

<div class="row g-3">
<div class="col-md-4">
<label class="form-label">Language</label>
<input
    class="form-control"
    name="language"
    maxlength="20"
    value="<?= e((string) $voiceConfig['language']) ?>"
>
<div class="form-text">Examples: en, en-US, si-LK</div>
</div>

<div class="col-md-4">
<label class="form-label">STT Provider</label>
<input
    class="form-control"
    name="stt_provider"
    maxlength="150"
    placeholder="Not configured yet"
    value="<?= e((string) $voiceConfig['stt_provider']) ?>"
>
</div>

<div class="col-md-4">
<label class="form-label">STT Model</label>
<input
    class="form-control"
    name="stt_model"
    maxlength="150"
    placeholder="Not configured yet"
    value="<?= e((string) $voiceConfig['stt_model']) ?>"
>
</div>
</div>

<div class="form-check form-switch mt-4">
<input
    class="form-check-input"
    type="checkbox"
    id="auto_language_detection"
    name="auto_language_detection"
    value="1"
    <?= !empty($voiceConfig['auto_language_detection']) ? 'checked' : '' ?>
>
<label class="form-check-label" for="auto_language_detection">
    Automatic language detection
</label>
</div>

</div>
</div>

<div class="card mb-4" style="max-width:900px;">
<div class="card-header"><strong>Voice Output — TTS</strong></div>
<div class="card-body">

<div class="row g-3">
<div class="col-md-4">
<label class="form-label">TTS Provider</label>
<input
    class="form-control"
    name="tts_provider"
    maxlength="150"
    placeholder="Not configured yet"
    value="<?= e((string) $voiceConfig['tts_provider']) ?>"
>
</div>

<div class="col-md-4">
<label class="form-label">TTS Model</label>
<input
    class="form-control"
    name="tts_model"
    maxlength="150"
    placeholder="Not configured yet"
    value="<?= e((string) $voiceConfig['tts_model']) ?>"
>
</div>

<div class="col-md-4">
<label class="form-label">Speech Rate</label>
<input
    type="number"
    class="form-control"
    name="speech_rate"
    min="0.5"
    max="2"
    step="0.1"
    value="<?= e((string) $voiceConfig['speech_rate']) ?>"
>
</div>
</div>

</div>
</div>

<div class="card mb-4" style="max-width:900px;">
<div class="card-header"><strong>Recording Behaviour</strong></div>
<div class="card-body">

<div class="row g-3">

<div class="col-md-6">
<label class="form-label">Silence Timeout</label>
<div class="input-group">
<input
    type="number"
    class="form-control"
    name="silence_timeout_ms"
    min="500"
    max="10000"
    step="100"
    value="<?= (int) $voiceConfig['silence_timeout_ms'] ?>"
>
<span class="input-group-text">ms</span>
</div>
</div>

<div class="col-md-6">
<label class="form-label">Maximum Recording</label>
<div class="input-group">
<input
    type="number"
    class="form-control"
    name="max_recording_seconds"
    min="5"
    max="300"
    value="<?= (int) $voiceConfig['max_recording_seconds'] ?>"
>
<span class="input-group-text">seconds</span>
</div>
</div>

</div>
</div>
</div>

<div class="card mb-4" style="max-width:900px;">
<div class="card-header"><strong>Messages</strong></div>
<div class="card-body">

<div class="mb-3">
<label class="form-label">Welcome Message</label>
<textarea
    class="form-control"
    name="welcome_message"
    maxlength="1000"
    rows="3"
><?= e((string) $voiceConfig['welcome_message']) ?></textarea>
</div>

<div>
<label class="form-label">Voice Fallback Message</label>
<textarea
    class="form-control"
    name="fallback_message"
    maxlength="1000"
    rows="3"
><?= e((string) $voiceConfig['fallback_message']) ?></textarea>
</div>

</div>
</div>

<button class="btn btn-primary" type="submit">
<i class="bi bi-save me-1"></i>
Save Voice Settings
</button>

</form>
