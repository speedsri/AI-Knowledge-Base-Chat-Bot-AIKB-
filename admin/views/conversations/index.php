<?php use function App\Core\e; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Conversations</h1>
        <p class="text-secondary mb-0">Chat and voice assistant conversation history.</p>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="/admin/conversations" class="row g-2">
            <div class="col-lg-5">
                <input
                    type="text"
                    name="q"
                    value="<?= e($filters['q'] ?? '') ?>"
                    class="form-control"
                    placeholder="Search name, identifier or conversation ID"
                >
            </div>

            <div class="col-lg-3">
                <select name="status" class="form-select">
                    <option value="">All statuses</option>
                    <?php foreach (['open', 'closed', 'escalated'] as $s): ?>
                        <option value="<?= e($s) ?>"
                            <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>>
                            <?= e(ucfirst($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2">
                <select name="channel" class="form-select">
                    <option value="">All channels</option>
                    <?php foreach ($channels as $ch): ?>
                        <option value="<?= e($ch) ?>"
                            <?= ($filters['channel'] ?? '') === $ch ? 'selected' : '' ?>>
                            <?= e(ucfirst($ch)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2">
                <button class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Contact</th>
                        <th>Channel</th>
                        <th>Knowledge Base</th>
                        <th>Status</th>
                        <th>Messages</th>
                        <th>Last message</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($conversations)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-4">
                                No conversations recorded yet.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($conversations as $c): ?>
                        <tr>
                            <td>#<?= e((string) $c['id']) ?></td>

                            <td>
                                <div><?= e($c['contact_name'] ?: 'Anonymous') ?></div>
                                <?php if (!empty($c['contact_identifier'])): ?>
                                    <div class="small text-secondary">
                                        <?= e($c['contact_identifier']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge text-bg-light border">
                                    <?= e($c['channel']) ?>
                                </span>
                            </td>

                            <td><?= e($c['knowledge_base_name'] ?? '—') ?></td>

                            <td>
                                <?php
                                $statusClass = match ($c['status']) {
                                    'open' => 'success',
                                    'escalated' => 'warning',
                                    'closed' => 'secondary',
                                    default => 'secondary',
                                };
                                ?>
                                <span class="badge text-bg-<?= e($statusClass) ?>">
                                    <?= e(ucfirst($c['status'])) ?>
                                </span>
                            </td>

                            <td><?= e((string) $c['message_count']) ?></td>

                            <td class="small text-secondary">
                                <?= e($c['last_message_at']) ?>
                            </td>

                            <td class="text-end">
                                <a
                                    href="/admin/conversations/<?= e((string) $c['id']) ?>"
                                    class="btn btn-sm btn-outline-primary"
                                >
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
