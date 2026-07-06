<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=sales');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    sale_save_fail('Import database/schema.sql before saving sales.');
}

$customerId = ($_POST['customer_id'] ?? '') !== '' ? (int) $_POST['customer_id'] : null;
$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerPhone = nullable_string((string) ($_POST['customer_phone'] ?? ''));
$saleDate = trim((string) ($_POST['sale_date'] ?? date('Y-m-d\TH:i')));
$paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
$afterSave = (string) ($_POST['after_save'] ?? 'stay');
$discount = max(0.0, input_decimal('discount'));
$tax = max(0.0, input_decimal('tax'));
$paid = max(0.0, input_decimal('paid'));
$productIds = $_POST['product_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unitPrices = $_POST['unit_price'] ?? [];
$warrantyMonthsInput = $_POST['warranty_months'] ?? [];
$lineDiscounts = $_POST['line_discount'] ?? [];
$validPaymentMethods = ['cash', 'card', 'bank', 'credit'];

if (! in_array($paymentMethod, $validPaymentMethods, true)) {
    sale_save_fail('Choose a valid payment method.');
}

if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $saleDate)) {
    sale_save_fail('Sale date is not valid.');
}

if (! is_array($productIds) || ! is_array($quantities) || ! is_array($unitPrices) || ! is_array($warrantyMonthsInput) || ! is_array($lineDiscounts)) {
    sale_save_fail('Sale items are not valid.');
}

$items = [];

foreach ($productIds as $index => $rawProductId) {
    $productId = (int) $rawProductId;
    $quantity = max(0, (int) ($quantities[$index] ?? 0));
    $unitPrice = str_replace(',', '', trim((string) ($unitPrices[$index] ?? '0')));
    $unitPrice = is_numeric($unitPrice) ? max(0.0, (float) $unitPrice) : 0.0;
    $warrantyMonths = max(0, (int) ($warrantyMonthsInput[$index] ?? 0));
    $lineDiscount = str_replace(',', '', trim((string) ($lineDiscounts[$index] ?? '0')));
    $lineDiscount = is_numeric($lineDiscount) ? max(0.0, (float) $lineDiscount) : 0.0;

    if ($productId <= 0 && $quantity === 0 && $unitPrice <= 0) {
        continue;
    }

    if ($productId <= 0 || $quantity <= 0 || $unitPrice <= 0) {
        sale_save_fail('Each sale line needs a product, quantity, and unit price.');
    }

    $lineSubtotal = $quantity * $unitPrice;

    if ($lineDiscount > $lineSubtotal) {
        sale_save_fail('Line discount cannot be higher than the line subtotal.');
    }

    $items[] = [
        'product_id' => $productId,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'warranty_months' => $warrantyMonths,
        'line_discount' => $lineDiscount,
    ];
}

if ($items === []) {
    sale_save_fail('Add at least one sale item.');
}

$subtotal = 0.0;

foreach ($items as $item) {
    $subtotal += ($item['quantity'] * $item['unit_price']) - $item['line_discount'];
}

if ($discount > $subtotal) {
    sale_save_fail('Invoice discount cannot be higher than subtotal.');
}

$total = $subtotal - $discount + $tax;

if ($paid > $total) {
    sale_save_fail('Paid amount cannot be higher than invoice total.');
}

