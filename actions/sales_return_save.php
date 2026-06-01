<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=returns');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving returns.');
    redirect('?page=returns');
}

$saleItemId = (int) ($_POST['sale_item_id'] ?? 0);
$quantity = input_int('quantity');
$refundAmount = max(0.0, input_decimal('refund_amount'));
$refundMethod = (string) ($_POST['refund_method'] ?? 'cash');
$returnDate = trim((string) ($_POST['return_date'] ?? date('Y-m-d\TH:i')));
$conditionStatus = (string) ($_POST['condition_status'] ?? 'resellable');
$restock = isset($_POST['restock']) ? 1 : 0;
$notes = trim((string) ($_POST['notes'] ?? ''));
$validRefundMethods = ['cash', 'card', 'bank', 'store_credit', 'none'];
$validConditions = ['resellable', 'opened', 'damaged', 'warranty'];

if ($saleItemId <= 0 || $quantity <= 0) {
    set_flash('error', 'Choose a sold item and return quantity.');
    redirect('?page=returns');
}

if (! in_array($refundMethod, $validRefundMethods, true)) {
    set_flash('error', 'Choose a valid refund method.');
    redirect('?page=returns&sale_item=' . $saleItemId);
}

if (! in_array($conditionStatus, $validConditions, true)) {
    set_flash('error', 'Choose a valid return condition.');
    redirect('?page=returns&sale_item=' . $saleItemId);
}

if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $returnDate)) {
    set_flash('error', 'Return date is not valid.');
    redirect('?page=returns&sale_item=' . $saleItemId);
}

if ($notes === '') {
    set_flash('error', 'Return reason or notes are required.');
    redirect('?page=returns&sale_item=' . $saleItemId);
}

