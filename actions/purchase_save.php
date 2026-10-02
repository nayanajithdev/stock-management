<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=purchases');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    purchase_save_fail('Import database/schema.sql before saving purchases.');
}

if (! auth_can_view_product_cost($pdo, $currentUser ?? null)) {
    purchase_save_fail('Product Cost permission is required to receive supplier stock.');
}

$supplierId = ($_POST['supplier_id'] ?? '') !== '' ? (int) $_POST['supplier_id'] : null;
$purchaseId = max(0, (int) ($_POST['purchase_id'] ?? 0));
$invoiceNo = nullable_string((string) ($_POST['invoice_no'] ?? ''));
$purchaseDate = trim((string) ($_POST['purchase_date'] ?? app_today()));
$discount = max(0.0, input_decimal('discount'));
$paid = max(0.0, input_decimal('paid'));
$productIds = $_POST['product_id'] ?? [];
$productSearches = $_POST['product_search'] ?? [];
$warrantyMonthsInput = $_POST['warranty_months'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unitCosts = $_POST['unit_cost'] ?? [];
$sellingPrices = $_POST['selling_price'] ?? [];

if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchaseDate)) {
    purchase_save_fail('Purchase date is not valid.');
}

if (! is_array($productIds) || ! is_array($productSearches) || ! is_array($warrantyMonthsInput) || ! is_array($quantities) || ! is_array($unitCosts) || ! is_array($sellingPrices)) {
    purchase_save_fail('Purchase items are not valid.');
}

$items = [];
$seenProductIds = [];

foreach ($productIds as $index => $rawProductId) {
    $productId = (int) $rawProductId;
    $productSearch = trim((string) ($productSearches[$index] ?? ''));
    $warrantyMonths = max(0, (int) ($warrantyMonthsInput[$index] ?? 0));
    $quantity = max(0, (int) ($quantities[$index] ?? 0));
    $unitCost = str_replace(',', '', trim((string) ($unitCosts[$index] ?? '0')));
    $unitCost = is_numeric($unitCost) ? max(0.0, (float) $unitCost) : 0.0;
    $sellingPrice = str_replace(',', '', trim((string) ($sellingPrices[$index] ?? '0')));
    $sellingPrice = is_numeric($sellingPrice) ? max(0.0, (float) $sellingPrice) : 0.0;

    if ($productId <= 0 && $productSearch === '' && $unitCost <= 0 && $sellingPrice <= 0) {
        continue;
    }

    if ($productId <= 0 || $quantity <= 0 || $unitCost <= 0) {
        purchase_save_fail('Each purchase line needs a product, quantity, and unit cost.');
    }

    if (isset($seenProductIds[$productId])) {
        purchase_save_fail('Each product can appear only once on a purchase invoice. Combine duplicate quantities into one row.');
    }
    $seenProductIds[$productId] = true;

    $items[] = [
        'product_id' => $productId,
        'warranty_months' => $warrantyMonths,
        'quantity' => $quantity,
        'unit_cost' => $unitCost,
        'selling_price' => $sellingPrice,
    ];
}

if ($items === []) {
    purchase_save_fail('Add at least one purchase item.');
}

$subtotal = 0.0;

foreach ($items as $item) {
    $subtotal += $item['quantity'] * $item['unit_cost'];
}

if ($discount > $subtotal) {
    purchase_save_fail('Discount cannot be higher than subtotal.');
}

$total = $subtotal - $discount;
$items = purchase_apply_discount_to_items($items, $subtotal, $discount, $total);

if ($paid > $total) {
    purchase_save_fail('Paid amount cannot be higher than purchase total.');
}

$status = 'paid';
if ($paid <= 0.0) {
    $status = 'credit';
} elseif ($paid < $total) {
    $status = 'partial';
}

