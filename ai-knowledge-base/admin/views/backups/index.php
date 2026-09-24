<?php

use App\Auth\Csrf;
use function App\Core\e;

function backupSize(
    int $bytes
): string {
    if ($bytes >= 1024 * 1024) {
        return number_format(
            $bytes / 1024 / 1024,
            2
        ) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format(
            $bytes / 1024,
            2
        ) . ' KB';
    }

    return $bytes . ' B';
}
?>

<div
    class="d-flex justify-content-between align-items-start mb-4"
>
    <div>
        <h1 class="h3 mb-1">
            Backups
        </h1>

        <p class="text-secondary mb-0">
            Create and download protected
            AI Knowledge Base backups.
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

<div class="alert alert-warning">
    <i class="bi bi-shield-exclamation me-2"></i>
    Restore is intentionally not available from
    the web interface because restoring can overwrite
    production data.
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">
                    <i class="bi bi-database me-2"></i>
                    Database Backup
                </h2>

                <p class="text-secondary">
                    Creates a compressed SQL backup of
                    the complete AIKB MySQL database,
                    including schema and data.
                </p>

                <form
                    method="POST"
                    action="/admin/backups/database"
                >
                    <?= Csrf::field() ?>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-download me-1"></i>
                        Create Database Backup
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">
                    <i class="bi bi-file-earmark-zip me-2"></i>
                    Application Backup
                </h2>

                <p class="text-secondary">
                    Archives the AIKB source and configuration
                    structure. Environment secrets, runtime
                    documents, logs, cache, and previous backups
                    are excluded.
                </p>

                <form
                    method="POST"
                    action="/admin/backups/application"
                >
                    <?= Csrf::field() ?>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-archive me-1"></i>
                        Create Application Backup
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-body">
        <strong>Backup History</strong>
    </div>

    <div class="table-responsive">
        <table
            class="table table-hover align-middle mb-0"
        >
            <thead>
                <tr>
                    <th>File</th>
                    <th>Type</th>
                    <th>Size</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$backups): ?>
                    <tr>
                        <td
                            colspan="5"
                            class="text-center text-secondary py-4"
                        >
                            No backups have been created yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td>
                                <code>
                                    <?= e(
                                        (string) $backup['name']
                                    ) ?>
                                </code>
                            </td>

                            <td>
                                <?= e(
                                    (string) $backup['type']
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    backupSize(
                                        (int) $backup['size']
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    date(
                                        'Y-m-d H:i:s',
                                        (int) $backup['created_at']
                                    )
                                ) ?>
                            </td>

                            <td class="text-end">
                                <a
                                    href="/admin/backups/download/<?= rawurlencode((string) $backup['name']) ?>"
                                    class="btn btn-sm btn-outline-primary"
                                >
                                    <i class="bi bi-download me-1"></i>
                                    Download
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
