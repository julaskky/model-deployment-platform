<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_service.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    runtime_service_json(
        false,
        [],
        'POST method required.',
        405
    );
}

try {
    // --------------------------------------------------
    // AUTHENTICATION AND CSRF
    // --------------------------------------------------

    runtime_service_auth();
    runtime_service_csrf();

    // --------------------------------------------------
    // REQUEST VALIDATION
    // --------------------------------------------------

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        runtime_service_json(
            false,
            [],
            'Invalid request body.',
            400
        );
    }

    $runtimeId = filter_var(
        $input['runtime_id'] ?? null,
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

    // --------------------------------------------------
    // DATABASE AND OWNERSHIP VALIDATION
    // --------------------------------------------------

    $pdo = runtime_service_database();

    $runtime = runtime_service_get_runtime(
        $pdo,
        $runtimeId
    );

    // --------------------------------------------------
    // WORKSPACE VALIDATION
    // --------------------------------------------------

    if (empty($runtime['workspace_path'])) {
        runtime_service_json(
            false,
            [],
            'Runtime workspace path is missing.',
            409
        );
    }

    $workspace = runtime_service_workspace(
        $runtime['workspace_path']
    );

    // --------------------------------------------------
    // STOP REAL PYTHON INFERENCE SERVICE
    // --------------------------------------------------

    $result = runtime_service_run_manager(
        'stop',
        $workspace,
        ''
    );

    // --------------------------------------------------
    // UPDATE DATABASE AFTER SUCCESSFUL STOP
    // --------------------------------------------------

    $update = $pdo->prepare("
        UPDATE model_runtimes
        SET
            status = 'stopped',
            errors = NULL
        WHERE id = :id
    ");

    $update->execute([
        ':id' => $runtimeId,
    ]);

    // --------------------------------------------------
    // SUCCESS RESPONSE
    // --------------------------------------------------

    runtime_service_json(
        true,
        [
            'runtime_id' => (int) $runtime['id'],
            'runtime_key' => $runtime['runtime_key'],
            'model_version_id' =>
                (int) $runtime['model_version_id'],

            'service' => $result['data'] ?? $result,
        ],
        'Model inference service stopped successfully.'
    );

} catch (Throwable $e) {

    error_log(
        'Runtime shutdown error: ' . $e->getMessage()
    );

    runtime_service_json(
        false,
        [],
        $e->getMessage(),
        500
    );
}
