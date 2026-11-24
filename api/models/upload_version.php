<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Upload New Model Version
|--------------------------------------------------------------------------
|
| Endpoint:
| POST /api/models/upload_version.php
|
| Required multipart fields:
| - model_id
| - package (ZIP file)
|
| Responsibilities:
| - Authenticate the user.
| - Verify model ownership.
| - Validate uploaded ZIP packages.
| - Assign the next version number safely.
| - Store the package privately.
| - Create model version and file records.
| - Run static package inspection.
|
| Uploaded model code is NEVER executed on the PHP host.
|
*/

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/inspection_service.php';

requireMethod('POST');

$userId = requireAuth();
requireCsrf();

/*
|--------------------------------------------------------------------------
| Upload limits
|--------------------------------------------------------------------------
*/

const VERSION_MAX_ARCHIVE_SIZE = 536870912; // 512 MB
const VERSION_MAX_ENTRIES = 5000;
const VERSION_MAX_UNCOMPRESSED_FILE_SIZE = 536870912; // 512 MB
const VERSION_MAX_TOTAL_UNCOMPRESSED_SIZE = 2147483648; // 2 GB
const VERSION_MAX_COMPRESSION_RATIO = 100;

const VERSION_STORAGE_DIRECTORY =
    __DIR__ . '/../storage/models';

/*
|--------------------------------------------------------------------------
| Error messages
|--------------------------------------------------------------------------
*/

function versionUploadErrorMessage(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE =>
            'The uploaded file exceeds the allowed upload size.',

        UPLOAD_ERR_PARTIAL =>
            'The file was only partially uploaded.',

        UPLOAD_ERR_NO_FILE =>
            'Please select a ZIP package.',

        UPLOAD_ERR_NO_TMP_DIR =>
            'The server temporary upload directory is missing.',

        UPLOAD_ERR_CANT_WRITE =>
            'The server could not write the uploaded file.',

        UPLOAD_ERR_EXTENSION =>
            'The upload was blocked by a PHP extension.',

        default =>
            'An unknown file upload error occurred.'
    };
}

/*
|--------------------------------------------------------------------------
| ZIP path validation
|--------------------------------------------------------------------------
*/

