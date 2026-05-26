<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=credit-sales');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before collecting payments.');
    redirect('?page=credit-sales');
}

$saleId = (int) ($_POST['sale_id'] ?? 0);
$amount = max(0.0, input_decimal('amount'));
$paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
$paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d\TH:i')));
$notes = nullable_string((string) ($_POST['notes'] ?? ''));
$validPaymentMethods = ['cash', 'card', 'bank', 'cheque', 'online'];

if ($saleId <= 0 || $amount <= 0.0) {
    set_flash('error', 'Choose an invoice and enter a payment amount.');
    redirect('?page=credit-sales');
}

if (! in_array($paymentMethod, $validPaymentMethods, true)) {
    set_flash('error', 'Choose a valid payment method.');
    redirect('?page=credit-sales&collect=' . $saleId);
}

if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $paymentDate)) {
    set_flash('error', 'Payment date is not valid.');
    redirect('?page=credit-sales&collect=' . $saleId);
}

try {
    $pdo->beginTransaction();

    $saleStatement = $pdo->prepare(
        'SELECT id, customer_id, invoice_no, total, paid
         FROM sales
         WHERE id = :id
         FOR UPDATE'
    );
    $saleStatement->execute(['id' => $saleId]);
    $sale = $saleStatement->fetch();

    if (! is_array($sale)) {
        throw new RuntimeException('Selected invoice was not found.');
    }

    $balance = (float) $sale['total'] - (float) $sale['paid'];

    if ($balance <= 0.0) {
        throw new RuntimeException('This invoice is already fully paid.');
    }

    if ($amount > $balance) {
        throw new RuntimeException('Payment cannot be higher than the invoice balance.');
    }

    $newPaid = (float) $sale['paid'] + $amount;
    $newStatus = $newPaid >= (float) $sale['total'] ? 'paid' : 'partial';

    $paymentStatement = $pdo->prepare(
        'INSERT INTO customer_payments
            (sale_id, customer_id, payment_date, amount, payment_method, notes)
         VALUES
            (:sale_id, :customer_id, :payment_date, :amount, :payment_method, :notes)'
    );
    $paymentStatement->execute([
        'sale_id' => $saleId,
        'customer_id' => $sale['customer_id'],
        'payment_date' => str_replace('T', ' ', $paymentDate) . ':00',
        'amount' => $amount,
        'payment_method' => $paymentMethod,
        'notes' => $notes,
    ]);
    $paymentId = (int) $pdo->lastInsertId();

    $updateStatement = $pdo->prepare(
        'UPDATE sales
         SET paid = :paid,
             status = :status,
             payment_method = :payment_method,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $updateStatement->execute([
        'paid' => $newPaid,
        'status' => $newStatus,
        'payment_method' => $paymentMethod,
        'id' => $saleId,
    ]);

    $pdo->commit();

    app_log_activity($pdo, $currentUser, 'payment_collect', 'Collected ' . format_money($amount) . ' for invoice ' . $sale['invoice_no'] . '.');
    set_flash('success', 'Payment collected for invoice ' . $sale['invoice_no'] . '.');
    redirect('?page=payment-receipt&id=' . $paymentId);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Payment could not be collected.');
    redirect('?page=credit-sales' . ($saleId > 0 ? '&collect=' . $saleId : ''));
}
