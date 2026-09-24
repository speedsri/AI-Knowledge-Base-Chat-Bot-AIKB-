<?php use function App\Core\e; ?>
<h2 class="h5 mb-3">Sign in</h2>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<form method="POST" action="/login" novalidate>
    <?= \App\Auth\Csrf::field() ?>

    <div class="mb-3">
        <label for="email" class="form-label">Email address</label>
        <input type="email" class="form-control" id="email" name="email" required autofocus autocomplete="username">
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
    </div>

    <button type="submit" class="btn btn-primary w-100">Sign in</button>
</form>

<div class="text-center mt-3">
    <a href="/forgot-password" class="small">Forgot your password?</a>
</div>
