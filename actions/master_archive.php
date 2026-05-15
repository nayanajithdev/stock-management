<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=inventory-setup');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=inventory-setup');
}

$entity = (string) ($_POST['entity'] ?? '');
$id = (int) ($_POST['id'] ?? 0);
$entityConfig = [
    'category' => ['table' => 'categories', 'label' => 'Category', 'redirect' => 'categories'],
    'brand' => ['table' => 'brands', 'label' => 'Brand', 'redirect' => 'brands'],
    'supplier' => ['table' => 'suppliers', 'label' => 'Supplier', 'redirect' => 'suppliers'],
];

if ($id <= 0 || ! isset($entityConfig[$entity])) {
    set_flash('error', 'Invalid setup record selected.');
    redirect('?page=inventory-setup');
}

$table = $entityConfig[$entity]['table'];
$label = $entityConfig[$entity]['label'];
$redirectSection = $entityConfig[$entity]['redirect'];

$statement = $pdo->prepare("UPDATE {$table} SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
$statement->execute(['id' => $id]);

app_log_activity($pdo, $currentUser, strtolower($label) . '_archive', 'Archived ' . strtolower($label) . ' ID ' . $id . '.');
set_flash('success', $label . ' archived successfully.');
redirect('?page=inventory-setup&section=' . $redirectSection);