try {
    $pdo->beginTransaction();

    $isUpdate = $purchaseId > 0;
    $oldQuantities = [];
    $movementCreatedAt = null;
    $existingMovements = [];
    $recordedPayments = 0.0;

    if ($isUpdate) {
        $existingStatement = $pdo->prepare('SELECT id, created_at FROM purchases WHERE id = :id FOR UPDATE');
        $existingStatement->execute(['id' => $purchaseId]);
        $existingPurchase = $existingStatement->fetch();
        if (! is_array($existingPurchase)) {
            throw new RuntimeException('Purchase invoice was not found.');
        }
        $movementCreatedAt = (string) $existingPurchase['created_at'];

        $paymentStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE purchase_id = :purchase_id'
        );
        $paymentStatement->execute(['purchase_id' => $purchaseId]);
        $recordedPayments = (float) $paymentStatement->fetchColumn();
        $paid += $recordedPayments;
        if ($paid > $total) {
            throw new RuntimeException('The new total cannot be lower than the amount already paid to the supplier.');
        }

        $status = $paid <= 0.0 ? 'credit' : ($paid < $total ? 'partial' : 'paid');

        $oldItemStatement = $pdo->prepare(
            'SELECT product_id, SUM(quantity) AS quantity
             FROM purchase_items
             WHERE purchase_id = :purchase_id
             GROUP BY product_id'
        );
        $oldItemStatement->execute(['purchase_id' => $purchaseId]);
        foreach ($oldItemStatement->fetchAll() as $oldItem) {
            $oldQuantities[(int) $oldItem['product_id']] = (int) $oldItem['quantity'];
        }

        $oldMovementStatement = $pdo->prepare(
            'SELECT id, product_id, created_by, created_at
             FROM stock_movements
             WHERE reference_type = "purchase" AND reference_id = :purchase_id
             ORDER BY id ASC
             FOR UPDATE'
        );
        $oldMovementStatement->execute(['purchase_id' => $purchaseId]);
        foreach ($oldMovementStatement->fetchAll() as $movement) {
            $movementProductId = (int) $movement['product_id'];
            $existingMovements[$movementProductId][] = $movement;
        }

        $purchaseStatement = $pdo->prepare(
            'UPDATE purchases
             SET supplier_id = :supplier_id, invoice_no = :invoice_no, purchase_date = :purchase_date,
                 subtotal = :subtotal, discount = :discount, total = :total, paid = :paid,
                 status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $purchaseStatement->execute([
            'supplier_id' => $supplierId, 'invoice_no' => $invoiceNo, 'purchase_date' => $purchaseDate,
            'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total,
            'paid' => $paid, 'status' => $status, 'id' => $purchaseId,
        ]);

        $pdo->prepare('UPDATE supplier_payments SET supplier_id = :supplier_id WHERE purchase_id = :purchase_id')
            ->execute(['supplier_id' => $supplierId, 'purchase_id' => $purchaseId]);
    } else {
        $purchaseStatement = $pdo->prepare(
            'INSERT INTO purchases
                (supplier_id, invoice_no, purchase_date, subtotal, discount, total, paid, status)
             VALUES
                (:supplier_id, :invoice_no, :purchase_date, :subtotal, :discount, :total, :paid, :status)'
        );
        $purchaseStatement->execute([
            'supplier_id' => $supplierId, 'invoice_no' => $invoiceNo, 'purchase_date' => $purchaseDate,
            'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total,
            'paid' => $paid, 'status' => $status,
        ]);
        $purchaseId = (int) $pdo->lastInsertId();
    }

    $newQuantities = [];
    foreach ($items as $item) {
        $itemProductId = (int) $item['product_id'];
        $newQuantities[$itemProductId] = ($newQuantities[$itemProductId] ?? 0) + (int) $item['quantity'];
    }

    $affectedProductIds = array_values(array_unique(array_merge(array_keys($oldQuantities), array_keys($newQuantities))));
    sort($affectedProductIds, SORT_NUMERIC);
    $finalStocks = [];
    $productLock = $pdo->prepare('SELECT id, name, status, current_stock FROM products WHERE id = :id FOR UPDATE');
    foreach ($affectedProductIds as $affectedProductId) {
        $productLock->execute(['id' => $affectedProductId]);
        $product = $productLock->fetch();
        if (! is_array($product)) {
            throw new RuntimeException('One of the selected products no longer exists.');
        }
        if ((string) $product['status'] !== 'active'
            && ($newQuantities[$affectedProductId] ?? 0) > ($oldQuantities[$affectedProductId] ?? 0)) {
            throw new RuntimeException((string) $product['name'] . ' is inactive, so its purchased quantity cannot be added or increased.');
        }
        $newStock = (int) $product['current_stock'] - ($oldQuantities[$affectedProductId] ?? 0) + ($newQuantities[$affectedProductId] ?? 0);
        if ($newStock < 0) {
            throw new RuntimeException('The quantity for ' . (string) $product['name'] . ' cannot be reduced because some of this stock has already been used.');
        }
        $finalStocks[$affectedProductId] = $newStock;
    }

    if ($isUpdate) {
        $pdo->prepare('DELETE FROM purchase_items WHERE purchase_id = :purchase_id')->execute(['purchase_id' => $purchaseId]);
    }

    $itemStatement = $pdo->prepare(
        'INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_cost, warranty_months, total)
         VALUES (:purchase_id, :product_id, :quantity, :unit_cost, :warranty_months, :total)'
    );
    $movementStatement = $pdo->prepare(
        'INSERT INTO stock_movements
            (product_id, movement_type, quantity_change, stock_after, unit_cost, warranty_months, reference_type, reference_id, notes, created_by, created_at)
         VALUES
            (:product_id, "purchase", :quantity_change, :stock_after, :unit_cost, :warranty_months, "purchase", :reference_id, :notes, :created_by, COALESCE(:created_at, CURRENT_TIMESTAMP))'
    );
    $movementUpdate = $pdo->prepare(
        'UPDATE stock_movements
         SET quantity_change = :quantity_change, unit_cost = :unit_cost,
             warranty_months = :warranty_months, notes = :notes
         WHERE id = :id'
    );
    $usedMovementIds = [];
    $sellingPricesByProduct = [];

    foreach ($items as $item) {
        $lineTotal = $item['total'];
        $newStock = $finalStocks[(int) $item['product_id']];
        $itemProductId = (int) $item['product_id'];
        $sellingPricesByProduct[$itemProductId] = (float) $item['selling_price'];

        $itemStatement->execute([
            'purchase_id' => $purchaseId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_cost' => $item['unit_cost'],
            'warranty_months' => $item['warranty_months'],
            'total' => $lineTotal,
        ]);

        $notes = 'Stock received' . ($invoiceNo !== null ? ' from invoice ' . $invoiceNo : '');
        $existingMovement = $existingMovements[$itemProductId][0] ?? null;
        if (is_array($existingMovement)) {
            $movementUpdate->execute([
                'quantity_change' => $item['quantity'],
                'unit_cost' => $item['net_unit_cost'],
                'warranty_months' => $item['warranty_months'],
                'notes' => $notes,
                'id' => $existingMovement['id'],
            ]);
            $usedMovementIds[(int) $existingMovement['id']] = true;
        } else {
            $movementStatement->execute([
                'product_id' => $itemProductId,
                'quantity_change' => $item['quantity'],
                'stock_after' => $newStock,
                'unit_cost' => $item['net_unit_cost'],
                'warranty_months' => $item['warranty_months'],
                'reference_id' => $purchaseId,
                'notes' => $notes,
                'created_by' => (int) ($currentUser['id'] ?? 0) ?: null,
                'created_at' => $movementCreatedAt,
            ]);
        }
    }

    if ($isUpdate) {
        $deleteMovement = $pdo->prepare('DELETE FROM stock_movements WHERE id = :id');
        foreach ($existingMovements as $productMovements) {
            foreach ($productMovements as $movement) {
                if (! isset($usedMovementIds[(int) $movement['id']])) {
                    $deleteMovement->execute(['id' => $movement['id']]);
                }
            }
        }
    }

    $movementList = $pdo->prepare(
        'SELECT id, quantity_change FROM stock_movements
         WHERE product_id = :product_id ORDER BY created_at ASC, id ASC FOR UPDATE'
    );
    $movementBalanceUpdate = $pdo->prepare('UPDATE stock_movements SET stock_after = :stock_after WHERE id = :id');
    $latestCostStatement = $pdo->prepare(
        'SELECT sm.unit_cost
         FROM stock_movements sm
         LEFT JOIN purchases pu ON sm.reference_type = "purchase" AND pu.id = sm.reference_id
         WHERE sm.product_id = :product_id AND sm.quantity_change > 0
           AND sm.movement_type IN ("opening", "purchase")
         ORDER BY COALESCE(pu.purchase_date, DATE(sm.created_at)) DESC, sm.id DESC
         LIMIT 1'
    );
    $productUpdate = $pdo->prepare(
        'UPDATE products
         SET current_stock = :current_stock, cost_price = :cost_price,
             selling_price = COALESCE(:selling_price, selling_price), updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );

    foreach ($affectedProductIds as $affectedProductId) {
        $movementList->execute(['product_id' => $affectedProductId]);
        $runningStock = 0;
        foreach ($movementList->fetchAll() as $movement) {
            $runningStock += (int) $movement['quantity_change'];
            $movementBalanceUpdate->execute(['stock_after' => $runningStock, 'id' => $movement['id']]);
        }
        if ($runningStock !== $finalStocks[$affectedProductId]) {
            throw new RuntimeException('Stock history could not be reconciled for one of the edited products.');
        }

        $latestCostStatement->execute(['product_id' => $affectedProductId]);
        $latestCost = $latestCostStatement->fetchColumn();
        $productUpdate->execute([
            'current_stock' => $runningStock,
            'cost_price' => $latestCost !== false ? (float) $latestCost : 0.0,
            'selling_price' => $sellingPricesByProduct[$affectedProductId] ?? null,
            'id' => $affectedProductId,
        ]);
    }

    $pdo->commit();

    unset($_SESSION['purchase_form_old']);
    app_log_activity(
        $pdo,
        $currentUser,
        $isUpdate ? 'purchase_update' : 'purchase_create',
        ($isUpdate ? 'Updated purchase ' : 'Saved purchase ') . ($invoiceNo ?? '#' . $purchaseId) . ' for ' . format_money($total) . '.'
    );
    set_flash('success', $isUpdate ? 'Purchase updated and stock adjusted.' : 'Purchase saved and stock updated.');
    redirect($isUpdate ? '?page=purchase-view&id=' . $purchaseId : '?page=purchases');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    purchase_save_fail($exception instanceof RuntimeException ? $exception->getMessage() : 'Purchase could not be saved.');
}

