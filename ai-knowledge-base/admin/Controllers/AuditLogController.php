<?php

declare(strict_types=1);

namespace Admin\Controllers;

use App\Core\Database;
use App\Core\View;

final class AuditLogController
{
    public function index(): void
    {
        $pdo = Database::connection();

        $action = trim(
            (string) ($_GET['action'] ?? '')
        );

        $entityType = trim(
            (string) ($_GET['entity_type'] ?? '')
        );

        $userId = trim(
            (string) ($_GET['user_id'] ?? '')
        );

        $q = trim(
            (string) ($_GET['q'] ?? '')
        );

        $dateFrom = trim(
            (string) ($_GET['date_from'] ?? '')
        );

        $dateTo = trim(
            (string) ($_GET['date_to'] ?? '')
        );

        $where = [];
        $params = [];

        if ($action !== '') {
            $where[] = 'a.action = :action';
            $params[':action'] = $action;
        }

        if ($entityType !== '') {
            $where[] =
                'a.entity_type = :entity_type';

            $params[':entity_type'] =
                $entityType;
        }

        if (
            $userId !== ''
            && ctype_digit($userId)
        ) {
            $where[] = 'a.user_id = :user_id';
            $params[':user_id'] =
                (int) $userId;
        }

        if ($q !== '') {
            $where[] = '(
                a.action LIKE :q
                OR a.entity_type LIKE :q
                OR a.entity_id LIKE :q
                OR a.ip LIKE :q
                OR u.name LIKE :q
                OR u.email LIKE :q
            )';

            $params[':q'] =
                '%' . $q . '%';
        }

        if (
            $dateFrom !== ''
            && preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $dateFrom
            )
        ) {
            $where[] =
                'a.created_at >= :date_from';

            $params[':date_from'] =
                $dateFrom . ' 00:00:00';
        }

        if (
            $dateTo !== ''
            && preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $dateTo
            )
        ) {
            $where[] =
                'a.created_at < DATE_ADD(
                    :date_to,
                    INTERVAL 1 DAY
                )';

            $params[':date_to'] =
                $dateTo . ' 00:00:00';
        }

        $sql = '
            SELECT
                a.id,
                a.user_id,
                a.action,
                a.entity_type,
                a.entity_id,
                a.meta_json,
                a.ip,
                a.created_at,
                u.name AS user_name,
                u.email AS user_email
            FROM audit_logs a
            LEFT JOIN users u
                ON u.id = a.user_id
        ';

        if ($where) {
            $sql .=
                ' WHERE '
                . implode(' AND ', $where);
        }

        $sql .= '
            ORDER BY a.id DESC
            LIMIT 500
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $logs = $stmt->fetchAll();

        $actions = $pdo->query(
            'SELECT DISTINCT action
             FROM audit_logs
             ORDER BY action'
        )->fetchAll();

        $entityTypes = $pdo->query(
            'SELECT DISTINCT entity_type
             FROM audit_logs
             WHERE entity_type IS NOT NULL
             ORDER BY entity_type'
        )->fetchAll();

        $users = $pdo->query(
            'SELECT
                u.id,
                u.name,
                u.email
             FROM users u
             WHERE EXISTS (
                SELECT 1
                FROM audit_logs a
                WHERE a.user_id = u.id
             )
             ORDER BY u.name, u.email'
        )->fetchAll();

        View::render('audit_logs/index', [
            'title' => 'Audit Logs',
            'logs' => $logs,
            'actions' =>
                array_column(
                    $actions,
                    'action'
                ),
            'entityTypes' =>
                array_column(
                    $entityTypes,
                    'entity_type'
                ),
            'users' => $users,
            'filters' => [
                'action' => $action,
                'entity_type' => $entityType,
                'user_id' => $userId,
                'q' => $q,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ], 'layouts/base');
    }
}
