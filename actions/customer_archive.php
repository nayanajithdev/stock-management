<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=customers');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=customers');
}

$customerId = (int) ($_POST['customer_id'] ?? 0);

if ($customerId <= 0) {
    set_flash('error', 'Invalid customer selected.');
    redirect('?page=customers');
}

$statement = $pdo->prepare('UPDATE customers SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
$statement->execute(['id' => $customerId]);

app_log_activity($pdo, $currentUser, 'customer_archive', 'Archived customer ID ' . $customerId . '.');
set_flash('success', 'Customer archived. Sales history was kept.');
redirect('?page=customers');
