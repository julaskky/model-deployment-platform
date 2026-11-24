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
| Verify model ownership
|--------------------------------------------------------------------------
*/

$modelStmt = $pdo->prepare(
    'SELECT
        id,
        name,
        slug,
        status,
        created_at,
        updated_at
     FROM models
     WHERE id = ?
       AND user_id = ?
     LIMIT 1'
);

$modelStmt->execute([
    $modelId,
    $userId
]);

$model = $modelStmt->fetch(PDO::FETCH_ASSOC);

if (!$model) {
    respond([
        'success' => false,
        'message' => 'Model not found.'
    ], 404);
}

/*
|--------------------------------------------------------------------------
| Retrieve model version history
|--------------------------------------------------------------------------
|
| Returns every version belonging to this model.
| The newest version is returned first.
|
*/

$versionsStmt = $pdo->prepare(
    'SELECT
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
        mi.inspected_at

     FROM model_versions mv

     LEFT JOIN model_files mf
        ON mf.model_version_id = mv.id

     LEFT JOIN model_inspections mi
        ON mi.model_version_id = mv.id

     WHERE mv.model_id = ?

     ORDER BY mv.version DESC'
);

$versionsStmt->execute([
    $modelId
]);

$rows = $versionsStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Build version history
|--------------------------------------------------------------------------
*/

$versions = [];

foreach ($rows as $row) {

    $file = null;

    if ($row['file_id'] !== null) {
        $file = [
            'id' => (int) $row['file_id'],
            'original_name' => $row['original_name'],
            'file_size' => (int) $row['file_size'],
            'checksum' => $row['checksum'],
            'mime_type' => $row['mime_type'],
            'upload_status' => $row['upload_status'],
            'created_at' => $row['file_created_at']
        ];
    }

    $inspection = null;

    if ($row['inspection_id'] !== null) {
        $inspection = [
            'id' => (int) $row['inspection_id'],
            'status' => $row['inspection_status'],
            'framework' => $row['framework'],
            'summary' => $row['inspection_summary'],
            'inspected_at' => $row['inspected_at']
        ];
    }

    $versions[] = [
        'id' => (int) $row['version_id'],
        'version' => (int) $row['version_number'],
        'status' => $row['version_status'],
        'created_at' => $row['version_created_at'],
        'file' => $file,
        'inspection' => $inspection
    ];
}

/*
|--------------------------------------------------------------------------
| Return version history
|--------------------------------------------------------------------------
*/

respond([
    'success' => true,
    'data' => [
        'model' => [
            'id' => (int) $model['id'],
            'name' => $model['name'],
            'slug' => $model['slug'],
            'status' => $model['status'],
            'created_at' => $model['created_at'],
            'updated_at' => $model['updated_at']
        ],

        'total_versions' => count($versions),

        'versions' => $versions
    ]
], 200);
