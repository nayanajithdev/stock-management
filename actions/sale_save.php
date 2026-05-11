<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=sales');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving sales.');
    redirect('?page=sales');
}

$customerId = ($_POST['customer_id'] ?? '') !== '' ? (int) $_POST['customer_id'] : null;
$customerName = trim((string) ($_POST['customer_name'] ?? ''));
$customerPhone = nullable_string((string) ($_POST['customer_phone'] ?? ''));
$saleDate = trim((string) ($_POST['sale_date'] ?? date('Y-m-d\TH:i')));
$paymentMethod = (string) ($_POST['payment_method'] ?? 'cash');
$discount = max(0.0, input_decimal('discount'));
$tax = max(0.0, input_decimal('tax'));
$paid = max(0.0, input_decimal('paid'));
$productIds = $_POST['product_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unitPrices = $_POST['unit_price'] ?? [];
$lineDiscounts = $_POST['line_discount'] ?? [];
$validPaymentMethods = ['cash', 'card', 'bank', 'credit'];

if (! in_array($paymentMethod, $validPaymentMethods, true)) {
    set_flash('error', 'Choose a valid payment method.');
    redirect('?page=sales');
}

if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $saleDate)) {
    set_flash('error', 'Sale date is not valid.');
    redirect('?page=sales');
}

if (! is_array($productIds) || ! is_array($quantities) || ! is_array($unitPrices) || ! is_array($lineDiscounts)) {
    set_flash('error', 'Sale items are not valid.');
    redirect('?page=sales');
}

$items = [];

foreach ($productIds as $index => $rawProductId) {
    $productId = (int) $rawProductId;
    $quantity = max(0, (int) ($quantities[$index] ?? 0));
    $unitPrice = str_replace(',', '', trim((string) ($unitPrices[$index] ?? '0')));
    $unitPrice = is_numeric($unitPrice) ? max(0.0, (float) $unitPrice) : 0.0;
    $lineDiscount = str_replace(',', '', trim((string) ($lineDiscounts[$index] ?? '0')));
    $lineDiscount = is_numeric($lineDiscount) ? max(0.0, (float) $lineDiscount) : 0.0;

    if ($productId <= 0 && $quantity === 0 && $unitPrice <= 0) {
        continue;
    }

    if ($productId <= 0 || $quantity <= 0 || $unitPrice <= 0) {
        set_flash('error', 'Each sale line needs a product, quantity, and unit price.');
        redirect('?page=sales');
    }

    $lineSubtotal = $quantity * $unitPrice;

    if ($lineDiscount > $lineSubtotal) {
        set_flash('error', 'Line discount cannot be higher than the line subtotal.');
        redirect('?page=sales');
    }

    $items[] = [
        'product_id' => $productId,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'line_discount' => $lineDiscount,
    ];
}

if ($items === []) {
    set_flash('error', 'Add at least one sale item.');
    redirect('?page=sales');
}

$subtotal = 0.0;

foreach ($items as $item) {
    $subtotal += ($item['quantity'] * $item['unit_price']) - $item['line_discount'];
}

if ($discount > $subtotal) {
    set_flash('error', 'Invoice discount cannot be higher than subtotal.');
    redirect('?page=sales');
}

$total = $subtotal - $discount + $tax;

if ($paid > $total) {
    set_flash('error', 'Paid amount cannot be higher than invoice total.');
    redirect('?page=sales');
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
            (sale_id, product_id, quantity, unit_price, unit_cost, discount, total)
         VALUES
            (:sale_id, :product_id, :quantity, :unit_price, :unit_cost, :discount, :total)'
    );
    $stockUpdate = $pdo->prepare(
        'UPDATE products
         SET current_stock = :current_stock,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $movementStatement = $pdo->prepare(
        'INSERT INTO stock_movements
            (product_id, movement_type, quantity_change, stock_after, unit_cost, reference_type, reference_id, notes)
         VALUES
            (:product_id, "sale", :quantity_change, :stock_after, :unit_cost, "sale", :reference_id, :notes)'
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

        $itemStatement->execute([
            'sale_id' => $saleId,
            'product_id' => $item['product_id'],
            'quantity' => $quantity,
            'unit_price' => $item['unit_price'],
            'unit_cost' => (float) $product['cost_price'],
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
            'unit_cost' => (float) $product['cost_price'],
            'reference_id' => $saleId,
            'notes' => 'Sold on invoice ' . $invoiceNo,
        ]);
    }

    $pdo->commit();

    set_flash('success', 'Sale saved as invoice ' . $invoiceNo . '.');
    redirect('?page=sales');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Sale could not be saved.');
    redirect('?page=sales');
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
