<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod('GET');

$userId = requireAuth();

/*
|--------------------------------------------------------------------------
| Validate model ID
|--------------------------------------------------------------------------
*/

$modelId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($modelId === false || $modelId === null) {
    respond([
        'success' => false,
        'message' => 'A valid model ID is required.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Validate optional version ID
|--------------------------------------------------------------------------
|
| If version_id is omitted, the latest version is returned.
| If supplied, only that specific version is returned.
|
*/

$versionId = null;

if (array_key_exists('version_id', $_GET)) {
    $versionId = filter_input(
        INPUT_GET,
        'version_id',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($versionId === false || $versionId === null) {
        respond([
            'success' => false,
            'message' => 'A valid version ID is required.'
        ], 422);
    }
}

/*
|--------------------------------------------------------------------------
| Retrieve model, version, file and inspection
|--------------------------------------------------------------------------
|
| Ownership is enforced through models.user_id = authenticated user.
|
| Without version_id:
|   The latest version is returned.
|
| With version_id:
|   The requested version is returned only if it belongs to the model.
|
*/

$sql = '
    SELECT
        m.id AS model_id,
        m.name AS model_name,
        m.slug,
        m.status AS model_status,
        m.created_at AS model_created_at,
        m.updated_at AS model_updated_at,

        mv.id AS version_id,
        mv.version AS version_number,
        mv.status AS version_status,
        mv.created_at AS version_created_at,

        mf.id AS file_id,
        mf.original_name,
        mf.file_size,
        mf.checksum,
        mf.mime_type,
        mf.upload_status,
        mf.created_at AS file_created_at,

        mi.id AS inspection_id,
        mi.status AS inspection_status,
        mi.framework,
        mi.summary AS inspection_summary,
        mi.metadata,
        mi.files AS inspection_files,
        mi.requirements,
        mi.errors,
        mi.warnings,
        mi.inspected_at,
        mi.created_at AS inspection_created_at,
        mi.updated_at AS inspection_updated_at

    FROM models m

    INNER JOIN model_versions mv
        ON mv.model_id = m.id

    INNER JOIN model_files mf
        ON mf.model_version_id = mv.id

    LEFT JOIN model_inspections mi
        ON mi.model_version_id = mv.id

    WHERE
        m.id = ?
        AND m.user_id = ?
';

$params = [
    $modelId,
    $userId
];

/*
|--------------------------------------------------------------------------
| Apply selected version filter
|--------------------------------------------------------------------------
*/

if ($versionId !== null) {
    $sql .= '
        AND mv.id = ?
    ';

    $params[] = $versionId;
}

/*
|--------------------------------------------------------------------------
| Select latest version when no version is specified
|--------------------------------------------------------------------------
*/

$sql .= '
    ORDER BY mv.version DESC
    LIMIT 1
';

$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    respond([
        'success' => false,
        'message' => 'Model or requested version not found.'
    ], 404);
}

/*
|--------------------------------------------------------------------------
| JSON decoding helper
|--------------------------------------------------------------------------
*/

function decodeJsonField(
    mixed $value,
    mixed $default = null
): mixed {
    if ($value === null || $value === '') {
        return $default;
    }

    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode(
        (string) $value,
        true
    );

    if (json_last_error() !== JSON_ERROR_NONE) {
        return $default;
    }

    return $decoded;
}

/*
|--------------------------------------------------------------------------
| Decode inspection data
|--------------------------------------------------------------------------
*/

$metadata = decodeJsonField(
    $row['metadata'] ?? null,
    []
);

$inspectionFiles = decodeJsonField(
    $row['inspection_files'] ?? null,
    []
);

$requirements = decodeJsonField(
    $row['requirements'] ?? null,
    []
);

$errors = decodeJsonField(
    $row['errors'] ?? null,
    []
);

$warnings = decodeJsonField(
    $row['warnings'] ?? null,
    []
);

/*
|--------------------------------------------------------------------------
| Build inspection response
|--------------------------------------------------------------------------
*/

$inspection = null;

if ($row['inspection_id'] !== null) {
    $inspection = [
        'id' => (int) $row['inspection_id'],
        'status' => $row['inspection_status'],
        'framework' => $row['framework'],
        'summary' => $row['inspection_summary'],
        'metadata' => $metadata,
        'files' => $inspectionFiles,
        'requirements' => $requirements,
        'errors' => $errors,
        'warnings' => $warnings,
        'inspected_at' => $row['inspected_at'],
        'created_at' => $row['inspection_created_at'],
        'updated_at' => $row['inspection_updated_at']
    ];
}

/*
|--------------------------------------------------------------------------
| Build response
|--------------------------------------------------------------------------
*/

respond([
    'success' => true,

    'data' => [
        'model' => [
            'id' => (int) $row['model_id'],
            'name' => $row['model_name'],
            'slug' => $row['slug'],
            'status' => $row['model_status'],
            'created_at' => $row['model_created_at'],
            'updated_at' => $row['model_updated_at']
        ],

        'version' => [
            'id' => (int) $row['version_id'],
            'version' => (int) $row['version_number'],
            'status' => $row['version_status'],
            'created_at' => $row['version_created_at']
        ],

        'file' => [
            'id' => (int) $row['file_id'],
            'original_name' => $row['original_name'],
            'file_size' => (int) $row['file_size'],
            'checksum' => $row['checksum'],
            'mime_type' => $row['mime_type'],
            'upload_status' => $row['upload_status'],
            'created_at' => $row['file_created_at']
        ],

        'inspection' => $inspection
    ]
], 200);
