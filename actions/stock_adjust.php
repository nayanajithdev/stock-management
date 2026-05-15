<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=stock');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before adjusting stock.');
    redirect('?page=stock');
}

$productId = (int) ($_POST['product_id'] ?? 0);
$adjustmentType = (string) ($_POST['adjustment_type'] ?? '');
$quantity = input_int('quantity');
$exactStock = input_int('exact_stock');
$notes = trim((string) ($_POST['notes'] ?? ''));

$adjustmentTypes = [
    'increase' => [
        'movement_type' => 'adjustment_in',
        'label' => 'Manual increase',
        'direction' => 1,
    ],
    'decrease' => [
        'movement_type' => 'adjustment_out',
        'label' => 'Manual decrease',
        'direction' => -1,
    ],
    'damage' => [
        'movement_type' => 'damage',
        'label' => 'Damage or loss',
        'direction' => -1,
    ],
    'count' => [
        'movement_type' => 'stock_count',
        'label' => 'Stock count correction',
        'direction' => 0,
    ],
];

if ($productId <= 0 || ! isset($adjustmentTypes[$adjustmentType])) {
    set_flash('error', 'Choose a valid product and adjustment type.');
    redirect('?page=stock');
}

if ($notes === '') {
    set_flash('error', 'Notes are required for manual stock adjustments.');
    redirect('?page=stock');
}

if ($adjustmentType !== 'count' && $quantity <= 0) {
    set_flash('error', 'Quantity must be greater than zero.');
    redirect('?page=stock');
}

try {
    $pdo->beginTransaction();

    $productStatement = $pdo->prepare('SELECT id, name, current_stock, cost_price FROM products WHERE id = :id AND status = "active" FOR UPDATE');
    $productStatement->execute(['id' => $productId]);
    $product = $productStatement->fetch();

    if (! is_array($product)) {
        throw new RuntimeException('Selected product is not active.');
    }

    $currentStock = (int) $product['current_stock'];
    $typeConfig = $adjustmentTypes[$adjustmentType];

    if ($adjustmentType === 'count') {
        $newStock = $exactStock;
        $quantityChange = $newStock - $currentStock;

        if ($quantityChange === 0) {
            throw new RuntimeException('Exact stock count is already the current stock.');
        }
    } else {
        $quantityChange = (int) $typeConfig['direction'] * $quantity;
        $newStock = $currentStock + $quantityChange;
    }

    if ($newStock < 0) {
        throw new RuntimeException('Stock cannot go below zero.');
    }

    $updateStatement = $pdo->prepare(
        'UPDATE products
         SET current_stock = :current_stock,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $updateStatement->execute([
        'current_stock' => $newStock,
        'id' => $productId,
    ]);

    $movementStatement = $pdo->prepare(
        'INSERT INTO stock_movements
            (product_id, movement_type, quantity_change, stock_after, unit_cost, reference_type, reference_id, notes)
         VALUES
            (:product_id, :movement_type, :quantity_change, :stock_after, :unit_cost, "manual_adjustment", NULL, :notes)'
    );
    $movementStatement->execute([
        'product_id' => $productId,
        'movement_type' => $typeConfig['movement_type'],
        'quantity_change' => $quantityChange,
        'stock_after' => $newStock,
        'unit_cost' => (float) $product['cost_price'],
        'notes' => $typeConfig['label'] . ': ' . $notes,
    ]);

    $pdo->commit();

    app_log_activity($pdo, $currentUser, 'stock_adjust', 'Adjusted stock for ' . $product['name'] . ' by ' . $quantityChange . ' unit(s).');
    set_flash('success', 'Stock adjusted successfully.');
    redirect('?page=stock');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Stock adjustment could not be saved.');
    redirect('?page=stock');
}
