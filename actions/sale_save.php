<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=sales');
}

verify_csrf();

$postedSaleId = max(0, (int) ($_POST['sale_id'] ?? 0));

if (! $dbReady || $pdo === null) {
    sale_save_fail('Import database/schema.sql before saving sales.', $postedSaleId);
}

$customerId = ($_POST['customer_id'] ?? '') !== '' ? (int) $_POST['customer_id'] : null;
$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerPhone = nullable_string((string) ($_POST['customer_phone'] ?? ''));
$canChangeSaleDate = auth_user_has_permission($pdo, $currentUser, 'sale_date_change');
$saleDate = $canChangeSaleDate
    ? trim((string) ($_POST['sale_date'] ?? date('Y-m-d\TH:i')))
    : date('Y-m-d\TH:i');
$paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
$afterSave = (string) ($_POST['after_save'] ?? 'stay');
$discount = max(0.0, input_decimal('discount'));
$tax = max(0.0, input_decimal('tax'));
$paid = max(0.0, input_decimal('paid'));
$saleItemIds = $_POST['sale_item_id'] ?? [];
$productIds = $_POST['product_id'] ?? [];
$productSearches = $_POST['product_search'] ?? [];
$customItemNames = $_POST['custom_item_name'] ?? [];
$customItemCosts = $_POST['custom_item_cost'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unitPrices = $_POST['unit_price'] ?? [];
$warrantyMonthsInput = $_POST['warranty_months'] ?? [];
$lineDiscounts = $_POST['line_discount'] ?? [];
$validPaymentMethods = ['cash', 'card', 'bank', 'credit'];

if (! in_array($paymentMethod, $validPaymentMethods, true)) {
    sale_save_fail('Choose a valid payment method.', $postedSaleId);
}

if ($canChangeSaleDate && ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $saleDate)) {
    sale_save_fail('Sale date is not valid.', $postedSaleId);
}

if (! is_array($saleItemIds) || ! is_array($productIds) || ! is_array($productSearches) || ! is_array($customItemNames) || ! is_array($customItemCosts) || ! is_array($quantities) || ! is_array($unitPrices) || ! is_array($warrantyMonthsInput) || ! is_array($lineDiscounts)) {
    sale_save_fail('Sale items are not valid.', $postedSaleId);
}

$items = [];

foreach ($productIds as $index => $rawProductId) {
    $saleItemId = max(0, (int) ($saleItemIds[$index] ?? 0));
    $productId = (int) $rawProductId;
    $productSearch = trim((string) ($productSearches[$index] ?? ''));
    $customItemName = substr(trim((string) ($customItemNames[$index] ?? '')), 0, 180);
    $customItemCost = str_replace(',', '', trim((string) ($customItemCosts[$index] ?? '0')));
    $customItemCost = is_numeric($customItemCost) ? max(0.0, (float) $customItemCost) : 0.0;
    $quantity = max(0, (int) ($quantities[$index] ?? 0));
    $unitPrice = str_replace(',', '', trim((string) ($unitPrices[$index] ?? '0')));
    $unitPrice = is_numeric($unitPrice) ? max(0.0, (float) $unitPrice) : 0.0;
    $warrantyMonths = max(0, (int) ($warrantyMonthsInput[$index] ?? 0));
    $lineDiscount = str_replace(',', '', trim((string) ($lineDiscounts[$index] ?? '0')));
    $lineDiscount = is_numeric($lineDiscount) ? max(0.0, (float) $lineDiscount) : 0.0;

    if ($productId <= 0 && $productSearch === '' && $customItemName === '') {
        continue;
    }

    if ($productId <= 0 && $customItemName === '' && str_starts_with($productSearch, '#')) {
        $customItemName = substr(trim(ltrim($productSearch, '#')), 0, 180);
    }

    $isCustomItem = $productId <= 0 && $customItemName !== '';

    if ((! $isCustomItem && $productId <= 0) || $quantity <= 0 || $unitPrice <= 0) {
        sale_save_fail('Each sale line needs a product, quantity, and unit price.', $postedSaleId);
    }

    $lineSubtotal = $quantity * $unitPrice;

    if ($lineDiscount > $lineSubtotal) {
        sale_save_fail('Line discount cannot be higher than the line subtotal.', $postedSaleId);
    }

    $items[] = [
        'sale_item_id' => $saleItemId,
        'product_id' => $isCustomItem ? null : $productId,
        'item_name' => $isCustomItem ? $customItemName : null,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'unit_cost' => $isCustomItem ? $customItemCost : null,
        'warranty_months' => $warrantyMonths,
        'line_discount' => $lineDiscount,
    ];
}

