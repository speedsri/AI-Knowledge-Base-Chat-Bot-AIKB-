<?php

use function App\Core\e;

$conversationsTotal =
    (int) ($summary['conversations_total'] ?? 0);

$conversationsToday =
    (int) ($summary['conversations_today'] ?? 0);

$userMessages =
    (int) ($summary['user_messages'] ?? 0);

$assistantMessages =
    (int) ($summary['assistant_messages'] ?? 0);

$groundedAnswers =
    (int) ($summary['grounded_answers'] ?? 0);

$escalatedAnswers =
    (int) ($summary['escalated_answers'] ?? 0);

$avgLatency =
    (int) ($summary['avg_latency_ms'] ?? 0);

$avgConfidence =
    (float) ($summary['avg_confidence'] ?? 0);

$groundedRate = $assistantMessages > 0
    ? round(
        ($groundedAnswers / $assistantMessages) * 100,
        1
    )
    : 0;

$escalationRate = $assistantMessages > 0
    ? round(
        ($escalatedAnswers / $assistantMessages) * 100,
        1
    )
    : 0;

$positive =
    (int) ($feedback['positive'] ?? 0);

$negative =
    (int) ($feedback['negative'] ?? 0);

$feedbackTotal =
    (int) ($feedback['total'] ?? 0);

$positiveRate = $feedbackTotal > 0
    ? round(
        ($positive / $feedbackTotal) * 100,
        1
    )
    : 0;

$modeTotals = [];

foreach ($inputModes as $mode) {
    $modeTotals[(string) $mode['input_mode']] =
        (int) $mode['total'];
}

$textMessages =
    (int) ($modeTotals['text'] ?? 0);

$voiceMessages =
    (int) ($modeTotals['voice'] ?? 0);

