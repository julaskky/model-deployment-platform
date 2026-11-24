<?php
declare(strict_types=1);

require_once __DIR__ . '/billing_common.php';

requireMethod('GET');

$userId = billing_require_user();

try {
    $pagination = billing_pagination();

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM billing_payments
        WHERE user_id = :user_id
    ");

    $countStmt->execute([
        ':user_id' => $userId
    ]);

    $totalRecords = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.invoice_id,
            i.invoice_number,
            p.payment_provider,
            p.provider_transaction_id,
            p.amount,
            p.currency,
            p.status,
            p.payment_method,
            p.paid_at,
            p.created_at
        FROM billing_payments p
        INNER JOIN billing_invoices i
            ON i.id = p.invoice_id
        WHERE p.user_id = :user_id
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT :limit OFFSET :offset
    ");

    $stmt->bindValue(
        ':user_id',
        $userId,
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        ':limit',
        $pagination['limit'],
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        ':offset',
        $pagination['offset'],
        PDO::PARAM_INT
    );

    $stmt->execute();

    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($payments as &$payment) {
        $payment['id'] = (int) $payment['id'];
        $payment['invoice_id'] = (int) $payment['invoice_id'];
        $payment['amount'] = (float) $payment['amount'];
    }

    unset($payment);

    respond([
        'success' => true,
        'data' => [
            'payments' => $payments,
            'pagination' => [
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'total_records' => $totalRecords,
                'total_pages' => (int) ceil(
                    $totalRecords / $pagination['limit']
                )
            ]
        ],
        'message' => 'Payment history retrieved successfully.'
    ]);

} catch (Throwable $e) {
    error_log('Billing payments error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'Unable to retrieve payment history.'
    ], 500);
}
