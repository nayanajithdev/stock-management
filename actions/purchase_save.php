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
$invoiceNo = nullable_string((string) ($_POST['invoice_no'] ?? ''));
$purchaseDate = trim((string) ($_POST['purchase_date'] ?? date('Y-m-d')));
$discount = max(0.0, input_decimal('discount'));
$paid = max(0.0, input_decimal('paid'));
$productIds = $_POST['product_id'] ?? [];
$warrantyMonthsInput = $_POST['warranty_months'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unitCosts = $_POST['unit_cost'] ?? [];

if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchaseDate)) {
    purchase_save_fail('Purchase date is not valid.');
}

if (! is_array($productIds) || ! is_array($warrantyMonthsInput) || ! is_array($quantities) || ! is_array($unitCosts)) {
    purchase_save_fail('Purchase items are not valid.');
}

$items = [];

foreach ($productIds as $index => $rawProductId) {
    $productId = (int) $rawProductId;
    $warrantyMonths = max(0, (int) ($warrantyMonthsInput[$index] ?? 0));
    $quantity = max(0, (int) ($quantities[$index] ?? 0));
    $unitCost = str_replace(',', '', trim((string) ($unitCosts[$index] ?? '0')));
    $unitCost = is_numeric($unitCost) ? max(0.0, (float) $unitCost) : 0.0;

    if ($productId <= 0 && $quantity === 0 && $unitCost <= 0) {
        continue;
    }

    if ($productId <= 0 || $quantity <= 0 || $unitCost <= 0) {
        purchase_save_fail('Each purchase line needs a product, quantity, and unit cost.');
    }

    $items[] = [
        'product_id' => $productId,
        'warranty_months' => $warrantyMonths,
        'quantity' => $quantity,
        'unit_cost' => $unitCost,
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
        'INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_cost, warranty_months, total)
         VALUES (:purchase_id, :product_id, :quantity, :unit_cost, :warranty_months, :total)'
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
            (product_id, movement_type, quantity_change, stock_after, unit_cost, warranty_months, reference_type, reference_id, notes, created_by)
         VALUES
            (:product_id, "purchase", :quantity_change, :stock_after, :unit_cost, :warranty_months, "purchase", :reference_id, :notes, :created_by)'
    );

    foreach ($items as $item) {
        $productStatement->execute(['id' => $item['product_id']]);
        $product = $productStatement->fetch();

        if (! is_array($product)) {
            throw new RuntimeException('One of the selected products is not active.');
        }

        $lineTotal = $item['total'];
        $newStock = (int) $product['current_stock'] + (int) $item['quantity'];

        $itemStatement->execute([
            'purchase_id' => $purchaseId,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_cost' => $item['unit_cost'],
            'warranty_months' => $item['warranty_months'],
            'total' => $lineTotal,
        ]);

        $stockUpdate->execute([
            'current_stock' => $newStock,
            'unit_cost' => $item['net_unit_cost'],
            'id' => $item['product_id'],
        ]);

        $movementStatement->execute([
            'product_id' => $item['product_id'],
            'quantity_change' => $item['quantity'],
            'stock_after' => $newStock,
            'unit_cost' => $item['net_unit_cost'],
            'warranty_months' => $item['warranty_months'],
            'reference_id' => $purchaseId,
            'notes' => 'Stock received' . ($invoiceNo !== null ? ' from invoice ' . $invoiceNo : ''),
            'created_by' => (int) ($currentUser['id'] ?? 0) ?: null,
        ]);
    }

    $pdo->commit();

    unset($_SESSION['purchase_form_old']);
    app_log_activity($pdo, $currentUser, 'purchase_create', 'Saved purchase ' . ($invoiceNo ?? '#' . $purchaseId) . ' for ' . format_money($total) . '.');
    set_flash('success', 'Purchase saved and stock updated.');
    redirect('?page=purchases');
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
    redirect('?page=purchases');
}

function purchase_save_old_input(): array
{
    $scalarKeys = [
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
