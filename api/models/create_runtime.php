<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod('POST');

$userId = requireAuth();
requireCsrf();

$input = getJsonInput();

/*
|--------------------------------------------------------------------------
| Validate model version ID
|--------------------------------------------------------------------------
*/

$modelVersionId = filter_var(
    $input['model_version_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($modelVersionId === false || $modelVersionId === null) {
    respond([
        'success' => false,
        'message' => 'A valid model_version_id is required.'
    ], 422);
}

try {
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Verify ownership and lock the selected model version
    |--------------------------------------------------------------------------
    */

    $versionStmt = $pdo->prepare("
        SELECT
            mv.id AS model_version_id,
            mv.model_id,
            mv.version,
            mv.status AS version_status,
            m.name AS model_name,
            m.slug AS model_slug
        FROM model_versions mv
        INNER JOIN models m
            ON m.id = mv.model_id
        WHERE mv.id = ?
          AND m.user_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $versionStmt->execute([
        $modelVersionId,
        $userId
    ]);

    $version = $versionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$version) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'Model version not found or access denied.'
        ], 404);
    }

    if ($version['version_status'] !== 'ready') {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'Only model versions with READY status can be registered for deployment.'
        ], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieve the saved runtime configuration
    |--------------------------------------------------------------------------
    */

    $configStmt = $pdo->prepare("
        SELECT
            id,
            status,
            manifest_version,
            python_version,
            framework,
            framework_version,
            manifest
        FROM model_runtime_configurations
        WHERE model_version_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $configStmt->execute([$modelVersionId]);

    $configuration = $configStmt->fetch(PDO::FETCH_ASSOC);

    if (!$configuration) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'Runtime configuration has not been generated for this model version.'
        ], 404);
    }

    if ($configuration['status'] !== 'generated') {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The saved runtime configuration is not ready.'
        ], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate saved manifest
    |--------------------------------------------------------------------------
    */

    try {
        $manifest = json_decode(
            $configuration['manifest'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The saved runtime manifest contains invalid JSON.'
        ], 422);
    }

    if (
        !is_array($manifest) ||
        ($manifest['status'] ?? null) !== 'READY' ||
        (int) ($manifest['model']['model_id'] ?? 0) !== (int) $version['model_id'] ||
        (int) ($manifest['model']['model_version_id'] ?? 0) !== (int) $version['model_version_id']
    ) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The runtime manifest is invalid or does not match the selected model version.'
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Check whether a runtime is already registered
    |--------------------------------------------------------------------------
    */

    $existingStmt = $pdo->prepare("
        SELECT
            id,
            runtime_key,
            runtime_name,
            status,
            engine_version,
            python_version,
            framework,
            framework_version,
            workspace_path,
            created_at,
            updated_at
        FROM model_runtimes
        WHERE model_version_id = ?
        LIMIT 1
    ");

    $existingStmt->execute([$modelVersionId]);

    $existingRuntime = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingRuntime) {
        $pdo->commit();

        respond([
            'success' => true,
            'message' => 'A runtime is already registered for this model version.',
            'data' => [
                'runtime' => $existingRuntime,
                'model' => [
                    'id' => (int) $version['model_id'],
                    'name' => $version['model_name'],
                    'slug' => $version['model_slug']
                ],
                'version' => [
                    'id' => (int) $version['model_version_id'],
                    'number' => (int) $version['version']
                ],
                'manifest' => $manifest
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Generate runtime identity
    |--------------------------------------------------------------------------
    */

    $runtimeKey = bin2hex(random_bytes(16));

    $runtimeName = substr(
        $version['model_slug'] . '-v' . $version['version'],
        0,
        150
    );

    $engineVersion = '1.0.0';

    /*
    |--------------------------------------------------------------------------
    | Register runtime
    |--------------------------------------------------------------------------
    |
    | This creates a runtime management record only.
    | No customer code is executed.
    | No Python environment is created.
    | No inference server is started.
    |
    */

    $insertStmt = $pdo->prepare("
        INSERT INTO model_runtimes (
            runtime_key,
            model_version_id,
            runtime_name,
            engine_version,
            python_version,
            framework,
            framework_version,
            status,
            workspace_path,
            manifest
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, 'registered', NULL, ?
        )
    ");

    $manifestJson = json_encode(
        $manifest,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_THROW_ON_ERROR
    );

    $insertStmt->execute([
        $runtimeKey,
        $modelVersionId,
        $runtimeName,
        $engineVersion,
        $configuration['python_version'],
        $configuration['framework'],
        $configuration['framework_version'],
        $manifestJson
    ]);

    $runtimeId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Retrieve registered runtime
    |--------------------------------------------------------------------------
    */

    $runtimeStmt = $pdo->prepare("
        SELECT
            id,
            runtime_key,
            model_version_id,
            runtime_name,
            engine_version,
            python_version,
            framework,
            framework_version,
            status,
            workspace_path,
            created_at,
            updated_at
        FROM model_runtimes
        WHERE id = ?
        LIMIT 1
    ");

    $runtimeStmt->execute([$runtimeId]);

    $runtime = $runtimeStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    respond([
        'success' => true,
        'message' => 'Runtime registered successfully.',
        'data' => [
            'runtime' => $runtime,
            'model' => [
                'id' => (int) $version['model_id'],
                'name' => $version['model_name'],
                'slug' => $version['model_slug']
            ],
            'version' => [
                'id' => (int) $version['model_version_id'],
                'number' => (int) $version['version']
            ],
            'manifest' => $manifest
        ]
    ], 201);

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Runtime registration error: ' . $exception->getMessage()
    );

    respond([
        'success' => false,
        'message' => 'An error occurred while registering the runtime.'
    ], 500);
}
