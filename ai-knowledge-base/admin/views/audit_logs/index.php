<?php

use function App\Core\e;

function prettyAuditJson(
    mixed $value
): string {
    if ($value === null || $value === '') {
        return '';
    }

    $decoded = json_decode(
        (string) $value,
        true
    );

    if (!is_array($decoded)) {
        return (string) $value;
    }

    return (string) json_encode(
        $decoded,
        JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
    );
}
?>

<div
    class="d-flex justify-content-between align-items-start mb-4"
>
    <div>
        <h1 class="h3 mb-1">Audit Logs</h1>
        <p class="text-secondary mb-0">
            Administrative changes recorded by the durable audit trail.
        </p>
    </div>

    <span class="badge text-bg-secondary">
        Showing up to 500 records
    </span>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form
            method="GET"
            action="/admin/audit-logs"
            class="row g-3"
        >
            <div class="col-xl-3 col-md-6">
                <label class="form-label">
                    Search
                </label>

                <input
                    type="text"
                    name="q"
                    class="form-control"
                    value="<?= e(
                        (string) $filters['q']
                    ) ?>"
                    placeholder="Action, entity, IP, user..."
                >
            </div>

            <div class="col-xl-3 col-md-6">
                <label class="form-label">
                    Action
                </label>

                <select
                    name="action"
                    class="form-select"
                >
                    <option value="">
                        All actions
                    </option>

                    <?php foreach ($actions as $value): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $filters['action'] === $value
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($value) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-xl-2 col-md-6">
                <label class="form-label">
                    Entity
                </label>

                <select
                    name="entity_type"
                    class="form-select"
                >
                    <option value="">
                        All entities
                    </option>

                    <?php foreach ($entityTypes as $value): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $filters['entity_type'] === $value
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($value) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-xl-2 col-md-6">
                <label class="form-label">
                    User
                </label>

                <select
                    name="user_id"
                    class="form-select"
                >
                    <option value="">
                        All users
                    </option>

                    <?php foreach ($users as $user): ?>
                        <option
                            value="<?= (int) $user['id'] ?>"
                            <?= $filters['user_id']
                                === (string) $user['id']
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e(
                                (string) $user['name']
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-xl-2 col-md-6">
                <label class="form-label">
                    From
                </label>

                <input
                    type="date"
                    name="date_from"
                    class="form-control"
                    value="<?= e(
                        (string) $filters['date_from']
                    ) ?>"
                >
            </div>

            <div class="col-xl-2 col-md-6">
                <label class="form-label">
                    To
                </label>

                <input
                    type="date"
                    name="date_to"
                    class="form-control"
                    value="<?= e(
                        (string) $filters['date_to']
                    ) ?>"
                >
            </div>

            <div class="col-12">
                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="bi bi-funnel me-1"></i>
                    Apply Filters
                </button>

                <a
                    href="/admin/audit-logs"
                    class="btn btn-outline-secondary"
                >
                    Clear
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table
            class="table table-hover align-middle mb-0"
        >
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>IP</th>
                    <th>Metadata</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$logs): ?>
                    <tr>
                        <td
                            colspan="7"
                            class="text-secondary text-center py-4"
                        >
                            No audit records match these filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $meta = prettyAuditJson(
                            $log['meta_json'] ?? null
                        );
                        ?>

                        <tr>
                            <td>
                                #<?= (int) $log['id'] ?>
                            </td>

                            <td class="text-nowrap">
                                <?= e(
                                    (string) $log['created_at']
                                ) ?>
                            </td>

                            <td>
                                <?php if (
                                    $log['user_id'] !== null
                                ): ?>
                                    <div class="fw-semibold">
                                        <?= e(
                                            (string) (
                                                $log['user_name']
                                                ?? 'User #'
                                                . $log['user_id']
                                            )
                                        ) ?>
                                    </div>

                                    <?php if (
                                        !empty(
                                            $log['user_email']
                                        )
                                    ): ?>
                                        <div
                                            class="small text-secondary"
                                        >
                                            <?= e(
                                                (string) $log['user_email']
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-secondary">
                                        System
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <code>
                                    <?= e(
                                        (string) $log['action']
                                    ) ?>
                                </code>
                            </td>

                            <td>
                                <?php if (
                                    !empty(
                                        $log['entity_type']
                                    )
                                ): ?>
                                    <div>
                                        <?= e(
                                            (string) $log['entity_type']
                                        ) ?>
                                    </div>

                                    <?php if (
                                        $log['entity_id']
                                        !== null
                                    ): ?>
                                        <div
                                            class="small text-secondary"
                                        >
                                            #<?= e(
                                                (string) $log['entity_id']
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td class="text-nowrap">
                                <?= e(
                                    (string) (
                                        $log['ip']
                                        ?? '—'
                                    )
                                ) ?>
                            </td>

                            <td style="min-width: 260px;">
                                <?php if ($meta !== ''): ?>
                                    <details>
                                        <summary
                                            class="small text-primary"
                                            style="cursor: pointer;"
                                        >
                                            View metadata
                                        </summary>

                                        <pre
                                            class="small bg-body-tertiary border rounded p-2 mt-2 mb-0"
                                            style="max-height: 260px; overflow: auto;"
                                        ><?= e($meta) ?></pre>
                                    </details>
                                <?php else: ?>
                                    <span class="text-secondary">
                                        —
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
