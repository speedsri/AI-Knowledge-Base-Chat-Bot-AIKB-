<?php use function App\Core\e; ?>
<h2 class="h5 mb-3">Reset your password</h2>

<?php if (!empty($status)): ?>
    <div class="alert alert-info py-2 small" role="alert"><?= e($status) ?></div>
<?php endif; ?>

<form method="POST" action="/forgot-password" novalidate>
    <?= \App\Auth\Csrf::field() ?>

    <div class="mb-3">
        <label for="email" class="form-label">Email address</label>
        <input type="email" class="form-control" id="email" name="email" required autofocus autocomplete="username">
    </div>

    <button type="submit" class="btn btn-primary w-100">Send reset instructions</button>
</form>

<div class="text-center mt-3">
    <a href="/login" class="small">Back to sign in</a>
</div>