if ($items === []) {
    sale_save_fail('Add at least one sale item.', $postedSaleId);
}

$subtotal = 0.0;

foreach ($items as $item) {
    $subtotal += ($item['quantity'] * $item['unit_price']) - $item['line_discount'];
}

if ($discount > $subtotal) {
    sale_save_fail('Invoice discount cannot be higher than subtotal.', $postedSaleId);
}

$total = $subtotal - $discount + $tax;

if ($paid > $total) {
    sale_save_fail('Paid amount cannot be higher than invoice total.', $postedSaleId);
}

try {
    $pdo->beginTransaction();

    $existingSale = $postedSaleId > 0 ? sale_save_fetch_sale($pdo, $postedSaleId) : null;

    if ($postedSaleId > 0 && ! is_array($existingSale)) {
        throw new RuntimeException('Invoice was not found.');
    }

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

    $linkedPaymentTotal = $postedSaleId > 0 ? sale_save_linked_payment_total($pdo, $postedSaleId) : 0.0;

    if ($paid < $linkedPaymentTotal) {
        throw new RuntimeException('Paid amount cannot be less than recorded payment receipts.');
    }

    $returnTotals = $postedSaleId > 0 ? sale_save_return_totals($pdo, $postedSaleId) : ['returned_total' => 0.0, 'refund_total' => 0.0];
    $status = sale_save_status($total, $paid, (float) $returnTotals['returned_total'], (float) $returnTotals['refund_total']);
    $invoiceNo = is_array($existingSale) ? (string) $existingSale['invoice_no'] : next_sale_invoice_no($pdo);
    $storedSaleDate = is_array($existingSale) && ! $canChangeSaleDate
        ? (string) $existingSale['sale_date']
        : str_replace('T', ' ', $saleDate) . ':00';

    if (is_array($existingSale)) {
        $saleStatement = $pdo->prepare(
            'UPDATE sales
             SET customer_id = :customer_id,
                 sale_date = :sale_date,
                 subtotal = :subtotal,
                 discount = :discount,
                 tax = :tax,
                 total = :total,
                 paid = :paid,
                 payment_method = :payment_method,
                 status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $saleStatement->execute([
            'customer_id' => $customerId,
            'sale_date' => $storedSaleDate,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'paid' => $paid,
            'payment_method' => $paymentMethod,
            'status' => $status,
            'id' => $postedSaleId,
        ]);

        $saleId = $postedSaleId;
        sale_save_sync_linked_customers($pdo, $saleId, $customerId);
    } else {
        $saleStatement = $pdo->prepare(
            'INSERT INTO sales
                (customer_id, invoice_no, sale_date, subtotal, discount, tax, total, paid, payment_method, status)
             VALUES
                (:customer_id, :invoice_no, :sale_date, :subtotal, :discount, :tax, :total, :paid, :payment_method, :status)'
        );
        $saleStatement->execute([
            'customer_id' => $customerId,
            'invoice_no' => $invoiceNo,
            'sale_date' => $storedSaleDate,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'paid' => $paid,
            'payment_method' => $paymentMethod,
            'status' => $status,
        ]);
        $saleId = (int) $pdo->lastInsertId();
    }

    sale_save_apply_items($pdo, $saleId, $invoiceNo, $items, (int) ($currentUser['id'] ?? 0) ?: null);
    sale_save_recalculate_return_items($pdo, $saleId);
    sale_save_validate_return_refunds($pdo, $saleId);
    sale_save_recalculate_status($pdo, $saleId);

    $pdo->commit();

    unset($_SESSION['sale_form_old']);
    app_log_activity(
        $pdo,
        $currentUser,
        is_array($existingSale) ? 'sale_update' : 'sale_create',
        (is_array($existingSale) ? 'Updated invoice ' : 'Created invoice ') . $invoiceNo . ' for ' . format_money($total) . '.'
    );
    set_flash('success', (is_array($existingSale) ? 'Invoice updated: ' : 'Sale saved as invoice ') . $invoiceNo . '.');

    if ($afterSave === 'print') {
        redirect('?page=sale-view&id=' . $saleId . '&print=1');
    }

    redirect(is_array($existingSale) ? '?page=sale-view&id=' . $saleId : '?page=sales');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    sale_save_fail($exception instanceof RuntimeException ? $exception->getMessage() : 'Sale could not be saved.', $postedSaleId);
}

function sale_save_fail(string $message, int $saleId = 0): never
{
    $_SESSION['sale_form_old'] = sale_save_old_input();
    set_flash('error', $message);
    redirect($saleId > 0 ? '?page=sales&edit=' . $saleId : '?page=sales');
}

function sale_save_old_input(): array
{
    $scalarKeys = [
        'sale_id',
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
        'sale_item_id',
        'product_id',
        'product_search',
        'custom_item_name',
        'custom_item_cost',
        'quantity',
        'unit_price',
        'warranty_months',
        'line_discount',
        'original_product_id',
        'original_quantity',
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

function sale_save_fetch_sale(PDO $pdo, int $saleId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM sales WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $saleId]);
    $sale = $statement->fetch();

    return is_array($sale) ? $sale : null;
}

function sale_save_linked_payment_total(PDO $pdo, int $saleId): float
{
    $statement = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE sale_id = :sale_id');
    $statement->execute(['sale_id' => $saleId]);

    return round((float) $statement->fetchColumn(), 2);
}

function sale_save_return_totals(PDO $pdo, int $saleId): array
{
    $statement = $pdo->prepare(
        'SELECT COALESCE(SUM(ri.returned_total), 0) AS returned_total,
                COALESCE(SUM(sr.refund_amount), 0) AS refund_total
         FROM sales_returns sr
         LEFT JOIN (
            SELECT return_id, COALESCE(SUM(total), 0) AS returned_total
            FROM sales_return_items
            GROUP BY return_id
         ) ri ON ri.return_id = sr.id
         WHERE sr.sale_id = :sale_id'
    );
    $statement->execute(['sale_id' => $saleId]);
    $totals = $statement->fetch() ?: [];

    return [
        'returned_total' => (float) ($totals['returned_total'] ?? 0),
        'refund_total' => (float) ($totals['refund_total'] ?? 0),
    ];
}

function sale_save_status(float $total, float $paid, float $returnedTotal = 0.0, float $refundTotal = 0.0): string
{
    $balance = sale_receivable_balance($total, $paid, $returnedTotal, $refundTotal);

    if ($balance <= 0.0) {
        return 'paid';
    }

    return $paid > 0.0 ? 'partial' : 'credit';
}

function sale_save_sync_linked_customers(PDO $pdo, int $saleId, ?int $customerId): void
{
    $payments = $pdo->prepare('UPDATE customer_payments SET customer_id = :customer_id WHERE sale_id = :sale_id');
    $payments->execute([
        'customer_id' => $customerId,
        'sale_id' => $saleId,
    ]);

    $returns = $pdo->prepare('UPDATE sales_returns SET customer_id = :customer_id WHERE sale_id = :sale_id');
    $returns->execute([
        'customer_id' => $customerId,
        'sale_id' => $saleId,
    ]);

    $warranty = $pdo->prepare('UPDATE warranty_claims SET customer_id = :customer_id WHERE sale_id = :sale_id');
    $warranty->execute([
        'customer_id' => $customerId,
        'sale_id' => $saleId,
    ]);
}

function sale_save_apply_items(PDO $pdo, int $saleId, string $invoiceNo, array $items, ?int $userId): void
{
    $existingItems = sale_save_existing_items($pdo, $saleId);
    $dependencies = sale_save_item_dependencies($pdo, $saleId);
    $submittedExistingIds = [];
    $productIds = [];

    foreach ($existingItems as $existingItem) {
        if ($existingItem['product_id'] !== null) {
            $productIds[] = (int) $existingItem['product_id'];
        }
    }

    foreach ($items as $item) {
        $saleItemId = (int) $item['sale_item_id'];

        if ($saleItemId > 0) {
            if (! isset($existingItems[$saleItemId])) {
                throw new RuntimeException('One invoice item was not found.');
            }

            $submittedExistingIds[$saleItemId] = true;
            sale_save_validate_dependent_item($item, $existingItems[$saleItemId], $dependencies[$saleItemId] ?? null);
        }

        if ($item['product_id'] !== null) {
            $productIds[] = (int) $item['product_id'];
        }
    }

    foreach ($existingItems as $existingItemId => $existingItem) {
        if (isset($submittedExistingIds[$existingItemId])) {
            continue;
        }

        if (sale_save_dependency_units($dependencies[$existingItemId] ?? null) > 0) {
            throw new RuntimeException('Invoice items with returns or warranty claims cannot be removed.');
        }
    }

    $products = sale_save_lock_products($pdo, $productIds);
    $stockDeltas = [];
    $oldCostByProduct = [];

    foreach ($existingItems as $existingItem) {
        $productId = $existingItem['product_id'] !== null ? (int) $existingItem['product_id'] : 0;

        if ($productId <= 0) {
            continue;
        }

        $stockDeltas[$productId] = ($stockDeltas[$productId] ?? 0) - (int) $existingItem['quantity'];
        $oldCostByProduct[$productId]['quantity'] = ($oldCostByProduct[$productId]['quantity'] ?? 0) + (int) $existingItem['quantity'];
        $oldCostByProduct[$productId]['total'] = ($oldCostByProduct[$productId]['total'] ?? 0.0) + ((int) $existingItem['quantity'] * (float) $existingItem['unit_cost']);
    }

    $itemUnitCosts = [];

    foreach ($items as $index => $item) {
        $productId = $item['product_id'] !== null ? (int) $item['product_id'] : 0;

        if ($productId <= 0) {
            $itemUnitCosts[$index] = (float) $item['unit_cost'];
            continue;
        }

        if (! isset($products[$productId])) {
            throw new RuntimeException('One of the selected products was not found.');
        }

        $existingItem = (int) $item['sale_item_id'] > 0 ? ($existingItems[(int) $item['sale_item_id']] ?? null) : null;
        $isSameExistingProduct = is_array($existingItem) && (int) ($existingItem['product_id'] ?? 0) === $productId;

        if (! $isSameExistingProduct && (string) $products[$productId]['status'] !== 'active') {
            throw new RuntimeException('One of the selected products is not active.');
        }

        $stockDeltas[$productId] = ($stockDeltas[$productId] ?? 0) + (int) $item['quantity'];
        $itemUnitCosts[$index] = sale_save_calculate_item_unit_cost($pdo, $products[$productId], $item, $existingItem);
    }

    sale_save_apply_stock_deltas($pdo, $products, $stockDeltas, $oldCostByProduct, $invoiceNo, $saleId, $userId);
    sale_save_persist_items($pdo, $saleId, $items, $itemUnitCosts, $submittedExistingIds);
}

function sale_save_existing_items(PDO $pdo, int $saleId): array
{
    $statement = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = :sale_id ORDER BY id ASC FOR UPDATE');
    $statement->execute(['sale_id' => $saleId]);
    $items = [];

    foreach ($statement->fetchAll() as $item) {
        $items[(int) $item['id']] = $item;
    }

    return $items;
}

function sale_save_item_dependencies(PDO $pdo, int $saleId): array
{
    $statement = $pdo->prepare(
        'SELECT si.id,
                COALESCE(ret.returned_quantity, 0) AS returned_quantity,
                COALESCE(wc.claim_count, 0) AS claim_count
         FROM sale_items si
         LEFT JOIN (
            SELECT sale_item_id, COALESCE(SUM(quantity), 0) AS returned_quantity
            FROM sales_return_items
            GROUP BY sale_item_id
         ) ret ON ret.sale_item_id = si.id
         LEFT JOIN (
            SELECT sale_item_id, COUNT(*) AS claim_count
            FROM warranty_claims
            WHERE sale_item_id IS NOT NULL
            GROUP BY sale_item_id
         ) wc ON wc.sale_item_id = si.id
         WHERE si.sale_id = :sale_id'
    );
    $statement->execute(['sale_id' => $saleId]);
    $dependencies = [];

    foreach ($statement->fetchAll() as $row) {
        $dependencies[(int) $row['id']] = [
            'returned_quantity' => (int) $row['returned_quantity'],
            'claim_count' => (int) $row['claim_count'],
        ];
    }

    return $dependencies;
}

function sale_save_validate_dependent_item(array $item, array $existingItem, ?array $dependency): void
{
    if (sale_save_dependency_units($dependency) <= 0) {
        return;
    }

    $oldProductId = $existingItem['product_id'] !== null ? (int) $existingItem['product_id'] : null;
    $newProductId = $item['product_id'] !== null ? (int) $item['product_id'] : null;

    if ($oldProductId !== $newProductId) {
        throw new RuntimeException('Invoice items with returns or warranty claims cannot change product.');
    }

    $minimumQuantity = sale_save_dependency_units($dependency);

    if ((int) $item['quantity'] < $minimumQuantity) {
        throw new RuntimeException('Invoice item quantity cannot be lower than linked returns or warranty claims.');
    }
}

function sale_save_dependency_units(?array $dependency): int
{
    if (! is_array($dependency)) {
        return 0;
    }

    return (int) ($dependency['returned_quantity'] ?? 0) + (int) ($dependency['claim_count'] ?? 0);
}

function sale_save_lock_products(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));

    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $statement = $pdo->prepare(
        'SELECT id, name, current_stock, cost_price, unlimited_stock, status
         FROM products
         WHERE id IN (' . $placeholders . ')
         FOR UPDATE'
    );
    $statement->execute($productIds);
    $products = [];

    foreach ($statement->fetchAll() as $product) {
        $products[(int) $product['id']] = $product;
    }

    return $products;
}

