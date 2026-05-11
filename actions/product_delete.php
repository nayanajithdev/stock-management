<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=products');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=products');
}

$productId = (int) ($_POST['product_id'] ?? 0);

if ($productId <= 0) {
    set_flash('error', 'Invalid product selected.');
    redirect('?page=products');
}

$statement = $pdo->prepare('UPDATE products SET status = "inactive", updated_at = CURRENT_TIMESTAMP WHERE id = :id');
$statement->execute(['id' => $productId]);

set_flash('success', 'Product archived. Existing stock history was kept.');
redirect('?page=products');
