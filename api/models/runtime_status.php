<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod('GET');

$userId = requireAuth();

$modelVersionId = filter_input(
    INPUT_GET,
    'model_version_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$modelVersionId) {
    respond([
        'success' => false,
        'message' => 'A valid model_version_id is required.'
    ], 422);
}

$stmt = $pdo->prepare("
    SELECT
        m.id AS model_id,
        m.name AS model_name,
        m.slug AS model_slug,
        m.status AS model_status,

        mv.id AS model_version_id,
        mv.version AS model_version,
        mv.status AS version_status,

        rc.id AS runtime_configuration_id,
        rc.status AS configuration_status,
        rc.python_version,
        rc.framework,
        rc.framework_version,
        rc.manifest_version,
        rc.manifest,
        rc.errors AS configuration_errors,

        r.id AS runtime_id,
        r.runtime_key,
        r.runtime_name,
        r.engine_version,
        r.status AS runtime_status,
        r.workspace_status,
        r.workspace_path,
        r.last_health_check,
        r.started_at,
        r.stopped_at,
        r.errors AS runtime_errors,

        re.id AS environment_id,
        re.environment_key,
        re.environment_type,
        re.status AS environment_status,
        re.environment_path,
        re.dependencies,
        re.errors AS environment_errors,

        re.dependency_status,
        re.dependency_platform,
        re.dependency_lock_path,
        re.dependency_verified_at,
        re.dependencies_installed_at,
        re.installed_packages,
        re.dependency_installation_details,
        re.dependency_errors

    FROM model_versions mv

    INNER JOIN models m
        ON m.id = mv.model_id

    LEFT JOIN model_runtime_configurations rc
        ON rc.model_version_id = mv.id

    LEFT JOIN model_runtimes r
        ON r.model_version_id = mv.id

    LEFT JOIN runtime_environments re
        ON re.runtime_id = r.id

    WHERE mv.id = ?
      AND m.user_id = ?

    LIMIT 1
");

$stmt->execute([
    $modelVersionId,
    $userId
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    respond([
        'success' => false,
        'message' => 'Model version not found or access denied.'
    ], 404);
}

$decodeJson = static function ($value, $default = null) {
    if ($value === null || $value === '') {
        return $default;
    }

    $decoded = json_decode($value, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $decoded
        : $default;
};

/*
|--------------------------------------------------------------------------
| Runtime configuration
|--------------------------------------------------------------------------
*/

$configuration = null;

if ($row['runtime_configuration_id'] !== null) {
    $configuration = [
        'id' => (int) $row['runtime_configuration_id'],
        'status' => $row['configuration_status'],
        'manifest_version' => $row['manifest_version'],
        'python_version' => $row['python_version'],
        'framework' => $row['framework'],
        'framework_version' => $row['framework_version'],
        'manifest' => $decodeJson($row['manifest'], []),
        'errors' => $decodeJson(
            $row['configuration_errors'],
            []
        )
    ];
}

/*
|--------------------------------------------------------------------------
| Runtime
|--------------------------------------------------------------------------
*/

$runtime = null;

if ($row['runtime_id'] !== null) {
    $runtime = [
        'id' => (int) $row['runtime_id'],
        'runtime_key' => $row['runtime_key'],
        'runtime_name' => $row['runtime_name'],
        'engine_version' => $row['engine_version'],
        'status' => $row['runtime_status'],
        'workspace_status' => $row['workspace_status'],
        'workspace_path' => $row['workspace_path'],
        'last_health_check' => $row['last_health_check'],
        'started_at' => $row['started_at'],
        'stopped_at' => $row['stopped_at'],
        'errors' => $decodeJson(
            $row['runtime_errors'],
            []
        )
    ];
}

/*
|--------------------------------------------------------------------------
| Environment and dependency installation
|--------------------------------------------------------------------------
*/

$environment = null;

if ($row['environment_id'] !== null) {

    $installationDetails = $decodeJson(
        $row['dependency_installation_details'],
        []
    );

    /*
     * Return a safe summary instead of exposing internal
     * executable paths and complete pip output.
     */

    $installationSummary = [];

    if (is_array($installationDetails)) {
        $installationSummary = [
            'verification' => $installationDetails['verification'] ?? null,
            'pip' => $installationDetails['pip'] ?? null,
            'installed_count' => $installationDetails['installed_count'] ?? null,
            'network_access' => $installationDetails['network_access'] ?? null,
            'message' => $installationDetails['message'] ?? null
        ];
    }

    $environment = [
        'id' => (int) $row['environment_id'],
        'environment_key' => $row['environment_key'],
        'type' => $row['environment_type'],
        'status' => $row['environment_status'],
        'environment_path' => $row['environment_path'],

        'dependencies' => $decodeJson(
            $row['dependencies'],
            []
        ),

        'dependency_installation' => [
            'status' => $row['dependency_status'] ?? 'not_started',
            'platform' => $row['dependency_platform'],
            'verified_at' => $row['dependency_verified_at'],
            'installed_at' => $row['dependencies_installed_at'],

            'installed_packages' => $decodeJson(
                $row['installed_packages'],
                []
            ),

            'details' => $installationSummary,

            'errors' => $decodeJson(
                $row['dependency_errors'],
                []
            )
        ],

        'errors' => $decodeJson(
            $row['environment_errors'],
            []
        )
    ];
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

respond([
    'success' => true,
    'message' => 'Runtime status retrieved successfully.',
    'data' => [
        'model' => [
            'id' => (int) $row['model_id'],
            'name' => $row['model_name'],
            'slug' => $row['model_slug'],
            'status' => $row['model_status']
        ],

        'version' => [
            'id' => (int) $row['model_version_id'],
            'version' => (int) $row['model_version'],
            'status' => $row['version_status']
        ],

        'configuration' => $configuration,
        'runtime' => $runtime,
        'environment' => $environment
    ]
]);