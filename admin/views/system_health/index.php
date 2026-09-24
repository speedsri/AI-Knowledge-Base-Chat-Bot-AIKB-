<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">System Health</h1>

<div class="card mb-4">
    <div class="card-header">Currently Deployed (checked live)</div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <tbody>
                <?php
                $labels = [
                    'mysql' => 'MySQL Database',
                    'storage' => 'Local Storage',
                    'background_jobs' => 'Background Job Queue',
                    'environment' => 'Application Environment',
                ];
                $badgeClass = ['ok' => 'success', 'warning' => 'warning', 'error' => 'danger'];
                ?>
                <?php foreach ($checks as $key => $check): ?>
                    <tr>
                        <td style="width: 260px;"><?= e($labels[$key] ?? $key) ?></td>
                        <td><span class="badge text-bg-<?= $badgeClass[$check['status']] ?? 'secondary' ?>"><?= e(strtoupper($check['status'])) ?></span></td>
                        <td class="text-muted"><?= e($check['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">Execution Plane (RAG backend, .220:8500) — live, Phase B.1</div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <tbody>
                <?php
                $ragLabels = [
                    'rag_api' => 'RAG Backend (rag-api)',
                    'qdrant' => 'Vector Database (Qdrant)',
                    'gemini_provider' => 'Gemini Provider (configuration only)',
                ];
                ?>
                <?php foreach ($ragChecks as $key => $check): ?>
                    <tr>
                        <td style="width: 260px;"><?= e($ragLabels[$key] ?? $key) ?></td>
                        <td><span class="badge text-bg-<?= $badgeClass[$check['status']] ?? 'secondary' ?>"><?= e(strtoupper($check['status'])) ?></span></td>
                        <td class="text-muted"><?= e($check['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted small mt-2">
    These rows call only the RAG API's <code>GET /health</code> endpoint on
    every page load — a cheap check that does not call Gemini. The Gemini
    Provider row reflects whether an API key is configured on the RAG
    backend, not a live health check. For a real, on-demand provider test
    (which does perform a real embedding call and so is not run
    automatically), use <a href="/admin/rag-settings">RAG Settings → Test
    Connection</a>. The Gemini API key itself is never returned by the RAG
    API and is not shown here or anywhere in AIKB.
</p>
