<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

requireMethod('POST');
requireCsrf();

$data = getJsonInput();

$name = trim($data['name'] ?? '');
$email = strtolower(trim($data['email'] ?? ''));
$password = $data['password'] ?? '';

if ($name === '' || strlen($name) > 150) {
    respond([
        'success' => false,
        'message' => 'Enter a valid name.'
    ], 422);
}

if (
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    strlen($email) > 190
) {
    respond([
        'success' => false,
        'message' => 'Enter a valid email address.'
    ], 422);
}

if (
    !is_string($password) ||
    strlen($password) < 12 ||
    strlen($password) > 72
) {
    respond([
        'success' => false,
        'message' => 'Password must contain 12–72 characters.'
    ], 422);
}

try {
    $pdo->beginTransaction();

    // 1. Find the active Free plan.
    $planStmt = $pdo->prepare(
        "SELECT id
         FROM billing_plans
         WHERE plan_code = 'free'
           AND is_active = 1
         LIMIT 1"
    );

    $planStmt->execute();
    $freePlan = $planStmt->fetch(PDO::FETCH_ASSOC);

    if (!$freePlan) {
        throw new RuntimeException(
            'The active Free subscription plan was not found.'
        );
    }

    $freePlanId = (int) $freePlan['id'];

    // 2. Create the user account.
    $userStmt = $pdo->prepare(
        "INSERT INTO users
            (name, email, password_hash, status)
         VALUES
            (?, ?, ?, 'active')"
    );

    $userStmt->execute([
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT)
    ]);

    $userId = (int) $pdo->lastInsertId();

    // 3. Assign the Free subscription.
    $subscriptionStmt = $pdo->prepare(
        "INSERT INTO user_subscriptions (
            user_id,
            plan_id,
            status,
            billing_cycle_start,
            billing_cycle_end,
            cancel_at_period_end
        ) VALUES (
            ?,
            ?,
            'active',
            NOW(),
            DATE_ADD(NOW(), INTERVAL 1 MONTH),
            0
        )"
    );

    $subscriptionStmt->execute([
        $userId,
        $freePlanId
    ]);

    // 4. Commit both records together.
    $pdo->commit();

    // 5. Establish the user's authenticated session.
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    respond([
        'success' => true,
        'message' => 'Account created successfully.',
        'user' => [
            'id' => $userId,
            'name' => $name,
            'email' => $email
        ],
        'subscription' => [
            'plan' => 'Free',
            'status' => 'active'
        ],
        'csrf_token' => $_SESSION['csrf_token']
    ], 201);

} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e->getCode() === '23000') {
        respond([
            'success' => false,
            'message' => 'An account with this email already exists.'
        ], 409);
    }

    error_log('Registration database error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'Account registration failed.'
    ], 500);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Registration error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'Account registration could not be completed. Please contact support if the problem persists.'
    ], 500);
}
