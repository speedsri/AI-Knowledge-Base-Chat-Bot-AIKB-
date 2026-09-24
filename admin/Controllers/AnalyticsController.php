<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Core\Database;
use App\Core\View;

final class AnalyticsController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $summary = $pdo->query(
            'SELECT
                (SELECT COUNT(*) FROM conversations) AS conversations_total,

                (SELECT COUNT(*)
                 FROM conversations
                 WHERE DATE(started_at) = CURRENT_DATE) AS conversations_today,

                (SELECT COUNT(*)
                 FROM conversations
                 WHERE status = "escalated") AS conversations_escalated,

                (SELECT COUNT(*)
                 FROM conversation_messages
                 WHERE role = "user") AS user_messages,

                (SELECT COUNT(*)
                 FROM conversation_messages
                 WHERE role = "assistant") AS assistant_messages,

                (SELECT COUNT(*)
                 FROM conversation_messages
                 WHERE role = "assistant"
                   AND JSON_UNQUOTE(
                        JSON_EXTRACT(metadata_json, "$.grounded")
                   ) = "true") AS grounded_answers,

                (SELECT COUNT(*)
                 FROM conversation_messages
                 WHERE role = "assistant"
                   AND JSON_UNQUOTE(
                        JSON_EXTRACT(metadata_json, "$.escalated")
                   ) = "true") AS escalated_answers,

                (SELECT ROUND(AVG(latency_ms))
                 FROM conversation_messages
                 WHERE role = "assistant"
                   AND latency_ms IS NOT NULL) AS avg_latency_ms,

                (SELECT ROUND(
                    AVG(
                        CAST(
                            JSON_UNQUOTE(
                                JSON_EXTRACT(
                                    metadata_json,
                                    "$.confidence"
                                )
                            ) AS DECIMAL(10,4)
                        )
                    ),
                    4
                 )
                 FROM conversation_messages
                 WHERE role = "assistant"
                   AND JSON_EXTRACT(
                        metadata_json,
                        "$.confidence"
                   ) IS NOT NULL) AS avg_confidence'
        )->fetch() ?: [];

        $inputModes = $pdo->query(
            'SELECT
                CASE
                    WHEN JSON_UNQUOTE(
                        JSON_EXTRACT(metadata_json, "$.via")
                    ) = "public_browser_voice"
                        THEN "voice"

                    WHEN JSON_UNQUOTE(
                        JSON_EXTRACT(metadata_json, "$.via")
                    ) = "public_browser_text"
                        THEN "text"

                    ELSE "other"
                END AS input_mode,
                COUNT(*) AS total
             FROM conversation_messages
             WHERE role = "user"
             GROUP BY input_mode
             ORDER BY total DESC'
        )->fetchAll();

        $channels = $pdo->query(
            'SELECT
                channel,
                COUNT(*) AS total
             FROM conversations
             GROUP BY channel
             ORDER BY total DESC'
        )->fetchAll();

        $models = $pdo->query(
            'SELECT
                COALESCE(NULLIF(model, ""), "none") AS model,
                COUNT(*) AS total,
                ROUND(AVG(latency_ms)) AS avg_latency_ms
             FROM conversation_messages
             WHERE role = "assistant"
             GROUP BY COALESCE(NULLIF(model, ""), "none")
             ORDER BY total DESC'
        )->fetchAll();

        $feedback = $pdo->query(
            'SELECT
                SUM(rating = "positive") AS positive,
                SUM(rating = "negative") AS negative,
                COUNT(*) AS total
             FROM conversation_feedback'
        )->fetch() ?: [];

        $jobs = $pdo->query(
            'SELECT
                SUM(status = "pending") AS pending,
                SUM(status = "running") AS running,
                SUM(status = "done") AS done,
                SUM(status = "failed") AS failed,
                COUNT(*) AS total
             FROM background_jobs'
        )->fetch() ?: [];

        $dailyRows = $pdo->query(
            'SELECT
                DATE(c.started_at) AS activity_date,
                COUNT(DISTINCT c.id) AS conversations,
                COUNT(
                    DISTINCT CASE
                        WHEN m.role = "user" THEN m.id
                        ELSE NULL
                    END
                ) AS user_messages,
                COUNT(
                    DISTINCT CASE
                        WHEN m.role = "assistant" THEN m.id
                        ELSE NULL
                    END
                ) AS assistant_messages
             FROM conversations c
             LEFT JOIN conversation_messages m
                ON m.conversation_id = c.id
             WHERE c.started_at >= CURRENT_DATE - INTERVAL 13 DAY
             GROUP BY DATE(c.started_at)
             ORDER BY activity_date ASC'
        )->fetchAll();

        $dailyByDate = [];

        foreach ($dailyRows as $row) {
            $dailyByDate[(string) $row['activity_date']] = $row;
        }

        $dailyActivity = [];

        for ($i = 13; $i >= 0; $i--) {
            $date = date(
                'Y-m-d',
                strtotime("-{$i} days")
            );

            $row = $dailyByDate[$date] ?? [];

            $dailyActivity[] = [
                'date' => $date,
                'conversations' =>
                    (int) ($row['conversations'] ?? 0),
                'user_messages' =>
                    (int) ($row['user_messages'] ?? 0),
                'assistant_messages' =>
                    (int) ($row['assistant_messages'] ?? 0),
            ];
        }

        $maxDailyMessages = 1;

        foreach ($dailyActivity as $day) {
            $totalMessages =
                $day['user_messages']
                + $day['assistant_messages'];

            $maxDailyMessages = max(
                $maxDailyMessages,
                $totalMessages
            );
        }

        $recentEscalations = $pdo->query(
            'SELECT
                c.id,
                c.channel,
                c.status,
                c.started_at,
                c.last_message_at,
                kb.name AS knowledge_base_name
             FROM conversations c
             LEFT JOIN knowledge_bases kb
                ON kb.id = c.knowledge_base_id
             WHERE c.status = "escalated"
             ORDER BY c.last_message_at DESC
             LIMIT 10'
        )->fetchAll();

        View::render('analytics/index', [
            'title' => 'Analytics',
            'summary' => $summary,
            'inputModes' => $inputModes,
            'channels' => $channels,
            'models' => $models,
            'feedback' => $feedback,
            'jobs' => $jobs,
            'dailyActivity' => $dailyActivity,
            'maxDailyMessages' => $maxDailyMessages,
            'recentEscalations' => $recentEscalations,
        ], 'layouts/base');
    }
}
