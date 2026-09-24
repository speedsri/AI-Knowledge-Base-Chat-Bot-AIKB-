<?php use function App\Core\e; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Users</h1>
</div>

<?php if (!empty($status)): ?>
    <div class="alert alert-success py-2 small"><?= e($status) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3">All users</h2>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Roles</th>
                                <th>Status</th>
                                <th>Last login</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <tr>
                                    <td><?= e($u['name']) ?></td>
                                    <td><?= e($u['email']) ?></td>
                                    <td><?= e($u['roles'] ?? '—') ?></td>
                                    <td>
                                        <?php if ((int) $u['is_active'] === 1): ?>
                                            <span class="badge text-bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-secondary small">
                                        <?= e($u['last_login_at'] ?? 'Never') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3">Add a user</h2>
                <form method="POST" action="/admin/users">
                    <?= \App\Auth\Csrf::field() ?>

                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Temporary password</label>
                        <input type="password" name="password" class="form-control" minlength="10" required>
                        <div class="form-text">At least 10 characters. The user should change it after first login.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select">
                            <?php foreach ($roles as $roleName): ?>
                                <option value="<?= e($roleName) ?>" <?= $roleName === 'Viewer' ? 'selected' : '' ?>>
                                    <?= e($roleName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Create user</button>
                </form>
            </div>
        </div>
    </div>
</div>
