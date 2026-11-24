
<?php
require_once __DIR__ . '/../bootstrap.php';

requireMethod('GET');

$userId = requireAuth();

// Verify that the authenticated account is still active.
$userStmt = $pdo->prepare("
    SELECT id, name, email, status, created_at
    FROM users
    WHERE id = :user_id AND status = 'active'
    LIMIT 1
");
$userStmt->execute(['user_id' => $userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    respond([
        'success' => false,
        'message' => 'Your account is unavailable. Please sign in again.'
    ], 401);
}

// Model statistics belonging only to this user.
$statsStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_models,
        SUM(status = 'ready') AS ready_models,
        SUM(status = 'deployed') AS deployed_models,
        SUM(status = 'needs_configuration') AS needs_configuration,
        SUM(status = 'unsupported') AS unsupported_models,
        SUM(status IN ('pending_upload', 'uploaded', 'analyzing')) AS processing_models
    FROM models
    WHERE user_id = :user_id
");
$statsStmt->execute(['user_id' => $userId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Calculate uploaded storage without relying on deployment infrastructure.
$storageStmt = $pdo->prepare("
    SELECT COALESCE(SUM(mf.file_size), 0) AS storage_bytes
    FROM model_files mf
    INNER JOIN model_versions mv
        ON mv.id = mf.model_version_id
    INNER JOIN models m
        ON m.id = mv.model_id
    WHERE m.user_id = :user_id
      AND mf.upload_status = 'completed'
");
$storageStmt->execute(['user_id' => $userId]);
$storage = $storageStmt->fetch(PDO::FETCH_ASSOC);

// Latest models and their latest versions.
$recentStmt = $pdo->prepare("
    SELECT
        m.id,
        m.name,
        m.slug,
        m.status,
        m.created_at,
        m.updated_at,
        COALESCE(latest_version.version, 1) AS version,
        latest_file.original_name,
        latest_file.file_size,
        latest_file.upload_status
    FROM models m
    LEFT JOIN (
        SELECT model_id, MAX(version) AS version
        FROM model_versions
        GROUP BY model_id
    ) latest_version
        ON latest_version.model_id = m.id
    LEFT JOIN model_versions mv
        ON mv.model_id = m.id
       AND mv.version = latest_version.version
    LEFT JOIN model_files latest_file
        ON latest_file.model_version_id = mv.id
    WHERE m.user_id = :user_id
    ORDER BY m.updated_at DESC, m.id DESC
    LIMIT 8
");
$recentStmt->execute(['user_id' => $userId]);
$recentModels = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Normalize numerical values for predictable JSON responses.
$stats = [
    'total_models' => (int) ($stats['total_models'] ?? 0),
    'ready_models' => (int) ($stats['ready_models'] ?? 0),
    'deployed_models' => (int) ($stats['deployed_models'] ?? 0),
    'needs_configuration' => (int) ($stats['needs_configuration'] ?? 0),
    'unsupported_models' => (int) ($stats['unsupported_models'] ?? 0),
    'processing_models' => (int) ($stats['processing_models'] ?? 0),
    'storage_bytes' => (int) ($storage['storage_bytes'] ?? 0),
];

// Return a consistent response for the React dashboard.
respond([
    'success' => true,
    'user' => $user,
    'stats' => $stats,
    'recent_models' => $recentModels,
    'capabilities' => [
        'deployments' => false,
        'api_requests' => false,
        'api_keys' => false,
        'billing' => false
    ]
]);
