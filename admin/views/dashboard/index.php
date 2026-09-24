<?php use function App\Core\e; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">Welcome, <?= e($user['name'] ?? 'Administrator') ?></h1>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small text-uppercase">Knowledge Bases</div>
                <div class="fs-3 fw-semibold">—</div>
                <div class="text-secondary small">Available from Phase 2</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small text-uppercase">Documents Indexed</div>
                <div class="fs-3 fw-semibold">—</div>
                <div class="text-secondary small">Available from Phase 2</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small text-uppercase">Conversations Today</div>
                <div class="fs-3 fw-semibold">—</div>
                <div class="text-secondary small">Available from Phase 3</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small text-uppercase">Background Jobs Pending</div>
                <div class="fs-3 fw-semibold" id="jobs-pending">—</div>
                <div class="text-secondary small">Worker runs every minute via cron</div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Phase 1 status</h2>
        <p class="text-secondary mb-2">
            Foundation is live: authentication, RBAC, database migrations, the
            background job queue/worker (cron-driven), and this dashboard shell.
        </p>
        <ul class="text-secondary small mb-0">
            <li>Knowledge bases, documents, and RAG land in Phase 2–3.</li>
            <li>Dynamic Technologies website import lands in Phase 4.</li>
            <li>Agriculture content and voice input land in Phases 5–6.</li>
        </ul>
    </div>
</div>
