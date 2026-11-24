<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/inspection_service.php';

requireMethod('POST');

$userId = requireAuth();
requireCsrf();

/*
|--------------------------------------------------------------------------
| Upload configuration
|--------------------------------------------------------------------------
*/

const MAX_ARCHIVE_SIZE = 536870912;
const MAX_ENTRIES = 5000;
const MAX_UNCOMPRESSED_FILE_SIZE = 536870912;
const MAX_TOTAL_UNCOMPRESSED_SIZE = 2147483648;
const MAX_COMPRESSION_RATIO = 100;

const STORAGE_DIRECTORY = __DIR__ . '/../storage/models';

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/

function uploadErrorMessage(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE =>
            'The uploaded file exceeds the PHP upload_max_filesize limit.',

        UPLOAD_ERR_FORM_SIZE =>
            'The uploaded file exceeds the allowed form size.',

        UPLOAD_ERR_PARTIAL =>
            'The file upload was interrupted. Please try again.',

        UPLOAD_ERR_NO_FILE =>
            'Please select a model package.',

        UPLOAD_ERR_NO_TMP_DIR =>
            'The server temporary upload directory is unavailable.',

        UPLOAD_ERR_CANT_WRITE =>
            'The server could not write the uploaded file.',

        UPLOAD_ERR_EXTENSION =>
            'A PHP extension stopped the file upload.',

        default =>
            'The file upload failed.'
    };
}

function normalizeModelName(string $name): string
{
    $name = trim($name);

    return preg_replace('/\s+/u', ' ', $name) ?? $name;
}

function createSlug(string $name): string
{
    $slug = strtolower($name);

    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'model';
    }

    return substr($slug, 0, 150);
}

function generateUniqueSlug(
    PDO $pdo,
    string $baseSlug
): string {
    $slug = $baseSlug;
    $counter = 2;

    while (true) {
        $stmt = $pdo->prepare(
            'SELECT id FROM models WHERE slug = ? LIMIT 1'
        );

        $stmt->execute([$slug]);

        if (!$stmt->fetch()) {
            return $slug;
        }

        $suffix = '-' . $counter;

        $slug = substr(
            $baseSlug,
            0,
            180 - strlen($suffix)
        ) . $suffix;

        $counter++;
    }
}

function isDangerousPath(string $path): bool
{
    if ($path === '') {
        return true;
    }

    $normalized = str_replace('\\', '/', $path);

    if (str_starts_with($normalized, '/')) {
        return true;
    }

    if (preg_match('/^[A-Za-z]:\//', $normalized)) {
        return true;
    }

    foreach (explode('/', $normalized) as $part) {
        if ($part === '..') {
            return true;
        }
    }

    return str_contains($normalized, "\0");
}

function isSymlinkEntry(
    ZipArchive $zip,
    int $index
): bool {
    $opsys = 0;
    $attributes = 0;

    if (!$zip->getExternalAttributesIndex(
        $index,
        $opsys,
        $attributes
    )) {
        return false;
    }

    $fileType = ($attributes >> 16) & 0xF000;

    return $fileType === 0xA000;
}

function validateZipArchive(
    string $filePath,
    int $compressedSize
): array {
    if (!class_exists('ZipArchive')) {
        return [
            'valid' => false,
            'message' => 'ZIP support is not available on the server.'
        ];
    }

    $zip = new ZipArchive();

    $openResult = $zip->open($filePath);

    if ($openResult !== true) {
        return [
            'valid' => false,
            'message' => 'The uploaded file is not a valid ZIP archive.'
        ];
    }

    try {
        $entryCount = $zip->numFiles;

        if ($entryCount < 1) {
            throw new RuntimeException(
                'The ZIP archive is empty.'
            );
        }

        if ($entryCount > MAX_ENTRIES) {
            throw new RuntimeException(
                'The ZIP archive contains too many files.'
            );
        }

        $totalUncompressed = 0;
        $seenPaths = [];

        for ($i = 0; $i < $entryCount; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw new RuntimeException(
                    'The ZIP archive contains an unreadable entry.'
                );
            }

            $entryName = (string) ($stat['name'] ?? '');

            if (isDangerousPath($entryName)) {
                throw new RuntimeException(
                    'The ZIP archive contains an unsafe file path.'
                );
            }

            $pathKey = strtolower(
                str_replace('\\', '/', $entryName)
            );

            if (isset($seenPaths[$pathKey])) {
                throw new RuntimeException(
                    'The ZIP archive contains duplicate file paths.'
                );
            }

            $seenPaths[$pathKey] = true;

            if (isSymlinkEntry($zip, $i)) {
                throw new RuntimeException(
                    'The ZIP archive contains a symbolic link, which is not allowed.'
                );
            }

            $uncompressedSize = (int) ($stat['size'] ?? 0);
            $compressedEntrySize = (int) ($stat['comp_size'] ?? 0);

            if (
                $uncompressedSize < 0 ||
                $compressedEntrySize < 0
            ) {
                throw new RuntimeException(
                    'The ZIP archive contains invalid file size information.'
                );
            }

            if (
                $uncompressedSize >
                MAX_UNCOMPRESSED_FILE_SIZE
            ) {
                throw new RuntimeException(
                    'A file inside the ZIP archive is too large.'
                );
            }

            $totalUncompressed += $uncompressedSize;

            if (
                $totalUncompressed >
                MAX_TOTAL_UNCOMPRESSED_SIZE
            ) {
                throw new RuntimeException(
                    'The total uncompressed size of the ZIP archive is too large.'
                );
            }

            if ($uncompressedSize > 0) {
                if ($compressedEntrySize === 0) {
                    throw new RuntimeException(
                        'The ZIP archive has an unsafe compression ratio.'
                    );
                }

                $ratio =
                    $uncompressedSize /
                    $compressedEntrySize;

                if ($ratio > MAX_COMPRESSION_RATIO) {
                    throw new RuntimeException(
                        'The ZIP archive has an unsafe compression ratio.'
                    );
                }
            }
        }

        if (
            $compressedSize > 0 &&
            $totalUncompressed > 0
        ) {
            $archiveRatio =
                $totalUncompressed /
                $compressedSize;

            if ($archiveRatio > MAX_COMPRESSION_RATIO) {
                throw new RuntimeException(
                    'The ZIP archive has an unsafe compression ratio.'
                );
            }
        }

        return [
            'valid' => true,
            'entry_count' => $entryCount,
            'uncompressed_size' => $totalUncompressed
        ];

    } catch (Throwable $e) {
        return [
            'valid' => false,
            'message' => $e->getMessage()
        ];

    } finally {
        $zip->close();
    }
}

