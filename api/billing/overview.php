<?php
declare(strict_types=1);

require_once __DIR__ . '/billing_common.php';

requireMethod('GET');

$userId = billing_require_user();

try {
    /*
     * Current active subscription.
     */
    $subscriptionStmt = $pdo->prepare("
        SELECT
            s.id,
            s.status,
            s.billing_cycle_start,
            s.billing_cycle_end,
            s.cancel_at_period_end,
            s.canceled_at,
            p.id AS plan_id,
            p.plan_code,
            p.name AS plan_name,
            p.description AS plan_description,
            p.price_monthly,
            p.currency,
            p.max_deployed_models,
            p.max_running_deployments,
            p.max_api_requests_monthly,
            p.max_storage_bytes,
            p.features
        FROM user_subscriptions s
        INNER JOIN billing_plans p
            ON p.id = s.plan_id
        WHERE s.user_id = :user_id
          AND s.status IN ('active', 'trialing')
        ORDER BY s.id DESC
        LIMIT 1
    ");

    $subscriptionStmt->execute([
        ':user_id' => $userId
    ]);

    $subscription = $subscriptionStmt->fetch(PDO::FETCH_ASSOC);

    if (!$subscription) {
        respond([
            'success' => false,
            'message' => 'No active subscription was found for this account.'
        ], 404);
    }

    /*
     * Pending subscription request.
     */
    $pendingStmt = $pdo->prepare("
        SELECT
            s.id,
            s.status,
            s.created_at,
            p.plan_code,
            p.name AS plan_name,
            p.price_monthly,
            p.currency
        FROM user_subscriptions s
        INNER JOIN billing_plans p
            ON p.id = s.plan_id
        WHERE s.user_id = :user_id
          AND s.status = 'pending'
        ORDER BY s.id DESC
        LIMIT 1
    ");

    $pendingStmt->execute([
        ':user_id' => $userId
    ]);

    $pendingSubscription = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$pendingSubscription) { $pendingSubscription = null; }
     if ($pendingSubscription) {
        $pendingSubscription['id'] =
            (int) $pendingSubscription['id'];

        $pendingSubscription['price_monthly'] =
            $pendingSubscription['price_monthly'] !== null
                ? (float) $pendingSubscription['price_monthly']
                : null;
    }

    /*
     * Billing cycle start.
     */
    $cycleStart = $subscription['billing_cycle_start'];

    /*
     * Model deployment usage.
     *
     * A model counts as deployed when it has at least one
     * registered runtime, regardless of the number of versions.
     */
    $deployedModelsStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT m.id)
        FROM models m
        INNER JOIN model_versions mv
            ON mv.model_id = m.id
        INNER JOIN model_runtimes mr
            ON mr.model_version_id = mv.id
        WHERE m.user_id = :user_id
    ");

    $deployedModelsStmt->execute([
        ':user_id' => $userId
    ]);

    $deployedModels = (int) $deployedModelsStmt->fetchColumn();

    /*
     * Currently running deployments.
     */
    $runningStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM model_runtimes mr
        INNER JOIN model_versions mv
            ON mv.id = mr.model_version_id
        INNER JOIN models m
            ON m.id = mv.model_id
        WHERE m.user_id = :user_id
          AND mr.status = 'running'
    ");

    $runningStmt->execute([
        ':user_id' => $userId
    ]);

    $runningDeployments = (int) $runningStmt->fetchColumn();

    /*
     * Uploaded model storage.
     */
    $storageStmt = $pdo->prepare("
        SELECT COALESCE(SUM(mf.file_size), 0)
        FROM model_files mf
        INNER JOIN model_versions mv
            ON mv.id = mf.model_version_id
        INNER JOIN models m
            ON m.id = mv.model_id
        WHERE m.user_id = :user_id
          AND mf.upload_status = 'completed'
    ");

    $storageStmt->execute([
        ':user_id' => $userId
    ]);

    $storageBytes = (int) $storageStmt->fetchColumn();

    /*
     * API requests during the current billing cycle.
     */
    $apiUsageStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_requests,
            COALESCE(SUM(success = 1), 0) AS successful_requests,
            COALESCE(SUM(success = 0), 0) AS failed_requests
        FROM api_request_logs
        WHERE user_id = :user_id
          AND created_at >= :cycle_start
          AND created_at < :cycle_end
    ");

    $apiUsageStmt->execute([
        ':user_id' => $userId,
        ':cycle_start' => $subscription['billing_cycle_start'],
        ':cycle_end' => $subscription['billing_cycle_end']
    ]);

    $apiUsage = $apiUsageStmt->fetch(PDO::FETCH_ASSOC);

    $totalRequests = (int) ($apiUsage['total_requests'] ?? 0);
    $successfulRequests = (int) ($apiUsage['successful_requests'] ?? 0);
    $failedRequests = (int) ($apiUsage['failed_requests'] ?? 0);

    /*
     * Plan limits.
     */
    $maxModels = $subscription['max_deployed_models'] !== null
        ? (int) $subscription['max_deployed_models']
        : null;

    $maxRunning = $subscription['max_running_deployments'] !== null
        ? (int) $subscription['max_running_deployments']
        : null;

    $maxRequests = $subscription['max_api_requests_monthly'] !== null
        ? (int) $subscription['max_api_requests_monthly']
        : null;

    $maxStorage = $subscription['max_storage_bytes'] !== null
        ? (int) $subscription['max_storage_bytes']
        : null;

    /*
     * Usage percentage helper.
     */
    $percentage = static function (
        int $used,
        ?int $limit
    ): ?float {
        if ($limit === null || $limit < 1) {
            return null;
        }

        return round(min(100, ($used / $limit) * 100), 2);
    };

    $subscription['id'] = (int) $subscription['id'];
    $subscription['plan_id'] = (int) $subscription['plan_id'];

    $subscription['price_monthly'] =
        $subscription['price_monthly'] !== null
            ? (float) $subscription['price_monthly']
            : null;

    $subscription['cancel_at_period_end'] =
        (bool) $subscription['cancel_at_period_end'];

    $subscription['features'] = billing_decode_features(
        $subscription['features']
    );

    unset($subscription['max_deployed_models']);
    unset($subscription['max_running_deployments']);
    unset($subscription['max_api_requests_monthly']);
    unset($subscription['max_storage_bytes']);

    $usage = [
        'deployed_models' => [
            'used' => $deployedModels,
            'limit' => $maxModels,
            'percentage' => $percentage($deployedModels, $maxModels)
        ],

        'running_deployments' => [
            'used' => $runningDeployments,
            'limit' => $maxRunning,
            'percentage' => $percentage($runningDeployments, $maxRunning)
        ],

        'api_requests' => [
            'used' => $totalRequests,
            'successful' => $successfulRequests,
            'failed' => $failedRequests,
            'limit' => $maxRequests,
            'percentage' => $percentage($totalRequests, $maxRequests)
        ],

        'storage' => [
            'used_bytes' => $storageBytes,
            'limit_bytes' => $maxStorage,
            'percentage' => $percentage($storageBytes, $maxStorage)
        ]
    ];

    respond([
        'success' => true,
        'data' => [
            'subscription' => $subscription,
            'pending_subscription' => $pendingSubscription,
            'usage' => $usage,
            'billing_period' => [
                'start' => $subscription['billing_cycle_start'],
                'end' => $subscription['billing_cycle_end']
            ]
        ],
        'message' => 'Billing overview retrieved successfully.'
    ]);

} catch (Throwable $e) {
    error_log('Billing overview error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'Unable to retrieve billing information.'
    ], 500);
}