try {
    $pdo->beginTransaction();

    if ($customerId === null && ($customerName !== '' || $customerPhone !== null)) {
        if ($customerName === '') {
            $customerName = 'Walk-in Customer';
        }

        $existingCustomer = null;

        if ($customerPhone !== null) {
            $customerStatement = $pdo->prepare('SELECT id FROM customers WHERE phone = :phone LIMIT 1');
            $customerStatement->execute(['phone' => $customerPhone]);
            $existingCustomer = $customerStatement->fetch();
        }

        if (is_array($existingCustomer)) {
            $customerId = (int) $existingCustomer['id'];
        } else {
            $createCustomer = $pdo->prepare('INSERT INTO customers (name, phone) VALUES (:name, :phone)');
            $createCustomer->execute([
                'name' => $customerName,
                'phone' => $customerPhone,
            ]);
            $customerId = (int) $pdo->lastInsertId();
        }
    }

    if ($customerId !== null) {
        $customerCheck = $pdo->prepare('SELECT id FROM customers WHERE id = :id AND is_active = 1 LIMIT 1');
        $customerCheck->execute(['id' => $customerId]);

        if (! is_array($customerCheck->fetch())) {
            throw new RuntimeException('Selected customer is not active.');
        }
    }

    $status = 'paid';
    if ($paid <= 0.0) {
        $status = 'credit';
    } elseif ($paid < $total) {
        $status = 'partial';
    }

    $invoiceNo = next_sale_invoice_no($pdo);
    $saleStatement = $pdo->prepare(
        'INSERT INTO sales
            (customer_id, invoice_no, sale_date, subtotal, discount, tax, total, paid, payment_method, status)
         VALUES
            (:customer_id, :invoice_no, :sale_date, :subtotal, :discount, :tax, :total, :paid, :payment_method, :status)'
    );
    $saleStatement->execute([
        'customer_id' => $customerId,
        'invoice_no' => $invoiceNo,
        'sale_date' => str_replace('T', ' ', $saleDate) . ':00',
        'subtotal' => $subtotal,
        'discount' => $discount,
        'tax' => $tax,
        'total' => $total,
        'paid' => $paid,
        'payment_method' => $paymentMethod,
        'status' => $status,
    ]);
    $saleId = (int) $pdo->lastInsertId();

    $productStatement = $pdo->prepare('SELECT id, name, current_stock, cost_price FROM products WHERE id = :id AND status = "active" FOR UPDATE');
    $itemStatement = $pdo->prepare(
        'INSERT INTO sale_items
            (sale_id, product_id, quantity, unit_price, unit_cost, warranty_months, discount, total)
         VALUES
            (:sale_id, :product_id, :quantity, :unit_price, :unit_cost, :warranty_months, :discount, :total)'
    );
    $stockUpdate = $pdo->prepare(
        'UPDATE products
         SET current_stock = :current_stock,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $movementStatement = $pdo->prepare(
        'INSERT INTO stock_movements
            (product_id, movement_type, quantity_change, stock_after, unit_cost, reference_type, reference_id, notes, created_by)
         VALUES
            (:product_id, "sale", :quantity_change, :stock_after, :unit_cost, "sale", :reference_id, :notes, :created_by)'
    );

    foreach ($items as $item) {
        $productStatement->execute(['id' => $item['product_id']]);
        $product = $productStatement->fetch();

        if (! is_array($product)) {
            throw new RuntimeException('One of the selected products is not active.');
        }

        $currentStock = (int) $product['current_stock'];
        $quantity = (int) $item['quantity'];

        if ($quantity > $currentStock) {
            throw new RuntimeException($product['name'] . ' has only ' . $currentStock . ' in stock.');
        }

        $lineTotal = ($quantity * $item['unit_price']) - $item['line_discount'];
        $newStock = $currentStock - $quantity;
        $unitCost = sale_fifo_unit_cost($pdo, (int) $item['product_id'], $quantity, (float) $product['cost_price']);

        $itemStatement->execute([
            'sale_id' => $saleId,
            'product_id' => $item['product_id'],
            'quantity' => $quantity,
            'unit_price' => $item['unit_price'],
            'unit_cost' => $unitCost,
            'warranty_months' => $item['warranty_months'],
            'discount' => $item['line_discount'],
            'total' => $lineTotal,
        ]);

        $stockUpdate->execute([
            'current_stock' => $newStock,
            'id' => $item['product_id'],
        ]);

        $movementStatement->execute([
            'product_id' => $item['product_id'],
            'quantity_change' => -$quantity,
            'stock_after' => $newStock,
            'unit_cost' => $unitCost,
            'reference_id' => $saleId,
            'notes' => 'Sold on invoice ' . $invoiceNo,
            'created_by' => (int) ($currentUser['id'] ?? 0) ?: null,
        ]);
    }

    $pdo->commit();

    unset($_SESSION['sale_form_old']);
    app_log_activity($pdo, $currentUser, 'sale_create', 'Created invoice ' . $invoiceNo . ' for ' . format_money($total) . '.');
    set_flash('success', 'Sale saved as invoice ' . $invoiceNo . '.');
    if ($afterSave === 'print') {
        redirect('?page=sale-view&id=' . $saleId . '&print=1');
    }

    redirect('?page=sales');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    sale_save_fail($exception instanceof RuntimeException ? $exception->getMessage() : 'Sale could not be saved.');
}

function sale_save_fail(string $message): never
{
    $_SESSION['sale_form_old'] = sale_save_old_input();
    set_flash('error', $message);
    redirect('?page=sales');
}

function sale_save_old_input(): array
{
    $scalarKeys = [
        'customer_id',
        'customer_name',
        'customer_phone',
        'sale_date',
        'payment_method',
        'discount',
        'tax',
        'paid',
    ];
    $arrayKeys = [
        'product_id',
        'product_search',
        'quantity',
        'unit_price',
        'warranty_months',
        'line_discount',
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

function next_sale_invoice_no(PDO $pdo): string
{
    $prefix = 'INV-' . date('Ymd') . '-';
    $statement = $pdo->prepare('SELECT invoice_no FROM sales WHERE invoice_no LIKE :prefix ORDER BY id DESC LIMIT 1');
    $statement->execute(['prefix' => $prefix . '%']);
    $lastInvoice = (string) ($statement->fetchColumn() ?: '');
    $nextNumber = 1;

    if (preg_match('/-(\d+)$/', $lastInvoice, $matches) === 1) {
        $nextNumber = (int) $matches[1] + 1;
    }

    return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
}

function sale_fifo_unit_cost(PDO $pdo, int $productId, int $quantity, float $fallbackCost): float
{
    if ($productId <= 0 || $quantity <= 0) {
        return $fallbackCost;
    }

    $outboundStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(ABS(quantity_change)), 0)
         FROM stock_movements
         WHERE product_id = :product_id
           AND quantity_change < 0'
    );
    $outboundStatement->execute(['product_id' => $productId]);
    $outboundRemaining = (int) $outboundStatement->fetchColumn();

    $lotStatement = $pdo->prepare(
        'SELECT sm.quantity_change,
                ' . app_lot_unit_cost_sql('sm', 'pc', 'lco') . ' AS unit_cost
         FROM stock_movements sm
         LEFT JOIN purchases pu ON sm.reference_type = "purchase" AND pu.id = sm.reference_id
         ' . app_purchase_cost_join_sql('sm', 'pc') . '
         ' . app_lot_cost_override_join_sql('sm', 'lco') . '
         WHERE sm.product_id = :product_id
           AND sm.quantity_change > 0
           AND sm.movement_type IN ("opening", "purchase", "return_in", "adjustment_in", "warranty_supplier_in")
         ORDER BY COALESCE(pu.purchase_date, DATE(sm.created_at)) ASC, sm.id ASC'
    );
    $lotStatement->execute(['product_id' => $productId]);

    $quantityToAllocate = $quantity;
    $allocatedQuantity = 0;
    $allocatedCost = 0.0;

    foreach ($lotStatement->fetchAll() as $lot) {
        $lotQuantity = (int) $lot['quantity_change'];
        $alreadyConsumed = min($lotQuantity, $outboundRemaining);
        $outboundRemaining = max(0, $outboundRemaining - $alreadyConsumed);
        $availableQuantity = $lotQuantity - $alreadyConsumed;

        if ($availableQuantity <= 0) {
            continue;
        }

        $usedQuantity = min($quantityToAllocate, $availableQuantity);
        $allocatedQuantity += $usedQuantity;
        $allocatedCost += $usedQuantity * (float) $lot['unit_cost'];
        $quantityToAllocate -= $usedQuantity;

        if ($quantityToAllocate <= 0) {
            break;
        }
    }

    if ($quantityToAllocate > 0) {
        $allocatedQuantity += $quantityToAllocate;
        $allocatedCost += $quantityToAllocate * $fallbackCost;
    }

    return $allocatedQuantity > 0 ? round($allocatedCost / $allocatedQuantity, 2) : $fallbackCost;
}
