<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=warranty-returns');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving warranty or return records.');
    redirect('?page=warranty-returns');
}

$saleItemId = (int) ($_POST['sale_item_id'] ?? 0);
$outcome = (string) ($_POST['outcome'] ?? '');
$quantity = max(1, input_int('quantity'));
$refundAmount = max(0.0, input_decimal('refund_amount'));
$refundMethod = (string) ($_POST['refund_method'] ?? 'cash');
$returnDate = trim((string) ($_POST['return_date'] ?? date('Y-m-d\TH:i')));
$issueDescription = trim((string) ($_POST['issue_description'] ?? ''));
$notes = nullable_string((string) ($_POST['notes'] ?? ''));
$validRefundMethods = ['cash', 'card', 'bank', 'store_credit', 'none'];
$validOutcomes = [
    'normal_restock',
    'warranty_wait_supplier',
    'warranty_refund_now',
    'warranty_replace_now',
];

if ($saleItemId <= 0 || ! in_array($outcome, $validOutcomes, true)) {
    set_flash('error', 'Choose an invoice item and handling option.');
    redirect('?page=warranty-returns');
}

if (! in_array($refundMethod, $validRefundMethods, true)) {
    set_flash('error', 'Choose a valid refund method.');
    redirect('?page=warranty-returns');
}

if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $returnDate)) {
    set_flash('error', 'Date is not valid.');
    redirect('?page=warranty-returns');
}

if ($issueDescription === '') {
    set_flash('error', 'Enter the customer issue or return reason.');
    redirect('?page=warranty-returns');
}

try {
    $pdo->beginTransaction();

    $saleItem = wr_fetch_sale_item($pdo, $saleItemId);

    if (! is_array($saleItem)) {
        throw new RuntimeException('Selected invoice item was not found.');
    }

    $available = wr_available_item_quantity($pdo, $saleItemId, (int) $saleItem['quantity']);
    $isWarrantyOutcome = in_array($outcome, ['warranty_wait_supplier', 'warranty_refund_now', 'warranty_replace_now'], true);
    $quantity = $isWarrantyOutcome ? 1 : $quantity;

    if ($quantity > $available) {
        throw new RuntimeException('Only ' . $available . ' unit(s) are available for return or claim.');
    }

    $netUnitPrice = sale_discounted_unit_price(
        $saleItem['total'],
        $saleItem['sale_subtotal'],
        $saleItem['sale_discount'],
        (int) $saleItem['quantity']
    );
    $maxRefund = $quantity * $netUnitPrice;

    if ($refundAmount > $maxRefund) {
        throw new RuntimeException('Refund amount cannot exceed ' . format_money($maxRefund) . '.');
    }

    $needsWarranty = in_array($outcome, ['warranty_wait_supplier', 'warranty_refund_now', 'warranty_replace_now'], true);

    if ($needsWarranty && ! wr_item_is_in_warranty($saleItem, str_replace('T', ' ', $returnDate) . ':00')) {
        throw new RuntimeException('This item is outside the configured warranty period.');
    }

    $returnId = null;
    $claimId = null;
    $summary = [];

    if (in_array($outcome, ['normal_restock', 'warranty_refund_now'], true)) {
        $restock = $outcome === 'normal_restock' ? 1 : 0;
        $condition = $outcome === 'normal_restock' ? 'resellable' : 'warranty';
        $returnId = wr_create_sales_return(
            $pdo,
            $saleItem,
            $saleItemId,
            $quantity,
            $netUnitPrice,
            $refundAmount,
            $refundMethod,
            $returnDate,
            $condition,
            $restock,
            $issueDescription . ($notes !== null ? "\n" . $notes : ''),
            (int) ($currentUser['id'] ?? 0) ?: null
        );
        $summary[] = 'return saved';
    }

    if (in_array($outcome, ['warranty_wait_supplier', 'warranty_refund_now', 'warranty_replace_now'], true)) {
        $claimStatus = $outcome === 'warranty_wait_supplier' ? 'sent_to_supplier' : 'received';
        $replacementMode = match ($outcome) {
            'warranty_replace_now' => 'replace_now',
            'warranty_refund_now' => 'refund_now',
            default => 'wait_supplier',
        };
        $customerReplacementStatus = match ($outcome) {
            'warranty_replace_now' => 'issued',
            'warranty_refund_now' => 'refunded',
            default => 'pending',
        };
        $supplierReplacementStatus = 'pending';
        $customerReplacedAt = in_array($customerReplacementStatus, ['issued', 'refunded'], true) ? date('Y-m-d H:i:s') : null;

        $claimSaleItemId = $outcome === 'warranty_refund_now' ? null : $saleItemId;
        $claimId = wr_create_warranty_claim(
            $pdo,
            $saleItem,
            $claimSaleItemId,
            $claimStatus,
            $returnDate,
            $issueDescription,
            $notes,
            0.0,
            null,
            $replacementMode,
            $customerReplacementStatus,
            $customerReplacedAt,
            $supplierReplacementStatus
        );
        $summary[] = 'claim saved';

        if ($outcome === 'warranty_replace_now') {
            wr_issue_customer_replacement($pdo, $saleItem, $claimId, (int) ($currentUser['id'] ?? 0) ?: null);
            $summary[] = 'stock reduced';
        }
    }

    $pdo->commit();

    app_log_activity($pdo, $currentUser, 'warranty_return_create', 'Saved warranty/return handling for invoice ' . $saleItem['invoice_no'] . ' (' . implode(', ', $summary) . ').');
    set_flash('success', 'Warranty / return record saved.');
    redirect('?page=warranty-returns');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Warranty / return record could not be saved.');
    redirect('?page=warranty-returns');
}

