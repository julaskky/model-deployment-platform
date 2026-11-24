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
    runtime_service_auth();
    runtime_service_csrf();

    $input = json_decode(
        file_get_contents('php://input'),
        true
    );

    if (!is_array($input)) {
        runtime_service_json(
            false,
            [],
            'Invalid JSON request.',
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

    $predictionInput = $input['input'] ?? null;

    if (!is_array($predictionInput)) {
        runtime_service_json(
            false,
            [],
            'Prediction input must be a JSON object.',
            400
        );
    }

    $pdo = runtime_service_database();

    $runtime = runtime_service_get_runtime(
        $pdo,
        $runtimeId
    );

    if (
        empty($runtime['workspace_path'])
        || $runtime['workspace_status'] !== 'ready'
    ) {
        runtime_service_json(
            false,
            [],
            'Runtime workspace is not ready.',
            409
        );
    }

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
            false,
            [],
            'Inference service is not running.',
            409
        );
    }

    $service = json_decode(
        file_get_contents($serviceConfig),
        true
    );

    if (
        !is_array($service)
        || empty($service['port'])
        || empty($service['token'])
    ) {
        runtime_service_json(
            false,
            [],
            'Inference service configuration is invalid.',
            500
        );
    }

    $url =
        'http://127.0.0.1:'
        . (int) $service['port']
        . '/predict';

    $payload = json_encode(
        $predictionInput,
        JSON_UNESCAPED_SLASHES
    );

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Runtime-Token: ' . $service['token'],
        ],
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        runtime_service_json(
            false,
            [],
            'Inference service unavailable: ' . $error,
            503
        );
    }

    $httpCode = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    $decoded = json_decode(
        $response,
        true
    );

    if (!is_array($decoded)) {
        runtime_service_json(
            false,
            [],
            'Inference service returned invalid JSON.',
            502
        );
    }

    if ($httpCode >= 400) {
        runtime_service_json(
            false,
            $decoded['data'] ?? [],
            $decoded['message'] ?? 'Inference failed.',
            $httpCode
        );
    }

    runtime_service_json(
        true,
        $decoded['data'] ?? [],
        'Prediction completed successfully.'
    );

} catch (Throwable $e) {

    runtime_service_json(
        false,
        [],
        $e->getMessage(),
        500
    );
}