function sale_save_calculate_item_unit_cost(PDO $pdo, array $product, array $item, ?array $existingItem): float
{
    $productId = (int) $product['id'];
    $quantity = (int) $item['quantity'];
    $fallbackCost = (float) $product['cost_price'];

    if ((int) ($product['unlimited_stock'] ?? 0) === 1) {
        return $fallbackCost;
    }

    if (is_array($existingItem) && (int) ($existingItem['product_id'] ?? 0) === $productId) {
        $oldQuantity = (int) $existingItem['quantity'];
        $oldUnitCost = (float) $existingItem['unit_cost'];

        if ($quantity <= $oldQuantity) {
            return $oldUnitCost;
        }

        $extraQuantity = $quantity - $oldQuantity;
        $extraUnitCost = sale_fifo_unit_cost($pdo, $productId, $extraQuantity, $fallbackCost);

        return round((($oldQuantity * $oldUnitCost) + ($extraQuantity * $extraUnitCost)) / $quantity, 2);
    }

    return sale_fifo_unit_cost($pdo, $productId, $quantity, $fallbackCost);
}

function sale_save_apply_stock_deltas(PDO $pdo, array $products, array $stockDeltas, array $oldCostByProduct, string $invoiceNo, int $saleId, ?int $userId): void
{
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
            (:product_id, :movement_type, :quantity_change, :stock_after, :unit_cost, "sale", :reference_id, :notes, :created_by)'
    );

    foreach ($stockDeltas as $productId => $delta) {
        $delta = (int) $delta;

        if ($delta === 0) {
            continue;
        }

        if (! isset($products[$productId]) || (int) ($products[$productId]['unlimited_stock'] ?? 0) === 1) {
            continue;
        }

        $currentStock = (int) $products[$productId]['current_stock'];

        if ($delta > 0 && $delta > $currentStock) {
            throw new RuntimeException($products[$productId]['name'] . ' has only ' . $currentStock . ' in stock.');
        }

        $newStock = $currentStock - $delta;
        $quantityChange = -$delta;
        $unitCost = $delta > 0
            ? sale_fifo_unit_cost($pdo, (int) $productId, $delta, (float) $products[$productId]['cost_price'])
            : sale_save_old_product_unit_cost($oldCostByProduct[$productId] ?? null, (float) $products[$productId]['cost_price']);

        $stockUpdate->execute([
            'current_stock' => $newStock,
            'id' => $productId,
        ]);

        $movementStatement->execute([
            'product_id' => $productId,
            'movement_type' => $delta > 0 ? 'sale' : 'return_in',
            'quantity_change' => $quantityChange,
            'stock_after' => $newStock,
            'unit_cost' => $unitCost,
            'reference_id' => $saleId,
            'notes' => $delta > 0
                ? 'Invoice edit added ' . $delta . ' unit(s) on invoice ' . $invoiceNo
                : 'Invoice edit restored ' . abs($delta) . ' unit(s) from invoice ' . $invoiceNo,
            'created_by' => $userId,
        ]);
    }
}