function wr_fetch_sale_item(PDO $pdo, int $saleItemId): ?array
{
    $statement = $pdo->prepare(
        'SELECT si.*,
                s.customer_id,
                s.invoice_no,
                DATE(s.sale_date) AS sale_date,
                s.subtotal AS sale_subtotal,
                s.discount AS sale_discount,
                p.name AS product_name,
                p.current_stock,
                p.cost_price,
                p.warranty_months
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         INNER JOIN products p ON p.id = si.product_id
         WHERE si.id = :id
         FOR UPDATE'
    );
    $statement->execute(['id' => $saleItemId]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}

function wr_available_item_quantity(PDO $pdo, int $saleItemId, int $soldQuantity): int
{
    $returnedStatement = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM sales_return_items WHERE sale_item_id = :sale_item_id');
    $returnedStatement->execute(['sale_item_id' => $saleItemId]);
    $returned = (int) $returnedStatement->fetchColumn();

    $claimedStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM warranty_claims
         WHERE sale_item_id = :sale_item_id
           AND status <> "rejected"'
    );
    $claimedStatement->execute(['sale_item_id' => $saleItemId]);
    $claimed = (int) $claimedStatement->fetchColumn();

    return max(0, $soldQuantity - $returned - $claimed);
}

function wr_item_is_in_warranty(array $saleItem, string $claimDate): bool
{
    $warrantyMonths = (int) ($saleItem['warranty_months'] ?? 0);

    if ($warrantyMonths <= 0) {
        return false;
    }

    $saleDate = new DateTimeImmutable((string) $saleItem['sale_date']);
    $date = new DateTimeImmutable($claimDate);

    return $date <= $saleDate->modify('+' . $warrantyMonths . ' months');
}

function wr_create_sales_return(PDO $pdo, array $saleItem, int $saleItemId, int $quantity, float $netUnitPrice, float $refundAmount, string $refundMethod, string $returnDate, string $condition, int $restock, string $notes, ?int $userId): int
{
    $returnNo = wr_next_sales_return_no($pdo);
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
        'condition_status' => $condition,
        'total' => $lineTotal,
    ]);

    if ($restock === 1) {
        $newStock = (int) $saleItem['current_stock'] + $quantity;
        wr_update_product_stock($pdo, (int) $saleItem['product_id'], $newStock);
        wr_insert_stock_movement($pdo, (int) $saleItem['product_id'], 'return_in', $quantity, $newStock, (float) $saleItem['unit_cost'], 'sales_return', $returnId, 'Returned from invoice ' . $saleItem['invoice_no'] . ' / ' . $condition, $userId);
    }

    wr_recalculate_sale_status($pdo, (int) $saleItem['sale_id']);

    return $returnId;
}

