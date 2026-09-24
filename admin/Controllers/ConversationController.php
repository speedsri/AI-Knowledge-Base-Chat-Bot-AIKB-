<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Core\Database;
use App\Core\View;

final class ConversationController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $status = trim((string) ($_GET['status'] ?? ''));
        $channel = trim((string) ($_GET['channel'] ?? ''));
        $q = trim((string) ($_GET['q'] ?? ''));

        $where = [];
        $params = [];

        if (in_array($status, ['open', 'closed', 'escalated'], true)) {
            $where[] = 'c.status = :status';
            $params[':status'] = $status;
        }

        if ($channel !== '') {
            $where[] = 'c.channel = :channel';
            $params[':channel'] = $channel;
        }

        if ($q !== '') {
            $where[] = '(c.contact_name LIKE :q
                      OR c.contact_identifier LIKE :q
                      OR CAST(c.id AS CHAR) = :qid)';
            $params[':q'] = '%' . $q . '%';
            $params[':qid'] = $q;
        }

        $sql = '
            SELECT
                c.id,
                c.channel,
                c.status,
                c.contact_name,
                c.contact_identifier,
                c.started_at,
                c.last_message_at,
                c.closed_at,
                kb.name AS knowledge_base_name,
                u.name AS user_name,
                COUNT(cm.id) AS message_count
            FROM conversations c
            LEFT JOIN knowledge_bases kb ON kb.id = c.knowledge_base_id
            LEFT JOIN users u ON u.id = c.user_id
            LEFT JOIN conversation_messages cm ON cm.conversation_id = c.id
        ';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= '
            GROUP BY c.id
            ORDER BY c.last_message_at DESC
            LIMIT 200
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $channels = $pdo->query(
            'SELECT DISTINCT channel
             FROM conversations
             ORDER BY channel'
        )->fetchAll();

        View::render('conversations/index', [
            'title' => 'Conversations',
            'conversations' => $stmt->fetchAll(),
            'channels' => array_column($channels, 'channel'),
            'filters' => [
                'status' => $status,
                'channel' => $channel,
                'q' => $q,
            ],
        ], 'layouts/base');
    }

    public function show(int $id): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT
                c.*,
                kb.name AS knowledge_base_name,
                u.name AS user_name,
                u.email AS user_email
             FROM conversations c
             LEFT JOIN knowledge_bases kb ON kb.id = c.knowledge_base_id
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $conversation = $stmt->fetch();

        if (!$conversation) {
            http_response_code(404);
            echo 'Conversation not found.';
            return;
        }

        $messages = $pdo->prepare(
            'SELECT *
             FROM conversation_messages
             WHERE conversation_id = :id
             ORDER BY created_at ASC, id ASC'
        );
        $messages->execute([':id' => $id]);

        $feedback = $pdo->prepare(
            'SELECT
                f.*,
                u.name AS submitted_by_name
             FROM conversation_feedback f
             LEFT JOIN users u ON u.id = f.submitted_by
             WHERE f.conversation_id = :id
             ORDER BY f.created_at DESC, f.id DESC'
        );
        $feedback->execute([':id' => $id]);

        View::render('conversations/show', [
            'title' => 'Conversation #' . $id,
            'conversation' => $conversation,
            'messages' => $messages->fetchAll(),
            'feedback' => $feedback->fetchAll(),
        ], 'layouts/base');
    }
}
