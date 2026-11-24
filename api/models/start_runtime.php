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
    // DATABASE
    // --------------------------------------------------

    $pdo = runtime_service_database();

    $runtime = runtime_service_get_runtime(
        $pdo,
        $runtimeId
    );

    // --------------------------------------------------
    // WORKSPACE VALIDATION
    // --------------------------------------------------

    $workspaceStatus = strtolower(
        trim((string) ($runtime['workspace_status'] ?? ''))
    );

    if (
        $workspaceStatus !== 'ready'
        || empty($runtime['workspace_path'])
    ) {
        runtime_service_json(
            false,
            [],
            'Runtime workspace is not ready.',
            409
        );
    }

    // --------------------------------------------------
    // PYTHON ENVIRONMENT VALIDATION
    // --------------------------------------------------

    $environmentStatus = strtolower(
        trim((string) ($runtime['environment_status'] ?? ''))
    );

    /*
     * An environment with status "created" is valid
     * when its dependency installation has completed.
     *
     * The dependency installation status is managed
     * separately from the environment creation status.
     */

    if (
        !in_array(
            $environmentStatus,
            ['created', 'ready'],
            true
        )
    ) {
        runtime_service_json(
            false,
            [
                'environment_status' => $environmentStatus,
            ],
            'Runtime Python environment is not ready. Current status: '
                . ($environmentStatus ?: 'unknown'),
            409
        );
    }

    if (empty($runtime['environment_path'])) {
        runtime_service_json(
            false,
            [],
            'Runtime environment path is missing.',
            409
        );
    }

    // --------------------------------------------------
    // RESOLVE WORKSPACE AND PYTHON EXECUTABLE
    // --------------------------------------------------

    $workspace = runtime_service_workspace(
        $runtime['workspace_path']
    );

    $python = runtime_service_environment_python(
        $runtime['environment_path']
    );

    // --------------------------------------------------
    // START REAL PYTHON INFERENCE SERVICE
    // --------------------------------------------------

    $result = runtime_service_run_manager(
        'start',
        $workspace,
        $python
    );

    // --------------------------------------------------
    // UPDATE DATABASE AFTER SUCCESSFUL STARTUP
    // --------------------------------------------------

    $update = $pdo->prepare("
        UPDATE model_runtimes
        SET
            status = 'running',
            started_at = COALESCE(started_at, NOW()),
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

            'model' => [
                'id' => (int) $runtime['model_id'],
                'name' => $runtime['model_name'],
                'slug' => $runtime['model_slug'],
                'version' => (int) $runtime['version'],
            ],

            'service' => $result['data'] ?? $result,
        ],
        'Model runtime started successfully.'
    );

} catch (Throwable $e) {

    error_log(
        'Runtime startup error: ' . $e->getMessage()
    );

    runtime_service_json(
        false,
        [],
        $e->getMessage(),
        500
    );
}
