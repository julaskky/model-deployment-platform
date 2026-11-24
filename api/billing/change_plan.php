<?php
declare(strict_types=1);

require_once __DIR__ . '/billing_common.php';

requireMethod('POST');
requireCsrf();

$userId = billing_require_user();

$input = getJsonInput();

$planCode = trim((string) ($input['plan_code'] ?? ''));

if (
    $planCode === '' ||
    !preg_match('/^[a-z0-9_-]{1,50}$/', $planCode)
) {
    respond([
        'success' => false,
        'message' => 'A valid subscription plan must be selected.'
    ], 400);
}

try {
    $pdo->beginTransaction();

    /*
     * Lock the user's row to prevent concurrent plan requests.
     */
    $userLock = $pdo->prepare("
        SELECT id
        FROM users
        WHERE id = :user_id
          AND status = 'active'
        FOR UPDATE
    ");

    $userLock->execute([
        ':user_id' => $userId
    ]);

    if (!$userLock->fetchColumn()) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'Your account is unavailable.'
        ], 401);
    }

    /*
     * Find the selected active plan.
     */
    $planStmt = $pdo->prepare("
        SELECT
            id,
            plan_code,
            name,
            price_monthly,
            currency
        FROM billing_plans
        WHERE plan_code = :plan_code
          AND is_active = 1
        LIMIT 1
    ");

    $planStmt->execute([
        ':plan_code' => $planCode
    ]);

    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'The selected subscription plan is unavailable.'
        ], 404);
    }

    /*
     * Find current active subscription.
     */
    $activeStmt = $pdo->prepare("
        SELECT id, plan_id
        FROM user_subscriptions
        WHERE user_id = :user_id
          AND status IN ('active', 'trialing')
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");

    $activeStmt->execute([
        ':user_id' => $userId
    ]);

    $activeSubscription = $activeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeSubscription) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'No active subscription was found.'
        ], 404);
    }

    if ((int) $activeSubscription['plan_id'] === (int) $plan['id']) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'You are already subscribed to this plan.'
        ], 409);
    }

    /*
     * Check for an existing pending request.
     */
    $pendingStmt = $pdo->prepare("
        SELECT id, plan_id
        FROM user_subscriptions
        WHERE user_id = :user_id
          AND status = 'pending'
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");

    $pendingStmt->execute([
        ':user_id' => $userId
    ]);

    $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);

    if ($pending && (int) $pending['plan_id'] === (int) $plan['id']) {
        $pdo->rollBack();

        respond([
            'success' => false,
            'message' => 'This plan change is already awaiting approval.'
        ], 409);
    }

    /*
     * A pending subscription is only a request.
     * It does not grant paid-plan access.
     */
    $cycleStart = date('Y-m-d H:i:s');
    $cycleEnd = date(
        'Y-m-d H:i:s',
        strtotime('+1 month')
    );

    if ($pending) {
        $requestStmt = $pdo->prepare("
            UPDATE user_subscriptions
            SET
                plan_id = :plan_id,
                billing_cycle_start = :cycle_start,
                billing_cycle_end = :cycle_end,
                cancel_at_period_end = 0,
                canceled_at = NULL
            WHERE id = :subscription_id
              AND user_id = :user_id
              AND status = 'pending'
        ");

        $requestStmt->execute([
            ':plan_id' => (int) $plan['id'],
            ':cycle_start' => $cycleStart,
            ':cycle_end' => $cycleEnd,
            ':subscription_id' => (int) $pending['id'],
            ':user_id' => $userId
        ]);

        $requestId = (int) $pending['id'];

    } else {
        $requestStmt = $pdo->prepare("
            INSERT INTO user_subscriptions (
                user_id,
                plan_id,
                status,
                billing_cycle_start,
                billing_cycle_end
            )
            VALUES (
                :user_id,
                :plan_id,
                'pending',
                :cycle_start,
                :cycle_end
            )
        ");

        $requestStmt->execute([
            ':user_id' => $userId,
            ':plan_id' => (int) $plan['id'],
            ':cycle_start' => $cycleStart,
            ':cycle_end' => $cycleEnd
        ]);

        $requestId = (int) $pdo->lastInsertId();
    }

    $pdo->commit();

    respond([
        'success' => true,
        'data' => [
            'request_id' => $requestId,
            'plan_code' => $plan['plan_code'],
            'plan_name' => $plan['name'],
            'price_monthly' => $plan['price_monthly'] !== null
                ? (float) $plan['price_monthly']
                : null,
            'currency' => $plan['currency'],
            'status' => 'pending'
        ],
        'message' => 'Your plan change request has been recorded. Your current subscription remains active until the request is approved.'
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Billing plan change error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'Unable to process your subscription request.'
    ], 500);
}
