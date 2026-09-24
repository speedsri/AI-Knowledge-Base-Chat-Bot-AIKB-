<?php

use App\Auth\Csrf;
use function App\Core\e;
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="h3 mb-1">Roles</h1>
        <p class="text-secondary mb-0">
            Manage administrative access and permissions.
        </p>
    </div>
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

<div class="alert alert-info">
    <i class="bi bi-shield-check me-2"></i>
    The Administrator role is protected and always retains
    every system permission.
</div>

<?php foreach ($roles as $role): ?>
    <?php
    $roleId = (int) $role['id'];

    $isAdministrator =
        (string) $role['name']
        === 'Administrator';
    ?>

    <form
        method="POST"
        action="/admin/roles/<?= $roleId ?>"
        class="card shadow-sm mb-4"
    >
        <div
            class="card-header bg-body d-flex justify-content-between align-items-center"
        >
            <div>
                <strong class="fs-5">
                    <?= e((string) $role['name']) ?>
                </strong>

                <?php if ($isAdministrator): ?>
                    <span class="badge text-bg-primary ms-2">
                        Protected
                    </span>
                <?php endif; ?>
            </div>

            <span class="badge text-bg-secondary">
                <?= number_format(
                    (int) $role['user_count']
                ) ?>
                user<?= (int) $role['user_count'] === 1 ? '' : 's' ?>
            </span>
        </div>

        <div class="card-body">
            <?= Csrf::field() ?>

            <div class="mb-4">
                <label
                    class="form-label"
                    for="description-<?= $roleId ?>"
                >
                    Description
                </label>

                <input
                    id="description-<?= $roleId ?>"
                    type="text"
                    class="form-control"
                    name="description"
                    maxlength="255"
                    value="<?= e(
                        (string) (
                            $role['description']
                            ?? ''
                        )
                    ) ?>"
                    placeholder="Describe what this role is intended for"
                >
            </div>

            <div class="row g-3">
                <?php foreach ($permissions as $permission): ?>
                    <?php
                    $permissionId =
                        (int) $permission['id'];

                    $checked =
                        $isAdministrator
                        || !empty(
                            $assigned[$roleId][$permissionId]
                        );
                    ?>

                    <div class="col-xl-4 col-md-6">
                        <div
                            class="border rounded p-3 h-100"
                        >
                            <div class="form-check">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="permissions[]"
                                    value="<?= $permissionId ?>"
                                    id="role-<?= $roleId ?>-permission-<?= $permissionId ?>"
                                    <?= $checked ? 'checked' : '' ?>
                                    <?= $isAdministrator ? 'disabled' : '' ?>
                                >

                                <label
                                    class="form-check-label fw-semibold"
                                    for="role-<?= $roleId ?>-permission-<?= $permissionId ?>"
                                >
                                    <?= e(
                                        (string) $permission['key_name']
                                    ) ?>
                                </label>
                            </div>

                            <div class="small text-secondary mt-2">
                                <?= e(
                                    (string) (
                                        $permission['description']
                                        ?? ''
                                    )
                                ) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card-footer bg-body text-end">
            <button
                type="submit"
                class="btn btn-primary"
            >
                <i class="bi bi-save me-1"></i>
                Save <?= e((string) $role['name']) ?>
            </button>
        </div>
    </form>
<?php endforeach; ?>
