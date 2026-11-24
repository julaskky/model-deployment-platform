<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod('GET');

$userId = requireAuth();

/*
|--------------------------------------------------------------------------
| Validate model version ID
|--------------------------------------------------------------------------
*/

$modelVersionId = filter_input(
    INPUT_GET,
    'model_version_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($modelVersionId === false || $modelVersionId === null) {
    respond([
        'success' => false,
        'message' => 'A valid model_version_id is required.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Retrieve runtime configuration
|--------------------------------------------------------------------------
|
| Ownership is enforced through models.user_id.
|
*/

$stmt = $pdo->prepare(
    "SELECT
        m.id AS model_id,
        m.name AS model_name,
        m.slug AS model_slug,

        mv.id AS model_version_id,
        mv.version AS version_number,
        mv.status AS version_status,

        rc.id AS runtime_configuration_id,
        rc.status AS runtime_status,
        rc.manifest_version,
        rc.python_version,
        rc.framework,
        rc.framework_version,
        rc.manifest,
        rc.errors,
        rc.created_at,
        rc.updated_at

    FROM model_runtime_configurations rc

    INNER JOIN model_versions mv
        ON mv.id = rc.model_version_id

    INNER JOIN models m
        ON m.id = mv.model_id

    WHERE
        rc.model_version_id = ?
        AND m.user_id = ?

    LIMIT 1"
);

$stmt->execute([
    $modelVersionId,
    $userId
]);

$record = $stmt->fetch();

if (!$record) {
    respond([
        'success' => false,
        'message' =>
            'Runtime configuration not found for the requested model version.'
    ], 404);
}

/*
|--------------------------------------------------------------------------
| Decode saved manifest
|--------------------------------------------------------------------------
*/

try {
    $manifest = json_decode(
        $record['manifest'],
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!is_array($manifest)) {
        throw new RuntimeException(
            'Saved runtime manifest has an invalid structure.'
        );
    }

} catch (Throwable $e) {

    error_log(
        'Runtime configuration manifest decode error: ' .
        $e->getMessage()
    );

    respond([
        'success' => false,
        'message' =>
            'The saved runtime manifest is invalid.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Decode saved errors
|--------------------------------------------------------------------------
*/

$errors = [];

if (
    isset($record['errors']) &&
    $record['errors'] !== null &&
    $record['errors'] !== ''
) {
    try {
        $decodedErrors = json_decode(
            $record['errors'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (is_array($decodedErrors)) {
            $errors = $decodedErrors;
        }

    } catch (Throwable $e) {

        error_log(
            'Runtime configuration errors JSON decode error: ' .
            $e->getMessage()
        );
    }
}

/*
|--------------------------------------------------------------------------
| Return runtime configuration
|--------------------------------------------------------------------------
*/

respond([
    'success' => true,

    'message' =>
        'Runtime configuration retrieved successfully.',

    'data' => [
        'runtime_configuration' => [
            'id' =>
                (int) $record['runtime_configuration_id'],

            'status' =>
                $record['runtime_status'],

            'manifest_version' =>
                $record['manifest_version'],

            'python_version' =>
                $record['python_version'],

            'framework' =>
                $record['framework'],

            'framework_version' =>
                $record['framework_version'],

            'created_at' =>
                $record['created_at'],

            'updated_at' =>
                $record['updated_at']
        ],

        'model' => [
            'id' =>
                (int) $record['model_id'],

            'name' =>
                $record['model_name'],

            'slug' =>
                $record['model_slug']
        ],

        'version' => [
            'id' =>
                (int) $record['model_version_id'],

            'number' =>
                (int) $record['version_number'],

            'status' =>
                $record['version_status']
        ],

        'manifest' =>
            $manifest,

        'errors' =>
            $errors
    ]
]);