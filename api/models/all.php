<?php

require_once __DIR__ . '/../bootstrap.php';

requireMethod('GET');

$userId = requireAuth();

try {
    // Confirm that the authenticated account is still active.
    $userStmt = $pdo->prepare("
        SELECT id
        FROM users
        WHERE id = :user_id
          AND status = 'active'
        LIMIT 1
    ");

    $userStmt->execute([
        'user_id' => $userId
    ]);

    if (!$userStmt->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        respond([
            'success' => false,
            'message' => 'Your account is unavailable. Please sign in again.'
        ], 401);
    }

    /*
     * Retrieve every model belonging to this user.
     *
     * The latest version is selected independently for each model.
     * Models without versions are also included.
     *
     * The file information is optional because a model version
     * may not yet have an uploaded file.
     */
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.name,
            m.slug,
            m.status,
            m.created_at,
            m.updated_at,

            COALESCE(latest_version.version, 1) AS version,

            latest_version.id AS model_version_id,
            latest_version.status AS version_status,

            latest_file.id AS file_id,
            latest_file.original_name,
            latest_file.file_size,
            latest_file.upload_status

        FROM models m

        LEFT JOIN (
            SELECT
                mv1.id,
                mv1.model_id,
                mv1.version,
                mv1.status

            FROM model_versions mv1

            INNER JOIN (
                SELECT
                    model_id,
                    MAX(version) AS max_version
                FROM model_versions
                GROUP BY model_id
            ) latest
                ON latest.model_id = mv1.model_id
               AND latest.max_version = mv1.version
        ) latest_version
            ON latest_version.model_id = m.id

        LEFT JOIN model_files latest_file
            ON latest_file.model_version_id = latest_version.id

        WHERE m.user_id = :user_id

        ORDER BY
            m.updated_at DESC,
            m.id DESC,
            latest_file.id DESC
    ");

    $stmt->execute([
        'user_id' => $userId
    ]);

    $models = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize numeric fields for consistent JSON responses.
    foreach ($models as &$model) {
        $model['id'] = (int) $model['id'];
        $model['version'] = (int) $model['version'];

        $model['model_version_id'] =
            $model['model_version_id'] !== null
                ? (int) $model['model_version_id']
                : null;

        $model['file_id'] =
            $model['file_id'] !== null
                ? (int) $model['file_id']
                : null;

        $model['file_size'] =
            $model['file_size'] !== null
                ? (int) $model['file_size']
                : null;
    }

    unset($model);

    respond([
        'success' => true,
        'message' => 'Models retrieved successfully.',
        'data' => [
            'models' => $models,
            'total' => count($models)
        ]
    ]);

} catch (PDOException $e) {
    error_log(
        'All models retrieval error: ' . $e->getMessage()
    );

    respond([
        'success' => false,
        'message' => 'Unable to retrieve your models at this time.'
    ], 500);

} catch (Throwable $e) {
    error_log(
        'All models unexpected error: ' . $e->getMessage()
    );

    respond([
        'success' => false,
        'message' => 'An unexpected error occurred while retrieving your models.'
    ], 500);
}
