<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod('POST');

$userId = requireAuth();
requireCsrf();

$input = getJsonInput();

$runtimeId = filter_var(
    $input['runtime_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$runtimeId) {
    respond([
        'success' => false,
        'message' => 'A valid runtime_id is required.'
    ], 422);
}

$pdo->beginTransaction();

try {
    /*
    |--------------------------------------------------------------------------
    | Load and lock the runtime
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.runtime_key,
            r.model_version_id,
            r.python_version,
            r.status,
            r.workspace_status,
            r.workspace_path,
            m.name AS model_name,
            m.user_id
        FROM model_runtimes r
        INNER JOIN model_versions mv
            ON mv.id = r.model_version_id
        INNER JOIN models m
            ON m.id = mv.model_id
        WHERE r.id = ?
          AND m.user_id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        $runtimeId,
        $userId
    ]);

    $runtime = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$runtime) {
        throw new RuntimeException(
            'Runtime not found or access denied.'
        );
    }

    if ($runtime['workspace_status'] !== 'ready') {
        throw new RuntimeException(
            'The runtime workspace must be ready before creating its environment.'
        );
    }

    if ($runtime['status'] !== 'registered') {
        throw new RuntimeException(
            'Only registered runtimes can have an environment prepared.'
        );
    }

    if (
        !preg_match('/^[a-f0-9]{32}$/', $runtime['runtime_key'])
    ) {
        throw new RuntimeException(
            'The runtime key is invalid.'
        );
    }

    if (
        !preg_match('/^\d+\.\d+$/', $runtime['python_version'])
    ) {
        throw new RuntimeException(
            'The runtime Python version is invalid.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Check existing environment record
    |--------------------------------------------------------------------------
    */

    $environmentStmt = $pdo->prepare("
        SELECT
            id,
            environment_key,
            status,
            environment_path
        FROM runtime_environments
        WHERE runtime_id = ?
        FOR UPDATE
    ");

    $environmentStmt->execute([$runtimeId]);

    $existingEnvironment = $environmentStmt->fetch(
        PDO::FETCH_ASSOC
    );

    if (
        $existingEnvironment &&
        $existingEnvironment['status'] === 'created'
    ) {
        $pdo->commit();

        respond([
            'success' => true,
            'message' => 'Runtime environment already exists.',
            'data' => [
                'environment_id' => (int) $existingEnvironment['id'],
                'runtime_id' => (int) $runtimeId,
                'environment_key' =>
                    $existingEnvironment['environment_key'],
                'status' => 'created',
                'environment_path' =>
                    $existingEnvironment['environment_path']
            ]
        ]);
    }

    if (
        $existingEnvironment &&
        $existingEnvironment['status'] === 'creating'
    ) {
        throw new RuntimeException(
            'Environment creation is already in progress.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Register environment preparation
    |--------------------------------------------------------------------------
    */

    $environmentKey = bin2hex(random_bytes(16));

    if ($existingEnvironment) {
        $saveStmt = $pdo->prepare("
            UPDATE runtime_environments
            SET
                environment_key = ?,
                environment_type = 'python_venv',
                python_version = ?,
                status = 'creating',
                environment_path = NULL,
                dependencies = NULL,
                errors = NULL
            WHERE id = ?
        ");

        $saveStmt->execute([
            $environmentKey,
            $runtime['python_version'],
            $existingEnvironment['id']
        ]);

        $environmentId = (int) $existingEnvironment['id'];

    } else {
        $saveStmt = $pdo->prepare("
            INSERT INTO runtime_environments (
                runtime_id,
                environment_key,
                environment_type,
                python_version,
                status
            ) VALUES (?, ?, 'python_venv', ?, 'creating')
        ");

        $saveStmt->execute([
            $runtimeId,
            $environmentKey,
            $runtime['python_version']
        ]);

        $environmentId = (int) $pdo->lastInsertId();
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond([
        'success' => false,
        'message' => $e->getMessage()
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Resolve private runtime workspace
|--------------------------------------------------------------------------
*/

$runtimeStorageRoot = realpath(
    __DIR__ . '/../storage/runtimes'
);

if ($runtimeStorageRoot === false) {
    $error = 'Runtime storage directory could not be resolved.';

    $stmt = $pdo->prepare("
        UPDATE runtime_environments
        SET status = 'failed',
            errors = ?
        WHERE id = ?
    ");

    $stmt->execute([
        json_encode([$error]),
        $environmentId
    ]);

    respond([
        'success' => false,
        'message' => $error
    ], 500);
}

$workspacePath = $runtimeStorageRoot
    . DIRECTORY_SEPARATOR
    . $runtime['runtime_key'];

$resolvedWorkspace = realpath($workspacePath);

if (
    $resolvedWorkspace === false ||
    !is_dir($resolvedWorkspace) ||
    !str_starts_with(
        strtolower($resolvedWorkspace),
        strtolower($runtimeStorageRoot . DIRECTORY_SEPARATOR)
    )
) {
    $error = 'The runtime workspace could not be safely resolved.';

    $stmt = $pdo->prepare("
        UPDATE runtime_environments
        SET status = 'failed',
            errors = ?
        WHERE id = ?
    ");

    $stmt->execute([
        json_encode([$error]),
        $environmentId
    ]);

    respond([
        'success' => false,
        'message' => $error
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Execute Python environment builder
|--------------------------------------------------------------------------
*/

$pythonEngineRoot = realpath(
    __DIR__ . '/../../python_engine'
);

$pythonScript = __DIR__
    . '/../../python_engine/create_runtime_environment.py';

if (
    $pythonEngineRoot === false ||
    !is_file($pythonScript)
) {
    $error = 'Python environment builder was not found.';

    $stmt = $pdo->prepare("
        UPDATE runtime_environments
        SET status = 'failed',
            errors = ?
        WHERE id = ?
    ");

    $stmt->execute([
        json_encode([$error]),
        $environmentId
    ]);

    respond([
        'success' => false,
        'message' => $error
    ], 500);
}

$pythonBinary = getenv('MODEL_PYTHON_BINARY') ?: 'python';

$payload = json_encode([
    'runtime_key' => $runtime['runtime_key'],
    'workspace_path' => $resolvedWorkspace,
    'python_version' => $runtime['python_version']
], JSON_UNESCAPED_SLASHES);

$command = [
    $pythonBinary,
    $pythonScript
];

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
];

$process = proc_open(
    $command,
    $descriptors,
    $pipes,
    $pythonEngineRoot
);

$result = null;
$processError = null;

if (is_resource($process)) {
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    $result = json_decode(
        $stdout,
        true
    );

    if (
        $exitCode !== 0 ||
        !is_array($result) ||
        empty($result['success'])
    ) {
        $processError =
            $result['message'] ?? trim((string) $stderr);

        if ($processError === '') {
            $processError = 'Python environment creation failed.';
        }
    }

} else {
    $processError = 'Unable to start the Python environment builder.';
}

/*
|--------------------------------------------------------------------------
| Persist environment result
|--------------------------------------------------------------------------
*/

if ($processError !== null) {
    $errors = json_encode([
        $processError
    ]);

    $stmt = $pdo->prepare("
        UPDATE runtime_environments
        SET
            status = 'failed',
            errors = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $errors,
        $environmentId
    ]);

    respond([
        'success' => false,
        'message' => 'Runtime environment creation failed.',
        'error' => $processError
    ], 500);
}

$relativeEnvironmentPath =
    'storage/runtimes/'
    . $runtime['runtime_key']
    . '/environment/python';

$stmt = $pdo->prepare("
    UPDATE runtime_environments
    SET
        status = 'created',
        environment_path = ?,
        dependencies = ?,
        errors = NULL
    WHERE id = ?
");

$stmt->execute([
    $relativeEnvironmentPath,
    json_encode([]),
    $environmentId
]);

respond([
    'success' => true,
    'message' => 'Python runtime environment created successfully.',
    'data' => [
        'environment_id' => $environmentId,
        'runtime_id' => (int) $runtimeId,
        'environment_key' => $environmentKey,
        'model_name' => $runtime['model_name'],
        'python_version' => $runtime['python_version'],
        'environment_type' => 'python_venv',
        'status' => 'created',
        'environment_path' => $relativeEnvironmentPath,
        'pip_installed' => false,
        'dependencies_installed' => false
    ]
], 201);
