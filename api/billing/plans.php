<?php
declare(strict_types=1);

require_once __DIR__ . '/billing_common.php';

requireMethod('GET');

billing_require_user();

try {
    $stmt = $pdo->query("
        SELECT
            id,
            plan_code,
            name,
            description,
            price_monthly,
            currency,
            max_deployed_models,
            max_running_deployments,
            max_api_requests_monthly,
            max_storage_bytes,
            features,
            sort_order
        FROM billing_plans
        WHERE is_active = 1
        ORDER BY sort_order ASC, id ASC
    ");

    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($plans as &$plan) {
        $plan['id'] = (int) $plan['id'];
        $plan['price_monthly'] = $plan['price_monthly'] !== null
            ? (float) $plan['price_monthly']
            : null;

        $plan['max_deployed_models'] =
            $plan['max_deployed_models'] !== null
                ? (int) $plan['max_deployed_models']
                : null;

        $plan['max_running_deployments'] =
            $plan['max_running_deployments'] !== null
                ? (int) $plan['max_running_deployments']
                : null;

        $plan['max_api_requests_monthly'] =
            $plan['max_api_requests_monthly'] !== null
                ? (int) $plan['max_api_requests_monthly']
                : null;

        $plan['max_storage_bytes'] =
            $plan['max_storage_bytes'] !== null
                ? (int) $plan['max_storage_bytes']
                : null;

        $plan['features'] = billing_decode_features(
            $plan['features']
        );

        unset($plan['sort_order']);
    }

    unset($plan);

    respond([
        'success' => true,
        'data' => [
            'plans' => $plans
        ],
        'message' => 'Subscription plans retrieved successfully.'
    ]);

} catch (Throwable $e) {
    error_log('Billing plans error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'Unable to retrieve subscription plans.'
    ], 500);
}
