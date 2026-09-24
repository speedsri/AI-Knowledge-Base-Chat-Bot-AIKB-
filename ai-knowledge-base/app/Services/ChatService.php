<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use PDO;
use PDOException;

final class ChatService
{
    /**
     * @return array{status:int,body:array}
     */
    public function handle(array $body, array $context = []): array
    {
        $knowledgeBaseId = filter_var(
            $body['knowledge_base_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $message = trim((string) ($body['message'] ?? ''));
        $channel = strtolower(
            trim((string) ($body['channel'] ?? 'web'))
        );

        $conversationRef = trim(
            (string) ($body['conversation_ref'] ?? '')
        );

        $contactName = isset($body['contact_name'])
            ? trim((string) $body['contact_name'])
            : null;

        $contactIdentifier = isset($body['contact_identifier'])
            ? trim((string) $body['contact_identifier'])
            : null;

        if ($knowledgeBaseId === false || $knowledgeBaseId === null) {
            return $this->result(422, [
                'ok' => false,
                'error' => 'invalid_knowledge_base_id',
            ]);
        }

        if ($message === '' || mb_strlen($message) > 4000) {
            return $this->result(422, [
                'ok' => false,
                'error' => 'invalid_message',
            ]);
        }

        if (
            $channel === ''
            || strlen($channel) > 30
            || !preg_match('/^[a-z0-9_-]+$/', $channel)
        ) {
            return $this->result(422, [
                'ok' => false,
                'error' => 'invalid_channel',
            ]);
        }

        if ($conversationRef === '') {
            $conversationRef = 'aikb-' . bin2hex(random_bytes(16));
        }

        if (
            strlen($conversationRef) > 190
            || preg_match('/[\x00-\x1F\x7F]/', $conversationRef)
        ) {
            return $this->result(422, [
                'ok' => false,
                'error' => 'invalid_conversation_ref',
            ]);
        }

        if ($contactName === '') {
            $contactName = null;
        }

        if ($contactIdentifier === '') {
            $contactIdentifier = null;
        }

        if (
            ($contactName !== null && mb_strlen($contactName) > 190)
            || (
                $contactIdentifier !== null
                && mb_strlen($contactIdentifier) > 190
            )
        ) {
            return $this->result(422, [
                'ok' => false,
                'error' => 'invalid_contact',
            ]);
        }

        $createdVia = trim(
            (string) ($context['created_via'] ?? 'chat_service')
        );

        $messageVia = trim(
            (string) ($context['message_via'] ?? $createdVia)
        );

        $userId = isset($context['user_id'])
            && is_numeric($context['user_id'])
            ? (int) $context['user_id']
            : null;

        $pdo = Database::connection();

        $kbStmt = $pdo->prepare(
            'SELECT id
             FROM knowledge_bases
             WHERE id = :id
               AND is_active = 1
             LIMIT 1'
        );

        $kbStmt->execute([
            ':id' => $knowledgeBaseId,
        ]);

        if (!$kbStmt->fetch()) {
            return $this->result(404, [
                'ok' => false,
                'error' => 'knowledge_base_not_found',
            ]);
        }

        $requestId = $this->uuidV4();

        try {
            $pdo->beginTransaction();

            $conversation = $this->findConversation(
                $pdo,
                $channel,
                $conversationRef
            );

            if (!$conversation) {
                try {
                    $create = $pdo->prepare(
                        'INSERT INTO conversations (
                            knowledge_base_id,
                            user_id,
                            channel,
                            external_conversation_id,
                            status,
                            contact_name,
                            contact_identifier,
                            metadata_json,
                            started_at,
                            last_message_at
                         ) VALUES (
                            :knowledge_base_id,
                            :user_id,
                            :channel,
                            :conversation_ref,
                            "open",
                            :contact_name,
                            :contact_identifier,
                            :metadata_json,
                            NOW(),
                            NOW()
                         )'
                    );

                    $create->execute([
                        ':knowledge_base_id' => $knowledgeBaseId,
                        ':user_id' => $userId,
                        ':channel' => $channel,
                        ':conversation_ref' => $conversationRef,
                        ':contact_name' => $contactName,
                        ':contact_identifier' => $contactIdentifier,
                        ':metadata_json' => json_encode(
                            [
                                'created_via' => $createdVia,
                            ],
                            JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                        ),
                    ]);

                    $conversationId = (int) $pdo->lastInsertId();
                } catch (PDOException $e) {
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }

                    $conversation = $this->findConversation(
                        $pdo,
                        $channel,
                        $conversationRef
                    );

                    if (!$conversation) {
                        throw $e;
                    }

                    $conversationId = (int) $conversation['id'];
                }
            } else {
                $conversationId = (int) $conversation['id'];
            }

            if ($conversation ?? null) {
                if (
                    (int) $conversation['knowledge_base_id']
                    !== (int) $knowledgeBaseId
                ) {
                    $pdo->rollBack();

                    return $this->result(409, [
                        'ok' => false,
                        'error' =>
                            'conversation_knowledge_base_mismatch',
                    ]);
                }
            }

            $update = $pdo->prepare(
                'UPDATE conversations
                 SET contact_name =
                        COALESCE(:contact_name, contact_name),
                     contact_identifier =
                        COALESCE(
                            :contact_identifier,
                            contact_identifier
                        ),
                     last_message_at = NOW()
                 WHERE id = :id'
            );

            $update->execute([
                ':contact_name' => $contactName,
                ':contact_identifier' => $contactIdentifier,
                ':id' => $conversationId,
            ]);

            $insertUser = $pdo->prepare(
                'INSERT INTO conversation_messages (
                    conversation_id,
                    role,
                    content,
                    request_id,
                    metadata_json
                 ) VALUES (
                    :conversation_id,
                    "user",
                    :content,
                    :request_id,
                    :metadata_json
                 )'
            );

            $insertUser->execute([
                ':conversation_id' => $conversationId,
                ':content' => $message,
                ':request_id' => $requestId,
                ':metadata_json' => json_encode(
                    [
                        'via' => $messageVia,
                    ],
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                ),
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error('chat_service.persist_user_failed', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);

            return $this->result(500, [
                'ok' => false,
                'error' => 'conversation_persistence_failed',
            ]);
        }

        $history = $this->loadRecentHistory(
            $pdo,
            $conversationId,
            $requestId
        );

        $rag = (new RagApiClient())->chat([
            'knowledge_base_id' => (int) $knowledgeBaseId,
            'conversation_ref' => $conversationRef,
            'channel' => $channel,
            'message' => $message,
            'history' => $history,
        ]);

        if (($rag['ok'] ?? false) !== true) {
            Logger::info('chat_service.rag_failed', [
                'request_id' => $requestId,
                'conversation_id' => $conversationId,
                'rag_error' => $rag['error'] ?? 'unknown',
            ]);

            return $this->result(503, [
                'ok' => false,
                'error' => 'assistant_temporarily_unavailable',
                'conversation_id' => $conversationId,
                'conversation_ref' => $conversationRef,
                'request_id' => $requestId,
            ]);
        }

        $answer = (string) ($rag['answer'] ?? '');
        $model = (string) ($rag['model'] ?? '');

        $latencyMs = isset($rag['latency_ms'])
            ? (int) $rag['latency_ms']
            : null;

        $grounded = (bool) ($rag['grounded'] ?? false);

        $confidence = isset($rag['confidence'])
            ? (float) $rag['confidence']
            : 0.0;

        $escalated = (bool) ($rag['escalated'] ?? false);

        $sources = is_array($rag['sources'] ?? null)
            ? $rag['sources']
            : [];

        try {
            $pdo->beginTransaction();

            $insertAssistant = $pdo->prepare(
                'INSERT INTO conversation_messages (
                    conversation_id,
                    role,
                    content,
                    request_id,
                    provider,
                    model,
                    latency_ms,
                    retrieval_json,
                    metadata_json
                 ) VALUES (
                    :conversation_id,
                    "assistant",
                    :content,
                    :request_id,
                    :provider,
                    :model,
                    :latency_ms,
                    :retrieval_json,
                    :metadata_json
                 )'
            );

            $insertAssistant->execute([
                ':conversation_id' => $conversationId,
                ':content' => $answer,
                ':request_id' => $requestId,
                ':provider' =>
                    ($model !== '' && $model !== 'none')
                        ? 'gemini'
                        : null,
                ':model' => $model !== '' ? $model : null,
                ':latency_ms' => $latencyMs,
                ':retrieval_json' => json_encode(
                    $sources,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                ),
                ':metadata_json' => json_encode(
                    [
                        'grounded' => $grounded,
                        'confidence' => $confidence,
                        'escalated' => $escalated,
                    ],
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                ),
            ]);

            $assistantMessageId = (int) $pdo->lastInsertId();

            $statusSql = $escalated
                ? '"escalated"'
                : 'status';

            $updateConversation = $pdo->prepare(
                "UPDATE conversations
                 SET status = {$statusSql},
                     last_message_at = NOW()
                 WHERE id = :id"
            );

            $updateConversation->execute([
                ':id' => $conversationId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error(
                'chat_service.persist_assistant_failed',
                [
                    'request_id' => $requestId,
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ]
            );

            return $this->result(500, [
                'ok' => false,
                'error' =>
                    'assistant_response_persistence_failed',
                'conversation_id' => $conversationId,
                'conversation_ref' => $conversationRef,
                'request_id' => $requestId,
            ]);
        }

        Logger::info('chat_service.completed', [
            'request_id' => $requestId,
            'conversation_id' => $conversationId,
            'knowledge_base_id' => $knowledgeBaseId,
            'channel' => $channel,
            'model' => $model,
            'escalated' => $escalated,
        ]);

        return $this->result(200, [
            'ok' => true,
            'request_id' => $requestId,
            'conversation_id' => $conversationId,
            'conversation_ref' => $conversationRef,
            'assistant_message_id' => $assistantMessageId,
            'answer' => $answer,
            'grounded' => $grounded,
            'confidence' => $confidence,
            'escalated' => $escalated,
            'model' => $model,
            'sources' => $sources,
            'latency_ms' => $latencyMs,
        ]);
    }

    private function loadRecentHistory(
        PDO $pdo,
        int $conversationId,
        string $currentRequestId
    ): array {
        $stmt = $pdo->prepare(
            'SELECT role, content
             FROM conversation_messages
             WHERE conversation_id = :conversation_id
               AND role IN ("user", "assistant")
               AND (
                    request_id IS NULL
                    OR request_id <> :request_id
                    OR role <> "user"
               )
             ORDER BY id DESC
             LIMIT 6'
        );

        $stmt->execute([
            ':conversation_id' => $conversationId,
            ':request_id' => $currentRequestId,
        ]);

        $rows = $stmt->fetchAll();

        $history = [];
        $usedChars = 0;
        $maxChars = 6000;

        foreach ($rows as $row) {
            $role = (string) ($row['role'] ?? '');
            $content = trim(
                (string) ($row['content'] ?? '')
            );

            if (
                !in_array(
                    $role,
                    ['user', 'assistant'],
                    true
                )
                || $content === ''
            ) {
                continue;
            }

            $remaining =
                $maxChars - $usedChars;

            if ($remaining <= 0) {
                break;
            }

            if (mb_strlen($content) > $remaining) {
                $content = mb_substr(
                    $content,
                    0,
                    $remaining
                );
            }

            array_unshift(
                $history,
                [
                    'role' => $role,
                    'content' => $content,
                ]
            );

            $usedChars += mb_strlen($content);
        }

        return $history;
    }

    private function findConversation(
        PDO $pdo,
        string $channel,
        string $conversationRef
    ): array|false {
        $stmt = $pdo->prepare(
            'SELECT id, knowledge_base_id, status
             FROM conversations
             WHERE channel = :channel
               AND external_conversation_id = :conversation_ref
             LIMIT 1'
        );

        $stmt->execute([
            ':channel' => $channel,
            ':conversation_ref' => $conversationRef,
        ]);

        return $stmt->fetch();
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);

        $data[6] = chr(
            (ord($data[6]) & 0x0f) | 0x40
        );

        $data[8] = chr(
            (ord($data[8]) & 0x3f) | 0x80
        );

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($data), 4)
        );
    }

    private function result(
        int $status,
        array $body
    ): array {
        return [
            'status' => $status,
            'body' => $body,
        ];
    }
}