function versionIsDangerousPath(string $path): bool
{
    if ($path === '' || str_contains($path, "\0")) {
        return true;
    }

    /*
     * Normalize separators so Windows-style traversal
     * cannot bypass validation.
     */
    $path = str_replace('\\', '/', $path);

    /*
     * Reject absolute paths and Windows drive paths.
     */
    if (
        str_starts_with($path, '/') ||
        preg_match('/^[a-zA-Z]:/', $path)
    ) {
        return true;
    }

    /*
     * Reject traversal segments.
     */
    $segments = explode('/', $path);

    foreach ($segments as $segment) {
        if ($segment === '..') {
            return true;
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| ZIP symlink detection
|--------------------------------------------------------------------------
*/

function versionIsSymlinkEntry(
    ZipArchive $zip,
    int $index
): bool {
    $opsys = 0;
    $attributes = 0;

    if (
        !$zip->getExternalAttributesIndex(
            $index,
            $opsys,
            $attributes
        )
    ) {
        return false;
    }

    if ($opsys !== ZipArchive::OPSYS_UNIX) {
        return false;
    }

    /*
     * Extract the Unix file type from external attributes.
     */
    $fileType = ($attributes >> 16) & 0170000;

    return $fileType === 0120000;
}

/*
|--------------------------------------------------------------------------
| ZIP archive validation
|--------------------------------------------------------------------------
*/

function validateVersionZipArchive(string $zipPath): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException(
            'PHP ZIP extension is not available.'
        );
    }

    $zip = new ZipArchive();

    $openResult = $zip->open($zipPath);

    if ($openResult !== true) {
        throw new RuntimeException(
            'The uploaded file is not a valid ZIP archive.'
        );
    }

    try {
        $entryCount = $zip->numFiles;

        if ($entryCount < 1) {
            throw new RuntimeException(
                'The ZIP archive is empty.'
            );
        }

        if ($entryCount > VERSION_MAX_ENTRIES) {
            throw new RuntimeException(
                'The ZIP archive contains too many entries.'
            );
        }

        $totalUncompressedSize = 0;
        $seenPaths = [];

        for ($i = 0; $i < $entryCount; $i++) {
            $entry = $zip->statIndex($i);

            if ($entry === false) {
                throw new RuntimeException(
                    'The ZIP archive contains an invalid entry.'
                );
            }

            $entryName = $entry['name'] ?? '';

            if (
                !is_string($entryName) ||
                versionIsDangerousPath($entryName)
            ) {
                throw new RuntimeException(
                    'The ZIP archive contains an unsafe file path.'
                );
            }

            /*
             * Reject duplicate paths, including case variations.
             */
            $normalizedPath = strtolower(
                str_replace('\\', '/', $entryName)
            );

            if (isset($seenPaths[$normalizedPath])) {
                throw new RuntimeException(
                    'The ZIP archive contains duplicate file paths.'
                );
            }

            $seenPaths[$normalizedPath] = true;

            /*
             * Reject symbolic links.
             */
            if (versionIsSymlinkEntry($zip, $i)) {
                throw new RuntimeException(
                    'The ZIP archive contains a symbolic link.'
                );
            }

            /*
             * Reject encrypted entries when reported by the
             * available ZIP metadata.
             */
            if (
                isset($entry['encryption_method']) &&
                (int) $entry['encryption_method'] !== 0
            ) {
                throw new RuntimeException(
                    'Encrypted ZIP entries are not supported.'
                );
            }

            /*
             * Directories do not contribute to file-size limits.
             */
            if (str_ends_with($entryName, '/')) {
                continue;
            }

            $uncompressedSize = (int) ($entry['size'] ?? 0);
            $compressedSize = (int) ($entry['comp_size'] ?? 0);

            if ($uncompressedSize < 0 || $compressedSize < 0) {
                throw new RuntimeException(
                    'The ZIP archive contains invalid file sizes.'
                );
            }

            if (
                $uncompressedSize >
                VERSION_MAX_UNCOMPRESSED_FILE_SIZE
            ) {
                throw new RuntimeException(
                    'A file inside the ZIP archive exceeds the allowed size.'
                );
            }

            $totalUncompressedSize += $uncompressedSize;

            if (
                $totalUncompressedSize >
                VERSION_MAX_TOTAL_UNCOMPRESSED_SIZE
            ) {
                throw new RuntimeException(
                    'The ZIP archive exceeds the total uncompressed size limit.'
                );
            }

            /*
             * Protect against highly compressed archive bombs.
             */
            if ($uncompressedSize > 0) {
                if ($compressedSize === 0) {
                    throw new RuntimeException(
                        'The ZIP archive contains a suspicious compression ratio.'
                    );
                }

                $compressionRatio =
                    $uncompressedSize / $compressedSize;

                if (
                    $compressionRatio >
                    VERSION_MAX_COMPRESSION_RATIO
                ) {
                    throw new RuntimeException(
                        'The ZIP archive exceeds the allowed compression ratio.'
                    );
                }
            }
        }

        return [
            'entries' => $entryCount,
            'uncompressed_size' => $totalUncompressedSize
        ];

    } finally {
        $zip->close();
    }
}

/*
|--------------------------------------------------------------------------
| File MIME detection
|--------------------------------------------------------------------------
*/

function detectVersionPackageMimeType(string $filePath): string
{
    if (!class_exists(finfo::class)) {
        return 'application/zip';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mimeType = $finfo->file($filePath);

    if (!is_string($mimeType) || $mimeType === '') {
        return 'application/zip';
    }

    return $mimeType;
}

/*
|--------------------------------------------------------------------------
| Main upload operation
|--------------------------------------------------------------------------
*/

$storagePath = null;
$uploadCommitted = false;

try {
    /*
    |--------------------------------------------------------------------------
    | Validate model ID
    |--------------------------------------------------------------------------
    */

    $modelId = filter_var(
        $_POST['model_id'] ?? null,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($modelId === false) {
        respond([
            'success' => false,
            'message' => 'A valid model ID is required.'
        ], 422);
    }

    $modelId = (int) $modelId;

    /*
    |--------------------------------------------------------------------------
    | Validate uploaded file
    |--------------------------------------------------------------------------
    */

    if (!isset($_FILES['package'])) {
        respond([
            'success' => false,
            'message' => 'Please select a ZIP package.'
        ], 422);
    }

    $uploadedFile = $_FILES['package'];

    if (!is_array($uploadedFile)) {
        respond([
            'success' => false,
            'message' => 'Invalid uploaded file.'
        ], 422);
    }

    $uploadError = (int) (
        $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE
    );

    if ($uploadError !== UPLOAD_ERR_OK) {
        respond([
            'success' => false,
            'message' => versionUploadErrorMessage($uploadError)
        ], 422);
    }

    $temporaryPath = $uploadedFile['tmp_name'] ?? '';
    $originalName = basename(
        (string) ($uploadedFile['name'] ?? '')
    );

    $fileSize = (int) ($uploadedFile['size'] ?? 0);

    if (
        $temporaryPath === '' ||
        !is_uploaded_file($temporaryPath)
    ) {
        respond([
            'success' => false,
            'message' => 'The uploaded file could not be verified.'
        ], 422);
    }

    if ($fileSize < 1) {
        respond([
            'success' => false,
            'message' => 'The uploaded ZIP package is empty.'
        ], 422);
    }

    if ($fileSize > VERSION_MAX_ARCHIVE_SIZE) {
        respond([
            'success' => false,
            'message' => 'The ZIP package exceeds the 512 MB upload limit.'
        ], 413);
    }

    /*
     * Require ZIP extension.
     */
    $extension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );

    if ($extension !== 'zip') {
        respond([
            'success' => false,
            'message' => 'Only ZIP model packages are supported.'
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm model ownership
    |--------------------------------------------------------------------------
    */

    $ownerStmt = $pdo->prepare(
        'SELECT id, name, slug
         FROM models
         WHERE id = ?
           AND user_id = ?
         LIMIT 1'
    );

    $ownerStmt->execute([
        $modelId,
        $userId
    ]);

    $ownedModel = $ownerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ownedModel) {
        respond([
            'success' => false,
            'message' => 'Model not found or you do not have permission to upload a version.'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate ZIP archive
    |--------------------------------------------------------------------------
    |
    | Inspection is static only. No extraction, imports,
    | model loading, or execution of uploaded code occurs here.
    |
    */

    $archiveInformation = validateVersionZipArchive(
        $temporaryPath
    );

    /*
    |--------------------------------------------------------------------------
    | Calculate package checksum
    |--------------------------------------------------------------------------
    */

    $checksum = hash_file(
        'sha256',
        $temporaryPath
    );

    if (!is_string($checksum)) {
        throw new RuntimeException(
            'Unable to calculate the package checksum.'
        );
    }

    $mimeType = detectVersionPackageMimeType(
        $temporaryPath
    );

    /*
    |--------------------------------------------------------------------------
    | Validate private storage directory
    |--------------------------------------------------------------------------
    */

    if (
        !is_dir(VERSION_STORAGE_DIRECTORY) ||
        !is_writable(VERSION_STORAGE_DIRECTORY)
    ) {
        throw new RuntimeException(
            'The private model storage directory is unavailable.'
        );
    }

    /*
     * Generate an unpredictable storage key.
     */
    $storageKey = bin2hex(random_bytes(24)) . '.zip';

    $storagePath = VERSION_STORAGE_DIRECTORY
        . DIRECTORY_SEPARATOR
        . $storageKey;

    /*
    |--------------------------------------------------------------------------
    | Start database transaction
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    try {
        /*
         * Lock the parent model row.
         *
         * This serializes simultaneous version uploads
         * for the same model.
         */
        $lockStmt = $pdo->prepare(
            'SELECT id, name, slug
             FROM models
             WHERE id = ?
               AND user_id = ?
             FOR UPDATE'
        );

        $lockStmt->execute([
            $modelId,
            $userId
        ]);

        $model = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$model) {
            throw new RuntimeException(
                'Model not found or access denied.'
            );
        }

        /*
         * Assign the next version number while holding
         * the parent model lock.
         */
        $versionNumberStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(version), 0) + 1
             FROM model_versions
             WHERE model_id = ?'
        );

        $versionNumberStmt->execute([$modelId]);

        $versionNumber = (int) $versionNumberStmt->fetchColumn();

        if ($versionNumber < 2) {
            $versionNumber = 2;
        }

        /*
         * Move the uploaded ZIP into private storage.
         */
        if (!move_uploaded_file(
            $temporaryPath,
            $storagePath
        )) {
            throw new RuntimeException(
                'Unable to store the uploaded model package.'
            );
        }

        /*
         * Verify stored file checksum.
         */
        $storedChecksum = hash_file(
            'sha256',
            $storagePath
        );

        if (
            !is_string($storedChecksum) ||
            !hash_equals($checksum, $storedChecksum)
        ) {
            throw new RuntimeException(
                'Stored package checksum verification failed.'
            );
        }

        /*
         * Create model version.
         */
        $versionStmt = $pdo->prepare(
            'INSERT INTO model_versions
                (
                    model_id,
                    version,
                    status
                )
             VALUES (?, ?, ?)'
        );

        $versionStmt->execute([
            $modelId,
            $versionNumber,
            'uploaded'
        ]);

        $versionId = (int) $pdo->lastInsertId();

        /*
         * Create model file record.
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
            $checksum,
            $mimeType,
            'completed'
        ]);

        $fileId = (int) $pdo->lastInsertId();

        /*
         * A new latest version is now awaiting inspection.
         */
        $modelStatusStmt = $pdo->prepare(
            'UPDATE models
             SET status = ?
             WHERE id = ?
               AND user_id = ?'
        );

        $modelStatusStmt->execute([
            'uploaded',
            $modelId,
            $userId
        ]);

        /*
         * Commit model version and file records.
         */
        $pdo->commit();

        $uploadCommitted = true;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    /*
    |--------------------------------------------------------------------------
    | Run static package inspection
    |--------------------------------------------------------------------------
    |
    | The upload is already committed. Inspection failure
    | must not delete the uploaded package or its records.
    |
    */

    try {
        $inspectionResult = inspectStoredModelPackage(
            $pdo,
            $modelId,
            $versionId,
            $storageKey
        );

    } catch (Throwable $inspectionException) {

        error_log(
            'New model version inspection failed: ' .
            $inspectionException->getMessage()
        );

        $inspectionResult = [
            'success' => false,
            'message' => 'The package was uploaded, but inspection could not be completed.',
            'retryable' => true
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Return upload result
    |--------------------------------------------------------------------------
    */

    $versionStatusStmt = $pdo->prepare(
        'SELECT status
         FROM model_versions
         WHERE id = ?
           AND model_id = ?
         LIMIT 1'
    );

    $versionStatusStmt->execute([
        $versionId,
        $modelId
    ]);

    $finalVersionStatus = $versionStatusStmt->fetchColumn();

    $modelStatusStmt = $pdo->prepare(
        'SELECT status
         FROM models
         WHERE id = ?
           AND user_id = ?
         LIMIT 1'
    );

    $modelStatusStmt->execute([
        $modelId,
        $userId
    ]);

    $finalModelStatus = $modelStatusStmt->fetchColumn();

    $inspectionSucceeded = $inspectionResult['success'] ?? false;

    $response = [
        'success' => true,
        'message' => $inspectionSucceeded
            ? 'New model version uploaded and inspected successfully.'
            : 'New model version uploaded successfully, but inspection could not be completed.',
        'data' => [
            'model' => [
                'id' => $modelId,
                'name' => $model['name'],
                'slug' => $model['slug'],
                'status' => $finalModelStatus
            ],
            'version' => [
                'id' => $versionId,
                'version' => $versionNumber,
                'status' => $finalVersionStatus
            ],
            'file' => [
                'id' => $fileId,
                'original_name' => $originalName,
                'file_size' => $fileSize,
                'checksum' => $checksum,
                'mime_type' => $mimeType,
                'upload_status' => 'completed'
            ],
            'package' => [
                'entries' => $archiveInformation['entries'],
                'uncompressed_size' => $archiveInformation['uncompressed_size']
            ],
            'inspection' => $inspectionResult
        ]
    ];

    respond($response, 201);

} catch (Throwable $e) {

    /*
     * Remove stored ZIP only if the database transaction
     * did not successfully commit.
     */
    if (
        !$uploadCommitted &&
        is_string($storagePath) &&
        is_file($storagePath)
    ) {
        @unlink($storagePath);
    }

    error_log(
        'Model version upload failed: ' .
        $e->getMessage()
    );

    if ($e instanceof PDOException) {
        respond([
            'success' => false,
            'message' => 'A database error occurred while uploading the model version.'
        ], 500);
    }

    respond([
        'success' => false,
        'message' => $e->getMessage()
    ], 422);
}