/*
|--------------------------------------------------------------------------
| Validate model name
|--------------------------------------------------------------------------
*/

$modelName = normalizeModelName(
    (string) ($_POST['name'] ?? '')
);

if ($modelName === '') {
    respond([
        'success' => false,
        'message' => 'Model name is required.'
    ], 422);
}

if (mb_strlen($modelName) < 2) {
    respond([
        'success' => false,
        'message' => 'Model name must contain at least 2 characters.'
    ], 422);
}

if (mb_strlen($modelName) > 150) {
    respond([
        'success' => false,
        'message' => 'Model name cannot exceed 150 characters.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Validate uploaded file
|--------------------------------------------------------------------------
*/

if (!isset($_FILES['package'])) {
    respond([
        'success' => false,
        'message' => 'Please select a model ZIP package.'
    ], 422);
}

$file = $_FILES['package'];

if (!is_array($file)) {
    respond([
        'success' => false,
        'message' => 'Invalid upload data.'
    ], 400);
}

$error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

if ($error !== UPLOAD_ERR_OK) {
    respond([
        'success' => false,
        'message' => uploadErrorMessage($error)
    ], 422);
}

$tmpPath = (string) ($file['tmp_name'] ?? '');
$originalName = (string) ($file['name'] ?? '');
$fileSize = (int) ($file['size'] ?? 0);

if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    respond([
        'success' => false,
        'message' => 'The uploaded file could not be verified.'
    ], 400);
}

if ($fileSize <= 0) {
    respond([
        'success' => false,
        'message' => 'The uploaded file is empty.'
    ], 422);
}

if ($fileSize > MAX_ARCHIVE_SIZE) {
    respond([
        'success' => false,
        'message' => 'The model package cannot exceed 512 MB.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Validate filename
|--------------------------------------------------------------------------
*/

$originalName = trim(
    basename(
        str_replace('\\', '/', $originalName)
    )
);

if ($originalName === '') {
    respond([
        'success' => false,
        'message' => 'Invalid uploaded filename.'
    ], 422);
}

if (mb_strlen($originalName) > 255) {
    respond([
        'success' => false,
        'message' => 'The filename is too long.'
    ], 422);
}

$extension = strtolower(
    pathinfo($originalName, PATHINFO_EXTENSION)
);

if ($extension !== 'zip') {
    respond([
        'success' => false,
        'message' => 'Only ZIP model packages are accepted.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Validate ZIP archive
|--------------------------------------------------------------------------
*/

$zipValidation = validateZipArchive(
    $tmpPath,
    $fileSize
);

if (($zipValidation['valid'] ?? false) !== true) {
    respond([
        'success' => false,
        'message' =>
            $zipValidation['message'] ??
            'The ZIP package failed validation.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Generate checksum
|--------------------------------------------------------------------------
*/

$checksum = hash_file('sha256', $tmpPath);

if ($checksum === false) {
    respond([
        'success' => false,
        'message' => 'Unable to calculate the model package checksum.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Prepare private storage
|--------------------------------------------------------------------------
*/

if (
    !is_dir(STORAGE_DIRECTORY) &&
    !mkdir(STORAGE_DIRECTORY, 0750, true)
) {
    respond([
        'success' => false,
        'message' => 'Unable to prepare private model storage.'
    ], 500);
}

if (!is_writable(STORAGE_DIRECTORY)) {
    respond([
        'success' => false,
        'message' => 'Private model storage is not writable.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Generate private storage key
|--------------------------------------------------------------------------
*/

$storageToken = bin2hex(random_bytes(24));

$storageKey = $storageToken . '.zip';

$destination =
    STORAGE_DIRECTORY .
    DIRECTORY_SEPARATOR .
    $storageKey;

/*
|--------------------------------------------------------------------------
| Move uploaded file into private storage
|--------------------------------------------------------------------------
*/

if (!move_uploaded_file($tmpPath, $destination)) {
    respond([
        'success' => false,
        'message' => 'Unable to store the uploaded model package.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Verify stored package integrity
|--------------------------------------------------------------------------
*/

$storedChecksum = hash_file('sha256', $destination);

if (
    $storedChecksum === false ||
    !hash_equals($checksum, $storedChecksum)
) {
    @unlink($destination);

    respond([
        'success' => false,
        'message' => 'The stored model package failed integrity verification.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Register model, version and file
|--------------------------------------------------------------------------
*/

$slug = generateUniqueSlug(
    $pdo,
    createSlug($modelName)
);

try {
    $pdo->beginTransaction();

    /*
     * Create model.
     */
    $modelStmt = $pdo->prepare(
        'INSERT INTO models
            (user_id, name, slug, status)
         VALUES (?, ?, ?, ?)'
    );

    $modelStmt->execute([
        $userId,
        $modelName,
        $slug,
        'uploaded'
    ]);

    $modelId = (int) $pdo->lastInsertId();

    /*
     * Create initial version.
     */
    $versionStmt = $pdo->prepare(
        'INSERT INTO model_versions
            (model_id, version, status)
         VALUES (?, ?, ?)'
    );

    $versionStmt->execute([
        $modelId,
        1,
        'uploaded'
    ]);

    $versionId = (int) $pdo->lastInsertId();

    /*
     * Register private file.
     */
    $fileStmt = $pdo->prepare(
        'INSERT INTO model_files
            (
                model_version_id,
                original_name,
                storage_key,
                file_size,
                checksum,
                mime_type,
                upload_status
            )
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $fileStmt->execute([
        $versionId,
        $originalName,
        $storageKey,
        $fileSize,
        $storedChecksum,
        'application/zip',
        'completed'
    ]);

    $fileId = (int) $pdo->lastInsertId();

    $pdo->commit();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (is_file($destination)) {
        @unlink($destination);
    }

    error_log(
        'Model upload registration failed: ' .
        $e->getMessage()
    );

    respond([
        'success' => false,
        'message' => 'The model package could not be registered.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Automatic Python package inspection
|--------------------------------------------------------------------------
|
| The upload transaction has already committed.
| An inspection failure will not delete the uploaded ZIP.
|
*/

try {
    $inspectionOutcome = inspectStoredModelPackage(
        $pdo,
        $modelId,
        $versionId,
        $storageKey
    );

} catch (Throwable $e) {

    error_log(
        'Model inspection integration failed: ' .
        $e->getMessage()
    );

    $inspectionOutcome = [
        'success' => false,
        'message' =>
            'The model was uploaded, but inspection could not be completed.',
        'retryable' => true
    ];
}

/*
|--------------------------------------------------------------------------
| Prepare final response
|--------------------------------------------------------------------------
*/

$inspectionSucceeded =
    ($inspectionOutcome['success'] ?? false) === true;

$inspectionResult =
    $inspectionOutcome['inspection'] ?? null;

$finalStatus = 'uploaded';

if (
    $inspectionSucceeded &&
    is_array($inspectionResult)
) {
    $statusMap = [
        'READY' => 'ready',
        'NEEDS_CONFIGURATION' => 'needs_configuration',
        'UNSUPPORTED' => 'unsupported'
    ];

    $finalStatus = $statusMap[
        $inspectionResult['status'] ?? ''
    ] ?? 'uploaded';
}

/*
|--------------------------------------------------------------------------
| Successful upload response
|--------------------------------------------------------------------------
*/

respond([
    'success' => true,

    'message' => $inspectionSucceeded
        ? 'Model uploaded and inspected successfully.'
        : 'Model uploaded successfully. Inspection requires attention.',

    'data' => [
        'model' => [
            'id' => $modelId,
            'name' => $modelName,
            'slug' => $slug,
            'status' => $finalStatus
        ],

        'version' => [
            'id' => $versionId,
            'version' => 1,
            'status' => $finalStatus
        ],

        'file' => [
            'id' => $fileId,
            'original_name' => $originalName,
            'file_size' => $fileSize,
            'checksum' => $storedChecksum,
            'upload_status' => 'completed'
        ],

        'package' => [
            'entries' =>
                (int) $zipValidation['entry_count'],

            'uncompressed_size' =>
                (int) $zipValidation['uncompressed_size']
        ],

        'inspection' => [
            'success' => $inspectionSucceeded,

            'status' => $inspectionResult['status'] ?? 'FAILED',

            'framework' => $inspectionResult['framework'] ?? null,

            'summary' => $inspectionResult['summary']
                ?? $inspectionOutcome['message']
                ?? null,

            'errors' => $inspectionResult['errors'] ?? [],

            'warnings' => $inspectionResult['warnings'] ?? [],

            'metadata' => $inspectionResult['metadata'] ?? null,

            'retryable' => !$inspectionSucceeded
        ]
    ]
], 201);