function wr_create_warranty_claim(PDO $pdo, array $saleItem, ?int $saleItemId, string $status, string $receivedDate, string $issueDescription, ?string $supplierNotes, float $supplierRefundAmount, ?string $supplierRefundDate, string $replacementMode, string $customerReplacementStatus, ?string $customerReplacedAt, string $supplierReplacementStatus): int
{
    $claimNo = wr_next_warranty_claim_no($pdo);
    $statement = $pdo->prepare(
        'INSERT INTO warranty_claims
            (customer_id, product_id, serial_id, sale_id, sale_item_id, claim_no, issue_description, status, received_date, resolved_date, supplier_notes, supplier_refund_amount, supplier_refund_date, replacement_mode, customer_replacement_status, customer_replaced_at, supplier_replacement_status, supplier_replaced_at)
         VALUES
            (:customer_id, :product_id, NULL, :sale_id, :sale_item_id, :claim_no, :issue_description, :status, :received_date, :resolved_date, :supplier_notes, :supplier_refund_amount, :supplier_refund_date, :replacement_mode, :customer_replacement_status, :customer_replaced_at, :supplier_replacement_status, NULL)'
    );
    $statement->execute([
        'customer_id' => $saleItem['customer_id'],
        'product_id' => (int) $saleItem['product_id'],
        'sale_id' => (int) $saleItem['sale_id'],
        'sale_item_id' => $saleItemId,
        'claim_no' => $claimNo,
        'issue_description' => $issueDescription,
        'status' => $status,
        'received_date' => substr(str_replace('T', ' ', $receivedDate), 0, 10),
        'resolved_date' => in_array($status, ['resolved', 'rejected'], true) ? date('Y-m-d') : null,
        'supplier_notes' => $supplierNotes,
        'supplier_refund_amount' => $supplierRefundAmount,
        'supplier_refund_date' => $supplierRefundAmount > 0 ? $supplierRefundDate : null,
        'replacement_mode' => $replacementMode,
        'customer_replacement_status' => $customerReplacementStatus,
        'customer_replaced_at' => $customerReplacedAt,
        'supplier_replacement_status' => $supplierReplacementStatus,
    ]);

    return (int) $pdo->lastInsertId();
}

function wr_issue_customer_replacement(PDO $pdo, array $saleItem, int $claimId, ?int $userId): void
{
    $currentStock = (int) $saleItem['current_stock'];

    if ($currentStock <= 0) {
        throw new RuntimeException('Not enough stock to issue a customer replacement.');
    }

    $unitCost = wr_fifo_unit_cost($pdo, (int) $saleItem['product_id'], 1, (float) $saleItem['cost_price']);
    $newStock = $currentStock - 1;
    wr_update_product_stock($pdo, (int) $saleItem['product_id'], $newStock);
    wr_insert_stock_movement($pdo, (int) $saleItem['product_id'], 'warranty_customer_out', -1, $newStock, $unitCost, 'warranty_claim', $claimId, 'Customer replacement issued for warranty/return claim', $userId);
}

function wr_update_product_stock(PDO $pdo, int $productId, int $stock): void
{
    $statement = $pdo->prepare(
        'UPDATE products
         SET current_stock = :current_stock,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        'current_stock' => $stock,
        'id' => $productId,
    ]);
}

function wr_insert_stock_movement(PDO $pdo, int $productId, string $movementType, int $quantityChange, int $stockAfter, float $unitCost, string $referenceType, int $referenceId, string $notes, ?int $userId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO stock_movements
            (product_id, movement_type, quantity_change, stock_after, unit_cost, reference_type, reference_id, notes, created_by)
         VALUES
            (:product_id, :movement_type, :quantity_change, :stock_after, :unit_cost, :reference_type, :reference_id, :notes, :created_by)'
    );
    $statement->execute([
        'product_id' => $productId,
        'movement_type' => $movementType,
        'quantity_change' => $quantityChange,
        'stock_after' => $stockAfter,
        'unit_cost' => $unitCost,
        'reference_type' => $referenceType,
        'reference_id' => $referenceId,
        'notes' => $notes,
        'created_by' => $userId,
    ]);
}

function wr_recalculate_sale_status(PDO $pdo, int $saleId): void
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
        throw new RuntimeException('Invoice could not be recalculated.');
    }

    $balance = sale_receivable_balance((float) $row['total'], (float) $row['paid'], (float) $row['returned_total'], (float) $row['refund_total']);
    $status = $balance <= 0.0 ? 'paid' : ((float) $row['paid'] > 0.0 ? 'partial' : 'credit');
    $statusStatement = $pdo->prepare(
        'UPDATE sales
         SET status = :status,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statusStatement->execute([
        'status' => $status,
        'id' => $saleId,
    ]);
}

function wr_next_sales_return_no(PDO $pdo): string
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

function wr_next_warranty_claim_no(PDO $pdo): string
{
    $prefix = 'RMA-' . date('Ymd') . '-';
    $statement = $pdo->prepare('SELECT claim_no FROM warranty_claims WHERE claim_no LIKE :prefix ORDER BY id DESC LIMIT 1');
    $statement->execute(['prefix' => $prefix . '%']);
    $lastClaim = (string) ($statement->fetchColumn() ?: '');
    $nextNumber = 1;

    if (preg_match('/-(\d+)$/', $lastClaim, $matches) === 1) {
        $nextNumber = (int) $matches[1] + 1;
    }

    return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
}

function wr_fifo_unit_cost(PDO $pdo, int $productId, int $quantity, float $fallbackCost): float
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
                sm.unit_cost
         FROM stock_movements sm
         LEFT JOIN purchases pu ON sm.reference_type = "purchase" AND pu.id = sm.reference_id
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
