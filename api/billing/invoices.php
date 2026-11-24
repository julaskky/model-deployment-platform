<?php
declare(strict_types=1);

require_once __DIR__ . '/billing_common.php';

requireMethod('GET');

$userId = billing_require_user();

try {
    $pagination = billing_pagination();

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM billing_invoices
        WHERE user_id = :user_id
    ");

    $countStmt->execute([
        ':user_id' => $userId
    ]);

    $totalRecords = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.invoice_number,
            i.subscription_id,
            i.status,
            i.currency,
            i.subtotal,
            i.tax_amount,
            i.total_amount,
            i.billing_period_start,
            i.billing_period_end,
            i.issued_at,
            i.due_at,
            i.paid_at,
            i.created_at,
            p.name AS plan_name
        FROM billing_invoices i
        LEFT JOIN user_subscriptions s
            ON s.id = i.subscription_id
        LEFT JOIN billing_plans p
            ON p.id = s.plan_id
        WHERE i.user_id = :user_id
        ORDER BY i.created_at DESC, i.id DESC
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

    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($invoices as &$invoice) {
        $invoice['id'] = (int) $invoice['id'];

        $invoice['subscription_id'] =
            $invoice['subscription_id'] !== null
                ? (int) $invoice['subscription_id']
                : null;

        $invoice['subtotal'] = (float) $invoice['subtotal'];
        $invoice['tax_amount'] = (float) $invoice['tax_amount'];
        $invoice['total_amount'] = (float) $invoice['total_amount'];
    }

    unset($invoice);

    respond([
        'success' => true,
        'data' => [
            'invoices' => $invoices,
            'pagination' => [
                'page' => $pagination['page'],
                'limit' => $pagination['limit'],
                'total_records' => $totalRecords,
                'total_pages' => (int) ceil(
                    $totalRecords / $pagination['limit']
                )
            ]
        ],
        'message' => 'Invoice history retrieved successfully.'
    ]);

} catch (Throwable $e) {
    error_log('Billing invoices error: ' . $e->getMessage());

    respond([
        'success' => false,
        'message' => 'Unable to retrieve invoice history.'
    ], 500);
}
