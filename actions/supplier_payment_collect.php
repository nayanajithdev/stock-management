<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=supplier-credit');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before collecting supplier payments.');
    redirect('?page=supplier-credit');
}

if (! auth_can_view_product_cost($pdo, $currentUser ?? null)) {
    set_flash('error', 'Product Cost permission is required to record supplier payments.');
    redirect('?page=dashboard');
}

$purchaseId = (int) ($_POST['purchase_id'] ?? 0);
$amount = max(0.0, input_decimal('amount'));
$paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
$paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d\TH:i')));
$notes = nullable_string((string) ($_POST['notes'] ?? ''));
$validPaymentMethods = ['cash', 'card', 'bank', 'cheque', 'online'];

if ($purchaseId <= 0 || $amount <= 0.0) {
    set_flash('error', 'Choose a purchase and enter a payment amount.');
    redirect('?page=supplier-credit');
}

if (! in_array($paymentMethod, $validPaymentMethods, true)) {
    set_flash('error', 'Choose a valid payment method.');
    redirect('?page=supplier-credit&collect=' . $purchaseId);
}

if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $paymentDate)) {
    set_flash('error', 'Payment date is not valid.');
    redirect('?page=supplier-credit&collect=' . $purchaseId);
}

try {
    $pdo->beginTransaction();

    $purchaseStatement = $pdo->prepare(
        'SELECT p.id,
                p.supplier_id,
                p.invoice_no,
                p.total,
                p.paid,
                s.name AS supplier_name
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         WHERE p.id = :id
         FOR UPDATE'
    );
    $purchaseStatement->execute(['id' => $purchaseId]);
    $purchase = $purchaseStatement->fetch();

    if (! is_array($purchase)) {
        throw new RuntimeException('Selected purchase was not found.');
    }

    $balance = (float) $purchase['total'] - (float) $purchase['paid'];

    if ($balance <= 0.0) {
        throw new RuntimeException('This purchase is already fully paid.');
    }

    if ($amount > $balance) {
        throw new RuntimeException('Payment cannot be higher than the purchase balance.');
    }

    $newPaid = (float) $purchase['paid'] + $amount;
    $newStatus = $newPaid >= (float) $purchase['total'] ? 'paid' : 'partial';

    $paymentStatement = $pdo->prepare(
        'INSERT INTO supplier_payments
            (purchase_id, supplier_id, payment_date, amount, payment_method, notes)
         VALUES
            (:purchase_id, :supplier_id, :payment_date, :amount, :payment_method, :notes)'
    );
    $paymentStatement->execute([
        'purchase_id' => $purchaseId,
        'supplier_id' => $purchase['supplier_id'],
        'payment_date' => str_replace('T', ' ', $paymentDate) . ':00',
        'amount' => $amount,
        'payment_method' => $paymentMethod,
        'notes' => $notes,
    ]);

    $updateStatement = $pdo->prepare(
        'UPDATE purchases
         SET paid = :paid,
             status = :status,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $updateStatement->execute([
        'paid' => $newPaid,
        'status' => $newStatus,
        'id' => $purchaseId,
    ]);

    $pdo->commit();

    app_log_activity(
        $pdo,
        $currentUser,
        'supplier_payment_collect',
        'Paid ' . format_money($amount) . ' to ' . ($purchase['supplier_name'] ?: 'supplier') . ' for purchase ' . ($purchase['invoice_no'] ?: '#' . $purchaseId) . '.'
    );
    set_flash('success', 'Supplier payment saved for purchase ' . ($purchase['invoice_no'] ?: '#' . $purchaseId) . '.');
    redirect('?page=supplier-credit');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Supplier payment could not be saved.');
    redirect('?page=supplier-credit' . ($purchaseId > 0 ? '&collect=' . $purchaseId : ''));
}
