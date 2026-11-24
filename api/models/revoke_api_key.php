<?php
declare(strict_types=1);

require_once __DIR__ . '/api_key_service.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    runtime_service_json(false, [], 'POST method required.', 405);
}

try {
    runtime_service_auth();
    runtime_service_csrf();

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        runtime_service_json(false, [], 'Invalid request body.', 400);
    }

    $keyId = filter_var(
        $input['api_key_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$keyId || $keyId < 1) {
        runtime_service_json(false, [], 'Invalid API key ID.', 400);
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId < 1) {
        runtime_service_json(false, [], 'Authentication required.', 401);
    }

    $pdo = runtime_service_database();

    $query = $pdo->prepare("
        UPDATE api_keys
        SET
            status = 'revoked',
            revoked_at = COALESCE(revoked_at, NOW())
        WHERE id = :key_id
          AND user_id = :user_id
          AND status = 'active'
    ");

    $query->execute([
        ':key_id' => $keyId,
        ':user_id' => $userId
    ]);

    if ($query->rowCount() < 1) {
        runtime_service_json(
            false,
            [],
            'Active API key not found or already revoked.',
            404
        );
    }

    runtime_service_json(
        true,
        ['api_key_id' => (int) $keyId, 'status' => 'revoked'],
        'API key revoked successfully.'
    );

} catch (Throwable $e) {
    error_log('API key revocation error: ' . $e->getMessage());

    runtime_service_json(
        false,
        [],
        $e->getMessage(),
        500
    );
}
