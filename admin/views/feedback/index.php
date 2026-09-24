<?php use function App\Core\e; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Feedback</h1>
        <p class="text-secondary mb-0">
            User feedback collected from assistant conversations.
        </p>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="/admin/feedback" class="row g-2">
            <div class="col-md-4">
                <select name="rating" class="form-select">
                    <option value="">All feedback</option>
                    <option value="positive" <?= $rating === 'positive' ? 'selected' : '' ?>>
                        Positive
                    </option>
                    <option value="negative" <?= $rating === 'negative' ? 'selected' : '' ?>>
                        Negative
                    </option>
                </select>
            </div>

            <div class="col-md-2">
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
                        <th>Rating</th>
                        <th>Conversation</th>
                        <th>Contact</th>
                        <th>Channel</th>
                        <th>Comment</th>
                        <th>Date</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($feedback)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">
                                No feedback recorded yet.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($feedback as $f): ?>
                        <tr>
                            <td>
                                <?php if ($f['rating'] === 'positive'): ?>
                                    <span class="badge text-bg-success">
                                        <i class="bi bi-hand-thumbs-up"></i> Positive
                                    </span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">
                                        <i class="bi bi-hand-thumbs-down"></i> Negative
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <a href="/admin/conversations/<?= e((string) $f['conversation_id']) ?>">
                                    #<?= e((string) $f['conversation_id']) ?>
                                </a>
                            </td>

                            <td>
                                <?= e($f['contact_name'] ?: $f['contact_identifier'] ?: 'Anonymous') ?>
                            </td>

                            <td><?= e($f['channel']) ?></td>

                            <td>
                                <?= e($f['comment'] ?: '—') ?>
                            </td>

                            <td class="small text-secondary">
                                <?= e($f['created_at']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
