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

if ($runtimeId === false || $runtimeId === null) {
    respond([
        'success' => false,
        'message' => 'A valid runtime_id is required.'
    ], 422);
}

$storageRoot = realpath(__DIR__ . '/../storage');

if ($storageRoot === false) {
    respond([
        'success' => false,
        'message' => 'Private storage is unavailable.'
    ], 500);
}

$modelsRoot = realpath($storageRoot . DIRECTORY_SEPARATOR . 'models');

if ($modelsRoot === false) {
    respond([
        'success' => false,
        'message' => 'Private model storage is unavailable.'
    ], 500);
}

$workspaceRoot = $storageRoot . DIRECTORY_SEPARATOR . 'runtimes';

$pythonScript = dirname(__DIR__, 2)
    . DIRECTORY_SEPARATOR
    . 'python_engine'
    . DIRECTORY_SEPARATOR
    . 'prepare_runtime_workspace.py';

if (!is_file($pythonScript)) {
    respond([
        'success' => false,
        'message' => 'Runtime workspace manager CLI was not found.'
    ], 500);
}

$pythonBinary = getenv('MODEL_PYTHON_BINARY') ?: 'python';

try {

    /*
    |--------------------------------------------------------------------------
    | Lock runtime and verify ownership
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.runtime_key,
            r.runtime_name,
            r.status,
            r.workspace_status,
            r.workspace_path,
            r.manifest,
            r.python_version,
            r.framework,
            r.framework_version,
            mv.id AS model_version_id,
            mf.storage_key,
            m.id AS model_id,
            m.name AS model_name
        FROM model_runtimes r
        INNER JOIN model_versions mv
            ON mv.id = r.model_version_id
        INNER JOIN models m
            ON m.id = mv.model_id
        INNER JOIN model_files mf
            ON mf.model_version_id = mv.id
        WHERE r.id = ?
          AND m.user_id = ?
          AND mf.upload_status = 'completed'
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $runtimeId,
        $userId
    ]);

    $runtime = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$runtime) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'Runtime not found or access denied.'
        ], 404);
    }

    if ($runtime['workspace_status'] === 'ready') {
        $pdo->commit();

        respond([
            'success' => true,
            'message' => 'Runtime workspace is already prepared.',
            'data' => [
                'runtime_id' => (int) $runtime['id'],
                'runtime_key' => $runtime['runtime_key'],
                'status' => $runtime['status'],
                'workspace_status' => 'ready',
                'workspace_path' => $runtime['workspace_path']
            ]
        ]);
    }

    if ($runtime['workspace_status'] === 'creating') {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'Runtime workspace preparation is already in progress.'
        ], 409);
    }

    if ($runtime['status'] !== 'registered') {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'Only registered runtimes can be prepared at this stage.'
        ], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate stored archive key
    |--------------------------------------------------------------------------
    */

    $storageKey = $runtime['storage_key'];

    if (!preg_match('/^[a-f0-9]{48}\.zip$/', $storageKey)) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The stored model archive reference is invalid.'
        ], 422);
    }

    $sourceZip = realpath(
        $modelsRoot . DIRECTORY_SEPARATOR . $storageKey
    );

    if (
        $sourceZip === false ||
        !is_file($sourceZip) ||
        !str_starts_with(
            $sourceZip,
            $modelsRoot . DIRECTORY_SEPARATOR
        )
    ) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The uploaded model archive could not be found.'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate saved manifest
    |--------------------------------------------------------------------------
    */

    try {
        $manifest = json_decode(
            $runtime['manifest'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The saved runtime manifest is invalid.'
        ], 422);
    }

    if (
        !is_array($manifest) ||
        ($manifest['status'] ?? null) !== 'READY' ||
        (int) ($manifest['model']['model_version_id'] ?? 0)
            !== (int) $runtime['model_version_id']
    ) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The runtime manifest does not match this model version.'
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Mark workspace preparation as in progress
    |--------------------------------------------------------------------------
    */

    $updateStmt = $pdo->prepare("
        UPDATE model_runtimes
        SET
            workspace_status = 'creating',
            errors = NULL
        WHERE id = ?
    ");

    $updateStmt->execute([$runtimeId]);

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | Invoke Python workspace manager
    |--------------------------------------------------------------------------
    */

    if (!is_dir($workspaceRoot)) {
        if (!mkdir($workspaceRoot, 0700, true) && !is_dir($workspaceRoot)) {
            throw new RuntimeException(
                'Could not create private runtime storage.'
            );
        }
    }

    $payload = [
        'runtime_key' => $runtime['runtime_key'],
        'source_zip' => $sourceZip,
        'models_root' => $modelsRoot,
        'workspace_root' => $workspaceRoot,
        'manifest' => $manifest
    ];

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
        dirname(__DIR__, 2)
    );

    if (!is_resource($process)) {
        throw new RuntimeException(
            'Could not start the Python workspace manager.'
        );
    }

    fwrite(
        $pipes[0],
        json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        )
    );

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    $result = json_decode(
        $stdout ?: '',
        true
    );

    if (
        $exitCode !== 0 ||
        !is_array($result) ||
        ($result['success'] ?? false) !== true ||
        ($result['workspace_status'] ?? null) !== 'ready'
    ) {
        $failureMessage = is_array($result)
            ? ($result['message'] ?? 'Workspace preparation failed.')
            : 'Python workspace manager returned an invalid response.';

        error_log(
            'Runtime workspace failure: ' .
            $failureMessage .
            ' | ' . (string) $stderr
        );

        $errorJson = json_encode([
            'message' => $failureMessage
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $failureStmt = $pdo->prepare("
            UPDATE model_runtimes
            SET
                workspace_status = 'failed',
                errors = ?
            WHERE id = ?
        ");

        $failureStmt->execute([
            $errorJson,
            $runtimeId
        ]);

        respond([
            'success' => false,
            'message' => $failureMessage
        ], 500);
    }

    /*
    |--------------------------------------------------------------------------
    | Persist successful workspace preparation
    |--------------------------------------------------------------------------
    */

    $relativeWorkspacePath = 'storage/runtimes/'
        . $runtime['runtime_key'];

    $completeStmt = $pdo->prepare("
        UPDATE model_runtimes
        SET
            workspace_status = 'ready',
            workspace_path = ?,
            workspace_created_at = CURRENT_TIMESTAMP,
            errors = NULL
        WHERE id = ?
          AND workspace_status = 'creating'
    ");

    $completeStmt->execute([
        $relativeWorkspacePath,
        $runtimeId
    ]);

    if ($completeStmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Could not finalize runtime workspace registration.'
        );
    }

    respond([
        'success' => true,
        'message' => 'Runtime workspace prepared successfully.',
        'data' => [
            'runtime_id' => (int) $runtimeId,
            'runtime_key' => $runtime['runtime_key'],
            'runtime_name' => $runtime['runtime_name'],
            'runtime_status' => 'registered',
            'workspace_status' => 'ready',
            'workspace_path' => $relativeWorkspacePath,
            'file_count' => (int) ($result['file_count'] ?? 0),
            'model' => [
                'id' => (int) $runtime['model_id'],
                'name' => $runtime['model_name']
            ],
            'version' => [
                'id' => (int) $runtime['model_version_id']
            ]
        ]
    ], 201);

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Runtime workspace preparation error: '
        . $exception->getMessage()
    );

    try {
        $failureStmt = $pdo->prepare("
            UPDATE model_runtimes
            SET
                workspace_status = 'failed',
                errors = ?
            WHERE id = ?
              AND workspace_status <> 'ready'
        ");

        $failureStmt->execute([
            json_encode([
                'message' => $exception->getMessage()
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $runtimeId
        ]);
    } catch (Throwable $databaseException) {
        error_log(
            'Could not record workspace failure: '
            . $databaseException->getMessage()
        );
    }

    respond([
        'success' => false,
        'message' => 'Runtime workspace preparation failed.'
    ], 500);
}