try {
    $pdo->beginTransaction();

    $itemStatement = $pdo->prepare(
        'SELECT si.*,
                s.customer_id,
                s.invoice_no,
                s.subtotal AS sale_subtotal,
                s.discount AS sale_discount,
                p.name AS product_name,
                p.current_stock,
                p.cost_price
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         INNER JOIN products p ON p.id = si.product_id
         WHERE si.id = :id
         FOR UPDATE'
    );
    $itemStatement->execute(['id' => $saleItemId]);
    $saleItem = $itemStatement->fetch();

    if (! is_array($saleItem)) {
        throw new RuntimeException('Selected sale item was not found.');
    }

    $returnedStatement = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM sales_return_items WHERE sale_item_id = :sale_item_id');
    $returnedStatement->execute(['sale_item_id' => $saleItemId]);
    $alreadyReturned = (int) $returnedStatement->fetchColumn();
    $availableToReturn = (int) $saleItem['quantity'] - $alreadyReturned;

    if ($quantity > $availableToReturn) {
        throw new RuntimeException('Only ' . $availableToReturn . ' unit(s) are still returnable for this line.');
    }

    $netUnitPrice = sale_discounted_unit_price(
        $saleItem['total'],
        $saleItem['sale_subtotal'],
        $saleItem['sale_discount'],
        (int) $saleItem['quantity']
    );
    $maxRefund = $quantity * $netUnitPrice;

    if ($refundAmount > $maxRefund) {
        throw new RuntimeException('Refund amount cannot exceed ' . format_money($maxRefund) . ' for this return quantity.');
    }

    $returnNo = next_sales_return_no($pdo);
    $returnStatement = $pdo->prepare(
        'INSERT INTO sales_returns
            (sale_id, customer_id, return_no, return_date, refund_amount, refund_method, notes, status)
         VALUES
            (:sale_id, :customer_id, :return_no, :return_date, :refund_amount, :refund_method, :notes, "completed")'
    );
    $returnStatement->execute([
        'sale_id' => (int) $saleItem['sale_id'],
        'customer_id' => $saleItem['customer_id'],
        'return_no' => $returnNo,
        'return_date' => str_replace('T', ' ', $returnDate) . ':00',
        'refund_amount' => $refundAmount,
        'refund_method' => $refundMethod,
        'notes' => $notes,
    ]);
    $returnId = (int) $pdo->lastInsertId();
    $lineTotal = $quantity * $netUnitPrice;

    $returnItemStatement = $pdo->prepare(
        'INSERT INTO sales_return_items
            (return_id, sale_item_id, product_id, quantity, unit_price, unit_cost, restock, condition_status, total)
         VALUES
            (:return_id, :sale_item_id, :product_id, :quantity, :unit_price, :unit_cost, :restock, :condition_status, :total)'
    );
    $returnItemStatement->execute([
        'return_id' => $returnId,
        'sale_item_id' => $saleItemId,
        'product_id' => (int) $saleItem['product_id'],
        'quantity' => $quantity,
        'unit_price' => $netUnitPrice,
        'unit_cost' => (float) $saleItem['unit_cost'],
        'restock' => $restock,
        'condition_status' => $conditionStatus,
        'total' => $lineTotal,
    ]);

    if ($restock === 1) {
        $newStock = (int) $saleItem['current_stock'] + $quantity;

        $updateProduct = $pdo->prepare(
            'UPDATE products
             SET current_stock = :current_stock,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $updateProduct->execute([
            'current_stock' => $newStock,
            'id' => (int) $saleItem['product_id'],
        ]);

        $movementStatement = $pdo->prepare(
            'INSERT INTO stock_movements
                (product_id, movement_type, quantity_change, stock_after, unit_cost, reference_type, reference_id, notes, created_by)
             VALUES
                (:product_id, "return_in", :quantity_change, :stock_after, :unit_cost, "sales_return", :reference_id, :notes, :created_by)'
        );
        $movementStatement->execute([
            'product_id' => (int) $saleItem['product_id'],
            'quantity_change' => $quantity,
            'stock_after' => $newStock,
            'unit_cost' => (float) $saleItem['unit_cost'],
            'reference_id' => $returnId,
            'notes' => 'Returned from invoice ' . $saleItem['invoice_no'] . ' / ' . $conditionStatus,
            'created_by' => (int) ($currentUser['id'] ?? 0) ?: null,
        ]);
    }

    $saleAdjustment = sales_return_sale_adjustment($pdo, (int) $saleItem['sale_id']);
    $balanceAfterReturn = sale_receivable_balance(
        (float) $saleAdjustment['total'],
        (float) $saleAdjustment['paid'],
        (float) $saleAdjustment['returned_total'],
        (float) $saleAdjustment['refund_total']
    );
    $saleStatusAfterReturn = $balanceAfterReturn <= 0.0 ? 'paid' : ((float) $saleAdjustment['paid'] > 0.0 ? 'partial' : 'credit');
    $statusStatement = $pdo->prepare(
        'UPDATE sales
         SET status = :status,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statusStatement->execute([
        'status' => $saleStatusAfterReturn,
        'id' => (int) $saleItem['sale_id'],
    ]);

    $pdo->commit();

    app_log_activity($pdo, $currentUser, 'return_create', 'Saved return ' . $returnNo . ' for invoice ' . $saleItem['invoice_no'] . '.');
    set_flash('success', 'Return saved as ' . $returnNo . '.');
    redirect('?page=returns');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Return could not be saved.');
    redirect('?page=returns&sale_item=' . $saleItemId);
}

function next_sales_return_no(PDO $pdo): string
{
    $prefix = 'SR-' . date('Ymd') . '-';
    $statement = $pdo->prepare('SELECT return_no FROM sales_returns WHERE return_no LIKE :prefix ORDER BY id DESC LIMIT 1');
    $statement->execute(['prefix' => $prefix . '%']);
    $lastReturn = (string) ($statement->fetchColumn() ?: '');
    $nextNumber = 1;

    if (preg_match('/-(\d+)$/', $lastReturn, $matches) === 1) {
        $nextNumber = (int) $matches[1] + 1;
    }

    return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
}

function sales_return_sale_adjustment(PDO $pdo, int $saleId): array
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
    $row = $statement->fetch();

    if (! is_array($row)) {
        throw new RuntimeException('Invoice could not be recalculated after return.');
    }

    return $row;
}
