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
$lotMovementId = (int) ($_POST['lot_movement_id'] ?? 0);
$exactStock = input_int('exact_stock');
$notes = trim((string) ($_POST['notes'] ?? ''));
$redirectTo = $productId > 0 ? '?page=product-history&id=' . $productId : '?page=products';

if ($productId <= 0 || $lotMovementId <= 0) {
    set_flash('error', 'Choose a valid stock lot to correct.');
    redirect($redirectTo);
}

if ($notes === '') {
    set_flash('error', 'Notes are required for stock lot corrections.');
    redirect($redirectTo);
}

if ($exactStock < 0) {
    set_flash('error', 'New stock count cannot be below zero.');
    redirect($redirectTo);
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
    $lot = stock_adjust_fetch_lot($pdo, $productId, $lotMovementId);

    if (! is_array($lot)) {
        throw new RuntimeException('Selected stock lot was not found.');
    }

    $currentLotStock = stock_adjust_current_lot_stock($pdo, $productId, $lotMovementId);
    $newLotStock = $exactStock;
    $quantityChange = $newLotStock - $currentLotStock;

    if ($quantityChange === 0) {
        throw new RuntimeException('This lot already has the selected stock count.');
    }

    $newStock = $currentStock + $quantityChange;

    if ($newStock < 0) {
        throw new RuntimeException('Product stock cannot go below zero.');
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
            (product_id, movement_type, quantity_change, stock_after, unit_cost, warranty_months, reference_type, reference_id, notes, created_by)
         VALUES
            (:product_id, "stock_count", :quantity_change, :stock_after, :unit_cost, :warranty_months, "stock_lot", :reference_id, :notes, :created_by)'
    );
    $movementStatement->execute([
        'product_id' => $productId,
        'quantity_change' => $quantityChange,
        'stock_after' => $newStock,
        'unit_cost' => (float) $lot['display_unit_cost'],
        'warranty_months' => (int) $lot['warranty_months'],
        'reference_id' => $lotMovementId,
        'notes' => 'Lot correction: ' . $notes,
        'created_by' => (int) ($currentUser['id'] ?? 0) ?: null,
    ]);

    $pdo->commit();

    app_log_activity($pdo, $currentUser, 'stock_lot_adjust', 'Adjusted stock lot for ' . $product['name'] . ' by ' . $quantityChange . ' unit(s).');
    set_flash('success', 'Stock lot corrected successfully.');
    redirect($redirectTo);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Stock adjustment could not be saved.');
    redirect($redirectTo);
}

function stock_adjust_fetch_lot(PDO $pdo, int $productId, int $lotMovementId): ?array
{
    $statement = $pdo->prepare(
        'SELECT sm.*,
                ' . app_lot_unit_cost_sql('sm', 'pc') . ' AS display_unit_cost
         FROM stock_movements sm
         ' . app_purchase_cost_join_sql('sm', 'pc') . '
         WHERE sm.id = :id
           AND sm.product_id = :product_id
           AND sm.quantity_change > 0
           AND sm.movement_type IN ("opening", "purchase", "return_in", "adjustment_in", "warranty_supplier_in")
         LIMIT 1'
    );
    $statement->execute([
        'id' => $lotMovementId,
        'product_id' => $productId,
    ]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function stock_adjust_current_lot_stock(PDO $pdo, int $productId, int $lotMovementId): int
{
    $lotStatement = $pdo->prepare(
        'SELECT sm.id,
                sm.quantity_change
         FROM stock_movements sm
         LEFT JOIN purchases pu ON sm.reference_type = "purchase" AND pu.id = sm.reference_id
         WHERE sm.product_id = :product_id
           AND sm.quantity_change > 0
           AND sm.movement_type IN ("opening", "purchase", "return_in", "adjustment_in", "warranty_supplier_in")
         ORDER BY COALESCE(pu.purchase_date, DATE(sm.created_at)) ASC, sm.id ASC'
    );
    $lotStatement->execute(['product_id' => $productId]);
    $lots = $lotStatement->fetchAll();

    $lotIds = array_map(static fn (array $lot): int => (int) $lot['id'], $lots);
    $adjustments = stock_adjust_lot_adjustments($pdo, $productId, $lotIds);

    $outboundStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(ABS(quantity_change)), 0)
         FROM stock_movements
         WHERE product_id = :product_id
           AND quantity_change < 0
           AND (reference_type IS NULL OR reference_type <> "stock_lot")'
    );
    $outboundStatement->execute(['product_id' => $productId]);
    $outboundRemaining = (int) $outboundStatement->fetchColumn();

    foreach ($lots as $lot) {
        $id = (int) $lot['id'];
        $lotQuantity = max(0, (int) $lot['quantity_change'] + (int) ($adjustments[$id] ?? 0));
        $deducted = min($lotQuantity, $outboundRemaining);
        $currentLotStock = max(0, $lotQuantity - $deducted);
        $outboundRemaining = max(0, $outboundRemaining - $deducted);

        if ($id === $lotMovementId) {
            return $currentLotStock;
        }
    }

    return 0;
}

function stock_adjust_lot_adjustments(PDO $pdo, int $productId, array $lotIds): array
{
    if ($lotIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($lotIds), '?'));
    $statement = $pdo->prepare(
        'SELECT reference_id, COALESCE(SUM(quantity_change), 0) AS adjustment
         FROM stock_movements
         WHERE product_id = ?
           AND reference_type = "stock_lot"
           AND reference_id IN (' . $placeholders . ')
         GROUP BY reference_id'
    );
    $statement->execute(array_merge([$productId], $lotIds));

    $adjustments = [];

    foreach ($statement->fetchAll() as $row) {
        $adjustments[(int) $row['reference_id']] = (int) $row['adjustment'];
    }

    return $adjustments;
}
