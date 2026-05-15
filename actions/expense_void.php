<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=expenses');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=expenses');
}

$expenseId = (int) ($_POST['expense_id'] ?? 0);

if ($expenseId <= 0) {
    set_flash('error', 'Invalid expense selected.');
    redirect('?page=expenses');
}

$statement = $pdo->prepare(
    'UPDATE expenses
     SET status = "voided",
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :id
       AND status = "active"'
);
$statement->execute(['id' => $expenseId]);

if ($statement->rowCount() === 0) {
    set_flash('error', 'Expense was not found or already voided.');
    redirect('?page=expenses');
}

app_log_activity($pdo, $currentUser, 'expense_void', 'Voided expense ID ' . $expenseId . '.');
set_flash('success', 'Expense voided. Audit history was kept.');
redirect('?page=expenses');