function sale_save_old_product_unit_cost(?array $oldCost, float $fallbackCost): float
{
    if (! is_array($oldCost) || (int) ($oldCost['quantity'] ?? 0) <= 0) {
        return $fallbackCost;
    }

    return round((float) $oldCost['total'] / (int) $oldCost['quantity'], 2);
}

function sale_save_persist_items(PDO $pdo, int $saleId, array $items, array $itemUnitCosts, array $submittedExistingIds): void
{
    $deleteStatement = $pdo->prepare('DELETE FROM sale_items WHERE sale_id = :sale_id AND id = :id');
    $updateStatement = $pdo->prepare(
        'UPDATE sale_items
         SET product_id = :product_id,
             item_name = :item_name,
             quantity = :quantity,
             unit_price = :unit_price,
             unit_cost = :unit_cost,
             warranty_months = :warranty_months,
             discount = :discount,
             total = :total
         WHERE sale_id = :sale_id AND id = :id'
    );
    $insertStatement = $pdo->prepare(
        'INSERT INTO sale_items
            (sale_id, product_id, item_name, quantity, unit_price, unit_cost, warranty_months, discount, total)
         VALUES
            (:sale_id, :product_id, :item_name, :quantity, :unit_price, :unit_cost, :warranty_months, :discount, :total)'
    );
    $existingItems = sale_save_existing_items($pdo, $saleId);

    foreach ($existingItems as $existingItemId => $existingItem) {
        if (! isset($submittedExistingIds[$existingItemId])) {
            $deleteStatement->execute([
                'sale_id' => $saleId,
                'id' => $existingItemId,
            ]);
        }
    }

    foreach ($items as $index => $item) {
        $lineTotal = ((int) $item['quantity'] * (float) $item['unit_price']) - (float) $item['line_discount'];
        $params = [
            'sale_id' => $saleId,
            'product_id' => $item['product_id'],
            'item_name' => $item['item_name'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (float) $item['unit_price'],
            'unit_cost' => (float) ($itemUnitCosts[$index] ?? 0),
            'warranty_months' => (int) $item['warranty_months'],
            'discount' => (float) $item['line_discount'],
            'total' => $lineTotal,
        ];

        if ((int) $item['sale_item_id'] > 0) {
            $params['id'] = (int) $item['sale_item_id'];
            $updateStatement->execute($params);
        } else {
            $insertStatement->execute($params);
        }
    }
}

function sale_save_recalculate_return_items(PDO $pdo, int $saleId): void
{
    $statement = $pdo->prepare(
        'SELECT sri.id,
                sri.quantity,
                si.total AS sale_item_total,
                si.quantity AS sale_item_quantity,
                s.subtotal AS sale_subtotal,
                s.discount AS sale_discount
         FROM sales_return_items sri
         INNER JOIN sale_items si ON si.id = sri.sale_item_id
         INNER JOIN sales s ON s.id = si.sale_id
         WHERE si.sale_id = :sale_id'
    );
    $statement->execute(['sale_id' => $saleId]);
    $updateStatement = $pdo->prepare(
        'UPDATE sales_return_items
         SET unit_price = :unit_price,
             total = :total
         WHERE id = :id'
    );

    foreach ($statement->fetchAll() as $returnItem) {
        $quantity = max(1, (int) $returnItem['quantity']);
        $unitPrice = sale_discounted_unit_price(
            $returnItem['sale_item_total'],
            $returnItem['sale_subtotal'],
            $returnItem['sale_discount'],
            max(1, (int) $returnItem['sale_item_quantity'])
        );
        $updateStatement->execute([
            'unit_price' => $unitPrice,
            'total' => round($quantity * $unitPrice, 2),
            'id' => (int) $returnItem['id'],
        ]);
    }
}

function sale_save_validate_return_refunds(PDO $pdo, int $saleId): void
{
    $statement = $pdo->prepare(
        'SELECT sr.return_no,
                sr.refund_amount,
                COALESCE(SUM(sri.total), 0) AS returned_total
         FROM sales_returns sr
         LEFT JOIN sales_return_items sri ON sri.return_id = sr.id
         WHERE sr.sale_id = :sale_id
         GROUP BY sr.id, sr.return_no, sr.refund_amount'
    );
    $statement->execute(['sale_id' => $saleId]);

    foreach ($statement->fetchAll() as $return) {
        if ((float) $return['refund_amount'] > round((float) $return['returned_total'], 2)) {
            throw new RuntimeException('Invoice edit would make refund ' . $return['return_no'] . ' higher than the returned item value.');
        }
    }
}

function sale_save_recalculate_status(PDO $pdo, int $saleId): void
{
    $statement = $pdo->prepare(
        'SELECT s.total,
                s.paid,
                COALESCE(SUM(ri.returned_total), 0) AS returned_total,
                COALESCE(SUM(sr.refund_amount), 0) AS refund_total
         FROM sales s
         LEFT JOIN sales_returns sr ON sr.sale_id = s.id
         LEFT JOIN (
            SELECT return_id, COALESCE(SUM(total), 0) AS returned_total
            FROM sales_return_items
            GROUP BY return_id
         ) ri ON ri.return_id = sr.id
         WHERE s.id = :sale_id
         GROUP BY s.id'
    );
    $statement->execute(['sale_id' => $saleId]);
    $sale = $statement->fetch();

    if (! is_array($sale)) {
        throw new RuntimeException('Invoice status could not be recalculated.');
    }

    $status = sale_save_status(
        (float) $sale['total'],
        (float) $sale['paid'],
        (float) $sale['returned_total'],
        (float) $sale['refund_total']
    );
    $updateStatement = $pdo->prepare(
        'UPDATE sales
         SET status = :status,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $updateStatement->execute([
        'status' => $status,
        'id' => $saleId,
    ]);
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
           AND quantity_change < 0
           AND (reference_type IS NULL OR reference_type <> "stock_lot")'
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
