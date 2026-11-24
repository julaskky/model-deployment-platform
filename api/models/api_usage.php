<?php
declare(strict_types=1);

require_once __DIR__ . '/api_key_service.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    runtime_service_json(false, [], 'GET method required.', 405);
}

try {
    runtime_service_auth();

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId < 1) {
        runtime_service_json(
            false,
            [],
            'Authentication required.',
            401
        );
    }

    $pdo = runtime_service_database();

    $runtimeId = null;

    if (isset($_GET['runtime_id']) && $_GET['runtime_id'] !== '') {
        $runtimeId = filter_var(
            $_GET['runtime_id'],
            FILTER_VALIDATE_INT
        );

        if (!$runtimeId || $runtimeId < 1) {
            runtime_service_json(
                false,
                [],
                'Invalid runtime ID.',
                400
            );
        }

        // Verify ownership before filtering by runtime.
        $runtimeCheck = $pdo->prepare("
            SELECT id
            FROM model_runtimes
            WHERE id = :runtime_id
              AND EXISTS (
                  SELECT 1
                  FROM model_versions mv
                  INNER JOIN models m ON m.id = mv.model_id
                  WHERE mv.id = model_runtimes.model_version_id
                    AND m.user_id = :user_id
              )
            LIMIT 1
        ");

        $runtimeCheck->execute([
            ':runtime_id' => $runtimeId,
            ':user_id' => $userId,
        ]);

        if (!$runtimeCheck->fetchColumn()) {
            runtime_service_json(
                false,
                [],
                'Runtime not found.',
                404
            );
        }
    }

    $page = max(
        1,
        (int) ($_GET['page'] ?? 1)
    );

    $limit = (int) ($_GET['limit'] ?? 20);
    $limit = max(1, min(100, $limit));

    $offset = ($page - 1) * $limit;

    $where = 'WHERE l.user_id = :user_id';

    $params = [
        ':user_id' => $userId,
    ];

    if ($runtimeId !== null) {
        $where .= ' AND l.runtime_id = :runtime_id';
        $params[':runtime_id'] = $runtimeId;
    }

    /*
     * Usage summary.
     */
    $summaryQuery = $pdo->prepare("
        SELECT
            COUNT(*) AS total_requests,

            COALESCE(
                SUM(l.success = 1),
                0
            ) AS successful_requests,

            COALESCE(
                SUM(l.success = 0),
                0
            ) AS failed_requests,

            COALESCE(
                AVG(l.response_time_ms),
                0
            ) AS average_response_time_ms,

            COALESCE(
                SUM(l.created_at >= CURDATE()),
                0
            ) AS requests_today,

            COALESCE(
                SUM(l.success = 1 AND l.created_at >= CURDATE()),
                0
            ) AS successful_today

        FROM api_request_logs l
        $where
    ");

    $summaryQuery->execute($params);
    $summary = $summaryQuery->fetch(PDO::FETCH_ASSOC);

    /*
     * HTTP status distribution.
     */
    $statusQuery = $pdo->prepare("
        SELECT
            l.http_status,
            COUNT(*) AS request_count
        FROM api_request_logs l
        $where
        GROUP BY l.http_status
        ORDER BY l.http_status ASC
    ");

    $statusQuery->execute($params);
    $statusDistribution = $statusQuery->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Total history records.
     */
    $countQuery = $pdo->prepare("
        SELECT COUNT(*)
        FROM api_request_logs l
        $where
    ");

    $countQuery->execute($params);
    $totalRecords = (int) $countQuery->fetchColumn();

    /*
     * Request history.
     */
    $historyQuery = $pdo->prepare("
        SELECT
            l.id,
            l.api_key_id,
            ak.name AS api_key_name,
            l.runtime_id,
            l.model_id,
            l.model_version_id,
            l.http_method,
            l.model_slug,
            l.http_status,
            l.success,
            l.response_time_ms,
            l.request_size_bytes,
            l.error_code,
            l.error_message,
            l.created_at

        FROM api_request_logs l

        LEFT JOIN api_keys ak
            ON ak.id = l.api_key_id

        $where

        ORDER BY l.created_at DESC, l.id DESC

        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $historyQuery->bindValue(
            $key,
            $value,
            PDO::PARAM_INT
        );
    }

    $historyQuery->bindValue(
        ':limit',
        $limit,
        PDO::PARAM_INT
    );

    $historyQuery->bindValue(
        ':offset',
        $offset,
        PDO::PARAM_INT
    );

    $historyQuery->execute();

    $history = $historyQuery->fetchAll(PDO::FETCH_ASSOC);

    runtime_service_json(
        true,
        [
            'summary' => [
                'total_requests' => (int) $summary['total_requests'],
                'successful_requests' => (int) $summary['successful_requests'],
                'failed_requests' => (int) $summary['failed_requests'],
                'average_response_time_ms' => round(
                    (float) $summary['average_response_time_ms'],
                    2
                ),
                'requests_today' => (int) $summary['requests_today'],
                'successful_today' => (int) $summary['successful_today'],
            ],
            'status_distribution' => $statusDistribution,
            'history' => $history,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => (int) ceil($totalRecords / $limit),
            ],
        ],
        'API usage information retrieved successfully.'
    );

} catch (Throwable $e) {
    error_log(
        'API usage retrieval error: ' . $e->getMessage()
    );

    runtime_service_json(
        false,
        [],
        'Unable to retrieve API usage information.',
        500
    );
}