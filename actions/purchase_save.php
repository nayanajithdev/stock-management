<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=purchases');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving purchases.');
    redirect('?page=purchases');
}

$supplierId = ($_POST['supplier_id'] ?? '') !== '' ? (int) $_POST['supplier_id'] : null;
$invoiceNo = nullable_string((string) ($_POST['invoice_no'] ?? ''));
$purchaseDate = trim((string) ($_POST['purchase_date'] ?? date('Y-m-d')));
$discount = max(0.0, input_decimal('discount'));
$paid = max(0.0, input_decimal('paid'));
$productIds = $_POST['product_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unitCosts = $_POST['unit_cost'] ?? [];

if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchaseDate)) {
    set_flash('error', 'Purchase date is not valid.');
    redirect('?page=purchases');
}

if (! is_array($productIds) || ! is_array($quantities) || ! is_array($unitCosts)) {
    set_flash('error', 'Purchase items are not valid.');
    redirect('?page=purchases');
}

$items = [];

foreach ($productIds as $index => $rawProductId) {
    $productId = (int) $rawProductId;
    $quantity = max(0, (int) ($quantities[$index] ?? 0));
    $unitCost = str_replace(',', '', trim((string) ($unitCosts[$index] ?? '0')));
    $unitCost = is_numeric($unitCost) ? max(0.0, (float) $unitCost) : 0.0;

    if ($productId <= 0 && $quantity === 0 && $unitCost <= 0) {
        continue;
    }

    if ($productId <= 0 || $quantity <= 0 || $unitCost <= 0) {
        set_flash('error', 'Each purchase line needs a product, quantity, and unit cost.');
        redirect('?page=purchases');
    }

    $items[] = [
        'product_id' => $productId,
        'quantity' => $quantity,
        'unit_cost' => $unitCost,
    ];
}

if ($items === []) {
    set_flash('error', 'Add at least one purchase item.');
    redirect('?page=purchases');
}

$subtotal = 0.0;

foreach ($items as $item) {
    $subtotal += $item['quantity'] * $item['unit_cost'];
}

if ($discount > $subtotal) {
    set_flash('error', 'Discount cannot be higher than subtotal.');
    redirect('?page=purchases');
}

$total = $subtotal - $discount;

if ($paid > $total) {
    set_flash('error', 'Paid amount cannot be higher than purchase total.');
    redirect('?page=purchases');
}

$status = 'paid';
if ($paid <= 0.0) {
    $status = 'credit';
} elseif ($paid < $total) {
    $status = 'partial';
}

try {
    $pdo->beginTransaction();

    $purchaseStatement = $pdo->prepare(
        'INSERT INTO purchases
            (supplier_id, invoice_no, purchase_date, subtotal, discount, total, paid, status)
         VALUES
            (:supplier_id, :invoice_no, :purchase_date, :subtotal, :discount, :total, :paid, :status)'
    );
    $purchaseStatement->execute([
        'supplier_id' => $supplierId,
        'invoice_no' => $invoiceNo,
        'purchase_date' => $purchaseDate,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => $total,
        'paid' => $paid,
        'status' => $status,
    ]);
    $purchaseId = (int) $pdo->lastInsertId();

    $productStatement = $pdo->prepare('SELECT id, name, current_stock FROM products WHERE id = :id AND status = "active" FOR UPDATE');
    $itemStatement = $pdo->prepare(
        'INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_cost, total)
         VALUES (:purchase_id, :product_id, :quantity, :unit_cost, :total)'
    );
    $stockUpdate = $pdo->prepare(
        'UPDATE products
         SET current_stock = :current_stock,
             cost_price = :unit_cost,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $movementStatement = $pdo->prepare(
        'INSERT INTO stock_movements
            (product_id, movement_type, quantity_change, stock_after, unit_cost, reference_type, reference_id, notes)
         VALUES
            (:product_id, "purchase", :quantity_change, :stock_after, :unit_cost, "purchase", :reference_id, :notes)'
    );

    foreach ($items as $item) {
        $productStatement->execute(['id' => $item['product_id']]);
        $product = $productStatement->fetch();

        if (! is_array($product)) {
            throw new RuntimeException('One of the selected products is not active.');
        }

        $lineTotal = $item['quantity'] * $item['unit_cost'];
        $newStock = (int) $product['current_stock'] + (int) $item['quantity'];

        $itemStatement->execute([
            'purchase_id' => $purchaseId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_cost' => $item['unit_cost'],
            'total' => $lineTotal,
        ]);

        $stockUpdate->execute([
            'current_stock' => $newStock,
            'unit_cost' => $item['unit_cost'],
            'id' => $item['product_id'],
        ]);

        $movementStatement->execute([
            'product_id' => $item['product_id'],
            'quantity_change' => $item['quantity'],
            'stock_after' => $newStock,
            'unit_cost' => $item['unit_cost'],
            'reference_id' => $purchaseId,
            'notes' => 'Stock received' . ($invoiceNo !== null ? ' from invoice ' . $invoiceNo : ''),
        ]);
    }

    $pdo->commit();

    app_log_activity($pdo, $currentUser, 'purchase_create', 'Saved purchase ' . ($invoiceNo ?? '#' . $purchaseId) . ' for ' . format_money($total) . '.');
    set_flash('success', 'Purchase saved and stock updated.');
    redirect('?page=purchases');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Purchase could not be saved.');
    redirect('?page=purchases');
}
