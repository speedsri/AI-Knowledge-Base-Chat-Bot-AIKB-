<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Core\Database;
use App\Core\View;

final class FeedbackController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $rating = trim((string) ($_GET['rating'] ?? ''));

        $sql = '
            SELECT
                f.id,
                f.conversation_id,
                f.message_id,
                f.rating,
                f.comment,
                f.created_at,
                c.channel,
                c.contact_name,
                c.contact_identifier,
                u.name AS submitted_by_name
            FROM conversation_feedback f
            INNER JOIN conversations c ON c.id = f.conversation_id
            LEFT JOIN users u ON u.id = f.submitted_by
        ';

        $params = [];

        if (in_array($rating, ['positive', 'negative'], true)) {
            $sql .= ' WHERE f.rating = :rating';
            $params[':rating'] = $rating;
        }

        $sql .= ' ORDER BY f.created_at DESC, f.id DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        View::render('feedback/index', [
            'title' => 'Feedback',
            'feedback' => $stmt->fetchAll(),
            'rating' => $rating,
        ], 'layouts/base');
    }
}