function purchase_save_fail(string $message): never
{
    $_SESSION['purchase_form_old'] = purchase_save_old_input();
    set_flash('error', $message);
    $purchaseId = max(0, (int) ($_POST['purchase_id'] ?? 0));
    redirect('?page=purchases' . ($purchaseId > 0 ? '&edit=' . $purchaseId . '#purchase-form' : ''));
}

function purchase_save_old_input(): array
{
    $scalarKeys = [
        'purchase_id',
        'recorded_payments',
        'supplier_id',
        'supplier_search',
        'invoice_no',
        'purchase_date',
        'discount',
        'paid',
    ];
    $arrayKeys = [
        'product_id',
        'product_search',
        'warranty_months',
        'quantity',
        'unit_cost',
        'selling_price',
    ];
    $oldInput = [];

    foreach ($scalarKeys as $key) {
        $oldInput[$key] = substr(trim((string) ($_POST[$key] ?? '')), 0, 255);
    }

    foreach ($arrayKeys as $key) {
        $values = $_POST[$key] ?? [];
        $oldInput[$key] = [];

        if (! is_array($values)) {
            continue;
        }

        foreach (array_slice($values, 0, 50) as $value) {
            $oldInput[$key][] = substr(trim((string) $value), 0, 255);
        }
    }

    return $oldInput;
}