$otherMessages =
    (int) ($modeTotals['other'] ?? 0);
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h1 class="h3 mb-1">Analytics</h1>
        <p class="text-secondary mb-0">
            Live usage, RAG quality, voice adoption and operational metrics.
        </p>
    </div>

    <div class="text-secondary small">
        <i class="bi bi-clock-history me-1"></i>
        Live database metrics
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-secondary small text-uppercase">
                            Conversations
                        </div>
                        <div class="fs-2 fw-semibold">
                            <?= number_format($conversationsTotal) ?>
                        </div>
                    </div>
                    <i class="bi bi-chat-dots fs-2 text-secondary"></i>
                </div>
                <div class="small text-secondary mt-2">
                    <?= number_format($conversationsToday) ?>
                    started today
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-secondary small text-uppercase">
                            User Messages
                        </div>
                        <div class="fs-2 fw-semibold">
                            <?= number_format($userMessages) ?>
                        </div>
                    </div>
                    <i class="bi bi-person-lines-fill fs-2 text-secondary"></i>
                </div>
                <div class="small text-secondary mt-2">
                    <?= number_format($assistantMessages) ?>
                    assistant responses
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-secondary small text-uppercase">
                            Grounded Rate
                        </div>
                        <div class="fs-2 fw-semibold">
                            <?= e((string) $groundedRate) ?>%
                        </div>
                    </div>
                    <i class="bi bi-database-check fs-2 text-secondary"></i>
                </div>
                <div class="small text-secondary mt-2">
                    <?= number_format($groundedAnswers) ?>
                    grounded answers
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="text-secondary small text-uppercase">
                            Escalation Rate
                        </div>
                        <div class="fs-2 fw-semibold">
                            <?= e((string) $escalationRate) ?>%
                        </div>
                    </div>
                    <i class="bi bi-exclamation-triangle fs-2 text-secondary"></i>
                </div>
                <div class="small text-secondary mt-2">
                    <?= number_format($escalatedAnswers) ?>
                    escalated responses
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small text-uppercase mb-1">
                    Avg Confidence
                </div>
                <div class="fs-3 fw-semibold">
                    <?= number_format($avgConfidence, 4) ?>
                </div>
                <div class="small text-secondary">
                    Retrieval-based confidence
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small text-uppercase mb-1">
                    Avg Response Latency
                </div>
                <div class="fs-3 fw-semibold">
                    <?= number_format($avgLatency) ?> ms
                </div>
                <div class="small text-secondary">
                    Assistant responses
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small text-uppercase mb-1">
                    Positive Feedback
                </div>
                <div class="fs-3 fw-semibold">
                    <?= e((string) $positiveRate) ?>%
                </div>
                <div class="small text-secondary">
                    <?= number_format($positive) ?>
                    positive /
                    <?= number_format($feedbackTotal) ?>
                    total
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-secondary small text-uppercase mb-1">
                    Failed Jobs
                </div>
                <div class="fs-3 fw-semibold">
                    <?= number_format(
                        (int) ($jobs['failed'] ?? 0)
                    ) ?>
                </div>
                <div class="small text-secondary">
                    <?= number_format(
                        (int) ($jobs['pending'] ?? 0)
                    ) ?>
                    pending
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body">
                <strong>14-Day Activity</strong>
            </div>

            <div class="card-body">
                <?php foreach ($dailyActivity as $day): ?>
                    <?php
                    $messages =
                        $day['user_messages']
                        + $day['assistant_messages'];

                    $width = $maxDailyMessages > 0
                        ? max(
                            1,
                            round(
                                ($messages / $maxDailyMessages)
                                * 100
                            )
                        )
                        : 1;
                    ?>

                    <div class="row align-items-center g-2 mb-2">
                        <div class="col-3 col-lg-2 small text-secondary">
                            <?= e(
                                date(
                                    'M j',
                                    strtotime($day['date'])
                                )
                            ) ?>
                        </div>

                        <div class="col">
                            <div
                                class="progress"
                                style="height: 12px;"
                                title="<?= e((string) $messages) ?> messages"
                            >
                                <div
                                    class="progress-bar"
                                    role="progressbar"
                                    style="width: <?= (int) $width ?>%;"
                                ></div>
                            </div>
                        </div>

                        <div class="col-auto small text-secondary">
                            <?= number_format($messages) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body">
                <strong>Input Mode</strong>
            </div>

            <div class="card-body">
                <div class="d-flex justify-content-between mb-3">
                    <span>
                        <i class="bi bi-keyboard me-2"></i>
                        Browser text
                    </span>
                    <strong><?= number_format($textMessages) ?></strong>
                </div>

                <div class="d-flex justify-content-between mb-3">
                    <span>
                        <i class="bi bi-mic me-2"></i>
                        Browser voice
                    </span>
                    <strong><?= number_format($voiceMessages) ?></strong>
                </div>

                <div class="d-flex justify-content-between">
                    <span>
                        <i class="bi bi-three-dots me-2"></i>
                        Other / API
                    </span>
                    <strong><?= number_format($otherMessages) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body">
                <strong>Model Usage</strong>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th class="text-end">Responses</th>
                            <th class="text-end">Avg Latency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$models): ?>
                            <tr>
                                <td colspan="3" class="text-secondary">
                                    No model usage yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($models as $model): ?>
                                <tr>
                                    <td>
                                        <code>
                                            <?= e(
                                                (string) $model['model']
                                            ) ?>
                                        </code>
                                    </td>
                                    <td class="text-end">
                                        <?= number_format(
                                            (int) $model['total']
                                        ) ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (
                                            $model['avg_latency_ms'] !== null
                                        ): ?>
                                            <?= number_format(
                                                (int) $model['avg_latency_ms']
                                            ) ?>
                                            ms
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-body">
                <strong>Conversation Channels</strong>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Channel</th>
                            <th class="text-end">Conversations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$channels): ?>
                            <tr>
                                <td colspan="2" class="text-secondary">
                                    No conversations yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($channels as $channel): ?>
                                <tr>
                                    <td>
                                        <?= e(
                                            (string) $channel['channel']
                                        ) ?>
                                    </td>
                                    <td class="text-end">
                                        <?= number_format(
                                            (int) $channel['total']
                                        ) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-body d-flex justify-content-between">
        <strong>Recent Escalated Conversations</strong>

        <a
            href="/admin/conversations?status=escalated"
            class="small text-decoration-none"
        >
            View all
        </a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Knowledge Base</th>
                    <th>Channel</th>
                    <th>Last Activity</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$recentEscalations): ?>
                    <tr>
                        <td colspan="5" class="text-secondary">
                            No escalated conversations.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentEscalations as $conversation): ?>
                        <tr>
                            <td>
                                #<?= (int) $conversation['id'] ?>
                            </td>

                            <td>
                                <?= e(
                                    (string) (
                                        $conversation['knowledge_base_name']
                                        ?? '—'
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    (string) $conversation['channel']
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    (string) $conversation['last_message_at']
                                ) ?>
                            </td>

                            <td class="text-end">
                                <a
                                    href="/admin/conversations/<?= (int) $conversation['id'] ?>"
                                    class="btn btn-sm btn-outline-primary"
                                >
                                    Open
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
