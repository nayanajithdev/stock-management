<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=expenses');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving expenses.');
    redirect('?page=expenses');
}

$expenseDate = trim((string) ($_POST['expense_date'] ?? date('Y-m-d')));
$category = trim((string) ($_POST['category'] ?? ''));
$vendor = nullable_string((string) ($_POST['vendor'] ?? ''));
$amount = max(0.0, input_decimal('amount'));
$paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
$referenceNo = nullable_string((string) ($_POST['reference_no'] ?? ''));
$notes = nullable_string((string) ($_POST['notes'] ?? ''));
$validPaymentMethods = ['cash', 'card', 'bank', 'cheque', 'online'];

try {
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
        throw new RuntimeException('Expense date is not valid.');
    }

    if ($category === '') {
        throw new RuntimeException('Expense category is required.');
    }

    if (strlen($category) > 80) {
        throw new RuntimeException('Expense category is too long.');
    }

    if ($amount <= 0.0) {
        throw new RuntimeException('Expense amount must be greater than zero.');
    }

    if (! in_array($paymentMethod, $validPaymentMethods, true)) {
        throw new RuntimeException('Choose a valid payment method.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO expenses
            (expense_date, category, vendor, amount, payment_method, reference_no, notes, status, created_by)
         VALUES
            (:expense_date, :category, :vendor, :amount, :payment_method, :reference_no, :notes, "active", :created_by)'
    );
    $statement->execute([
        'expense_date' => $expenseDate,
        'category' => $category,
        'vendor' => $vendor,
        'amount' => $amount,
        'payment_method' => $paymentMethod,
        'reference_no' => $referenceNo,
        'notes' => $notes,
        'created_by' => (int) ($currentUser['id'] ?? 0) ?: null,
    ]);
    $expenseId = (int) $pdo->lastInsertId();

    app_log_activity($pdo, $currentUser, 'expense_create', 'Recorded expense #' . $expenseId . ' ' . $category . ' for ' . format_money($amount) . '.');
    set_flash('success', 'Expense saved successfully.');
    redirect('?page=expenses');
} catch (RuntimeException $exception) {
    set_flash('error', $exception->getMessage());
    redirect('?page=expenses');
} catch (PDOException) {
    set_flash('error', 'Expense could not be saved.');
    redirect('?page=expenses');
}
