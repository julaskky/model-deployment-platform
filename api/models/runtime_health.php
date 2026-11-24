<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_service.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    runtime_service_json(
        false,
        [],
        'GET method required.',
        405
    );
}

try {
    runtime_service_auth();

    $runtimeId = filter_var(
        $_GET['runtime_id'] ?? null,
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

    $pdo = runtime_service_database();

    $runtime = runtime_service_get_runtime(
        $pdo,
        $runtimeId
    );

    $workspace = runtime_service_workspace(
        $runtime['workspace_path']
    );

    $serviceConfig =
        $workspace
        . DIRECTORY_SEPARATOR
        . 'runtime'
        . DIRECTORY_SEPARATOR
        . 'inference_service.json';

    if (!is_file($serviceConfig)) {
        runtime_service_json(
            true,
            [
                'runtime_id' => (int) $runtime['id'],
                'status' => 'not_started',
                'health' => 'unavailable',
                'model_loaded' => false,
            ]
        );
    }

    $service = json_decode(
        file_get_contents($serviceConfig),
        true
    );

    if (!is_array($service)) {
        runtime_service_json(
            false,
            [],
            'Invalid inference service state.',
            500
        );
    }

    try {
        $result = runtime_service_run_manager(
            'status',
            $workspace,
            ''
        );

        runtime_service_json(
            true,
            [
                'runtime_id' => (int) $runtime['id'],
                'runtime_status' => $runtime['status'],
                'service' => $result['data'] ?? $result,
            ]
        );

    } catch (Throwable $e) {

        runtime_service_json(
            true,
            [
                'runtime_id' => (int) $runtime['id'],
                'runtime_status' => 'stopped',
                'health' => 'unavailable',
                'model_loaded' => false,
            ]
        );
    }

} catch (Throwable $e) {

    runtime_service_json(
        false,
        [],
        $e->getMessage(),
        500
    );
}