<?php
declare(strict_types=1);

require_once __DIR__ . '/api_key_service.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    runtime_service_json(false, [], 'GET method required.', 405);
}

try {
    runtime_service_auth();

    $runtimeId = filter_var(
        $_GET['runtime_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$runtimeId || $runtimeId < 1) {
        runtime_service_json(false, [], 'Invalid runtime ID.', 400);
    }

    $pdo = runtime_service_database();
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId < 1) {
        runtime_service_json(false, [], 'Authentication required.', 401);
    }

    // Confirm that this runtime belongs to the authenticated user.
    runtime_service_get_runtime($pdo, $runtimeId);

    $query = $pdo->prepare("
        SELECT
            id,
            runtime_id,
            name,
            key_prefix,
            status,
            last_used_at,
            expires_at,
            created_at,
            revoked_at
        FROM api_keys
        WHERE runtime_id = :runtime_id
          AND user_id = :user_id
        ORDER BY created_at DESC
    ");

    $query->execute([
        ':runtime_id' => $runtimeId,
        ':user_id' => $userId
    ]);

    $keys = $query->fetchAll(PDO::FETCH_ASSOC);

    runtime_service_json(
        true,
        ['api_keys' => $keys],
        'API keys retrieved successfully.'
    );

} catch (Throwable $e) {
    error_log('API key listing error: ' . $e->getMessage());

    runtime_service_json(
        false,
        [],
        $e->getMessage(),
        500
    );
}
