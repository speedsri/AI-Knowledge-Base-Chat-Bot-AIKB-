<?php use function App\Core\e; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="/admin/conversations" class="text-decoration-none small">
            ← Conversations
        </a>

        <h1 class="h3 mt-2 mb-0">
            Conversation #<?= e((string) $conversation['id']) ?>
        </h1>
    </div>

    <?php
    $statusClass = match ($conversation['status']) {
        'open' => 'success',
        'escalated' => 'warning',
        'closed' => 'secondary',
        default => 'secondary',
    };
    ?>

    <span class="badge text-bg-<?= e($statusClass) ?> fs-6">
        <?= e(ucfirst($conversation['status'])) ?>
    </span>
</div>

<div class="row g-4">

    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <strong>Messages</strong>
            </div>

            <div class="card-body">
                <?php if (empty($messages)): ?>
                    <p class="text-secondary mb-0">
                        No messages recorded for this conversation.
                    </p>
                <?php endif; ?>

                <?php foreach ($messages as $m): ?>

                    <?php
                    $roleClass = match ($m['role']) {
                        'user' => 'primary',
                        'assistant' => 'success',
                        'system' => 'secondary',
                        'tool' => 'warning',
                        default => 'secondary',
                    };
                    ?>

                    <div class="mb-4">

                        <div class="d-flex justify-content-between mb-1">
                            <div>
                                <span class="badge text-bg-<?= e($roleClass) ?>">
                                    <?= e(ucfirst($m['role'])) ?>
                                </span>

                                <?php if (!empty($m['model'])): ?>
                                    <span class="small text-secondary ms-2">
                                        <?= e($m['model']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="small text-secondary">
                                <?= e($m['created_at']) ?>
                            </div>
                        </div>

                        <div class="border rounded p-3 bg-body-tertiary">
                            <?= nl2br(e($m['content'])) ?>
                        </div>

                        <?php if (
                            !empty($m['latency_ms']) ||
                            !empty($m['prompt_tokens']) ||
                            !empty($m['completion_tokens'])
                        ): ?>

                            <div class="small text-secondary mt-1">
                                <?php if (!empty($m['latency_ms'])): ?>
                                    <?= e((string) $m['latency_ms']) ?> ms
                                <?php endif; ?>

                                <?php if (!empty($m['prompt_tokens'])): ?>
                                    · Prompt:
                                    <?= e((string) $m['prompt_tokens']) ?>
                                <?php endif; ?>

                                <?php if (!empty($m['completion_tokens'])): ?>
                                    · Completion:
                                    <?= e((string) $m['completion_tokens']) ?>
                                <?php endif; ?>
                            </div>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>
            </div>
        </div>
    </div>


    <div class="col-lg-4">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white">
                <strong>Conversation Details</strong>
            </div>

            <div class="card-body small">

                <dl class="row mb-0">

                    <dt class="col-5">Channel</dt>
                    <dd class="col-7">
                        <?= e($conversation['channel']) ?>
                    </dd>

                    <dt class="col-5">Contact</dt>
                    <dd class="col-7">
                        <?= e(
                            $conversation['contact_name']
                            ?: 'Anonymous'
                        ) ?>
                    </dd>

                    <dt class="col-5">Identifier</dt>
                    <dd class="col-7">
                        <?= e(
                            $conversation['contact_identifier']
                            ?: '—'
                        ) ?>
                    </dd>

                    <dt class="col-5">Knowledge Base</dt>
                    <dd class="col-7">
                        <?= e(
                            $conversation['knowledge_base_name']
                            ?? '—'
                        ) ?>
                    </dd>

                    <dt class="col-5">User</dt>
                    <dd class="col-7">
                        <?= e(
                            $conversation['user_name']
                            ?? '—'
                        ) ?>
                    </dd>

                    <dt class="col-5">Started</dt>
                    <dd class="col-7">
                        <?= e($conversation['started_at']) ?>
                    </dd>

                    <dt class="col-5">Last message</dt>
                    <dd class="col-7">
                        <?= e($conversation['last_message_at']) ?>
                    </dd>

                    <?php if (!empty($conversation['closed_at'])): ?>

                        <dt class="col-5">Closed</dt>
                        <dd class="col-7">
                            <?= e($conversation['closed_at']) ?>
                        </dd>

                    <?php endif; ?>

                </dl>

            </div>
        </div>


        <div class="card shadow-sm">

            <div class="card-header bg-white">
                <strong>Feedback</strong>
            </div>

            <div class="card-body">

                <?php if (empty($feedback)): ?>

                    <p class="text-secondary small mb-0">
                        No feedback recorded for this conversation.
                    </p>

                <?php endif; ?>

                <?php foreach ($feedback as $f): ?>

                    <div class="border-bottom pb-3 mb-3">

                        <div>
                            <?php if ($f['rating'] === 'positive'): ?>

                                <span class="badge text-bg-success">
                                    <i class="bi bi-hand-thumbs-up"></i>
                                    Positive
                                </span>

                            <?php else: ?>

                                <span class="badge text-bg-danger">
                                    <i class="bi bi-hand-thumbs-down"></i>
                                    Negative
                                </span>

                            <?php endif; ?>
                        </div>

                        <?php if (!empty($f['comment'])): ?>

                            <div class="mt-2">
                                <?= nl2br(e($f['comment'])) ?>
                            </div>

                        <?php endif; ?>

                        <div class="small text-secondary mt-2">
                            <?= e($f['created_at']) ?>

                            <?php if (!empty($f['submitted_by_name'])): ?>
                                · <?= e($f['submitted_by_name']) ?>
                            <?php endif; ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>
        </div>

    </div>

</div>