function purchase_apply_discount_to_items(array $items, float $subtotal, float $discount, float $total): array
{
    $subtotal = round(max(0.0, $subtotal), 2);
    $discount = round(max(0.0, $discount), 2);
    $total = round(max(0.0, $total), 2);
    $netSum = 0.0;
    $lastIndex = null;

    foreach ($items as $index => $item) {
        $quantity = max(1, (int) $item['quantity']);
        $grossUnitCost = round(max(0.0, (float) $item['unit_cost']), 2);
        $grossLineTotal = round($quantity * $grossUnitCost, 2);
        $discountShare = 0.0;

        if ($discount > 0.0 && $subtotal > 0.0 && $grossLineTotal > 0.0) {
            $discountShare = min($grossLineTotal, $discount * ($grossLineTotal / $subtotal));
        }

        $netLineTotal = round($grossLineTotal - $discountShare, 2);

        $items[$index]['gross_unit_cost'] = $grossUnitCost;
        $items[$index]['gross_total'] = $grossLineTotal;
        $items[$index]['line_discount'] = round($grossLineTotal - $netLineTotal, 2);
        $items[$index]['total'] = $grossLineTotal;
        $items[$index]['net_total'] = $netLineTotal;
        $items[$index]['net_unit_cost'] = round($netLineTotal / $quantity, 2);

        $netSum += $netLineTotal;
        $lastIndex = $index;
    }

    if ($lastIndex !== null) {
        $difference = round($total - $netSum, 2);

        if (abs($difference) >= 0.01) {
            $quantity = max(1, (int) $items[$lastIndex]['quantity']);
            $items[$lastIndex]['net_total'] = round(max(0.0, (float) $items[$lastIndex]['net_total'] + $difference), 2);
            $items[$lastIndex]['line_discount'] = round((float) $items[$lastIndex]['gross_total'] - (float) $items[$lastIndex]['net_total'], 2);
            $items[$lastIndex]['net_unit_cost'] = round((float) $items[$lastIndex]['net_total'] / $quantity, 2);
        }
    }

    return $items;
}
