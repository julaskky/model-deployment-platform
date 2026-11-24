<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$userId = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    requireMethod('GET');

    $stmt = $pdo->prepare("
        SELECT
            default_api_version,
            request_timeout_seconds,
            notify_usage,
            notify_errors
        FROM user_api_preferences
        WHERE user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([':user_id' => $userId]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$preferences) {
        $preferences = [
            'default_api_version' => 'v1',
            'request_timeout_seconds' => 30,
            'notify_usage' => 1,
            'notify_errors' => 1,
        ];
    }

    $preferences['request_timeout_seconds'] =
        (int) $preferences['request_timeout_seconds'];

    $preferences['notify_usage'] =
        (bool) $preferences['notify_usage'];

    $preferences['notify_errors'] =
        (bool) $preferences['notify_errors'];

    respond([
        'success' => true,
        'data' => [
            'preferences' => $preferences
        ]
    ]);
}

requireMethod('POST');
requireCsrf();

$input = getJsonInput();

$apiVersion = trim((string) ($input['default_api_version'] ?? 'v1'));
$timeout = (int) ($input['request_timeout_seconds'] ?? 30);
$notifyUsage = $input['notify_usage'] ?? true;
$notifyErrors = $input['notify_errors'] ?? true;

if ($apiVersion !== 'v1') {
    respond([
        'success' => false,
        'message' => 'The selected API version is not supported.'
    ], 422);
}

if (!in_array($timeout, [10, 30, 60, 120], true)) {
    respond([
        'success' => false,
        'message' => 'Please select a valid request timeout.'
    ], 422);
}

if (!is_bool($notifyUsage) || !is_bool($notifyErrors)) {
    respond([
        'success' => false,
        'message' => 'Notification preferences must be enabled or disabled.'
    ], 422);
}

$stmt = $pdo->prepare("
    INSERT INTO user_api_preferences (
        user_id,
        default_api_version,
        request_timeout_seconds,
        notify_usage,
        notify_errors
    )
    VALUES (
        :user_id,
        :api_version,
        :timeout,
        :notify_usage,
        :notify_errors
    )
    ON DUPLICATE KEY UPDATE
        default_api_version = VALUES(default_api_version),
        request_timeout_seconds = VALUES(request_timeout_seconds),
        notify_usage = VALUES(notify_usage),
        notify_errors = VALUES(notify_errors)
");

$stmt->execute([
    ':user_id' => $userId,
    ':api_version' => $apiVersion,
    ':timeout' => $timeout,
    ':notify_usage' => (int) $notifyUsage,
    ':notify_errors' => (int) $notifyErrors,
]);

respond([
    'success' => true,
    'message' => 'API preferences saved successfully.',
    'data' => [
        'preferences' => [
            'default_api_version' => $apiVersion,
            'request_timeout_seconds' => $timeout,
            'notify_usage' => $notifyUsage,
            'notify_errors' => $notifyErrors,
        ]
    ]
]);