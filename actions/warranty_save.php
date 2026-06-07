<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=warranty-returns');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving warranty claims.');
    redirect('?page=warranty-returns');
}

$claimId = (int) ($_POST['claim_id'] ?? 0);
$saleItemId = (int) ($_POST['sale_item_id'] ?? 0);
$redirectTo = warranty_safe_redirect((string) ($_POST['redirect_to'] ?? '?page=warranty-returns'));
$status = (string) ($_POST['status'] ?? 'received');
$receivedDate = trim((string) ($_POST['received_date'] ?? date('Y-m-d')));
$resolvedDate = trim((string) ($_POST['resolved_date'] ?? date('Y-m-d')));
$issueDescription = trim((string) ($_POST['issue_description'] ?? ''));
$supplierNotes = nullable_string((string) ($_POST['supplier_notes'] ?? ''));
$supplierRefundAmount = max(0.0, input_decimal('supplier_refund_amount'));
$supplierRefundDate = trim((string) ($_POST['supplier_refund_date'] ?? date('Y-m-d')));
$replacementMode = (string) ($_POST['replacement_mode'] ?? 'wait_supplier');
$supplierDecision = (string) ($_POST['supplier_decision'] ?? '');
$markSupplierReplacementReceived = isset($_POST['supplier_replacement_received']);
$issueCustomerReplacement = isset($_POST['customer_replacement_issued']);
$validStatuses = ['received', 'sent_to_supplier', 'ready_for_pickup', 'resolved', 'rejected'];
$finalStatuses = ['resolved', 'rejected'];
$validReplacementModes = ['replace_now', 'wait_supplier'];
$validSupplierDecisions = ['', 'send_to_supplier', 'no_supplier_warranty'];

if (! in_array($status, $validStatuses, true)) {
    set_flash('error', 'Choose a valid warranty status.');
    redirect($redirectTo);
}

if (! in_array($supplierDecision, $validSupplierDecisions, true)) {
    set_flash('error', 'Choose a valid supplier decision.');
    redirect($redirectTo);
}

try {
    if ($supplierRefundAmount > 0 && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $supplierRefundDate)) {
        throw new RuntimeException('Supplier refund date is not valid.');
    }

    if ($claimId > 0) {
        $pdo->beginTransaction();

        $claimStatement = $pdo->prepare(
            'SELECT wc.*,
                    p.name AS product_name,
                    p.current_stock,
                    p.cost_price
             FROM warranty_claims wc
             INNER JOIN products p ON p.id = wc.product_id
             WHERE wc.id = :id
             FOR UPDATE'
        );
        $claimStatement->execute(['id' => $claimId]);
        $claim = $claimStatement->fetch();

        if (! is_array($claim)) {
            throw new RuntimeException('Warranty claim was not found.');
        }

        $customerReplacementStatus = (string) ($claim['customer_replacement_status'] ?? 'pending');
        $supplierReplacementStatus = (string) ($claim['supplier_replacement_status'] ?? 'pending');
        $customerReplacedAt = $claim['customer_replaced_at'] ?? null;
        $supplierReplacedAt = $claim['supplier_replaced_at'] ?? null;
        $stockNow = (int) $claim['current_stock'];
        $productId = (int) $claim['product_id'];
        $productCost = (float) $claim['cost_price'];
        $now = date('Y-m-d H:i:s');
        $stockChanges = [];

        if ($supplierDecision === 'send_to_supplier') {
            if (in_array($supplierReplacementStatus, ['received', 'none'], true)) {
                throw new RuntimeException('Supplier handling is already finalized for this claim.');
            }

            $status = 'sent_to_supplier';
            $stockChanges[] = 'sent to supplier';
        }

        if ($supplierDecision === 'no_supplier_warranty') {
            if ($supplierReplacementStatus === 'received') {
                throw new RuntimeException('Supplier replacement was already received for this claim.');
            }

            if ($markSupplierReplacementReceived) {
                throw new RuntimeException('Cannot receive supplier replacement when there is no supplier warranty.');
            }

            $supplierReplacementStatus = 'none';
            $supplierReplacedAt = $now;
            $status = 'resolved';
            $stockChanges[] = 'no supplier warranty';
        }

        if ($markSupplierReplacementReceived) {
            if ($supplierReplacementStatus === 'received') {
                throw new RuntimeException('Supplier replacement was already recorded for this claim.');
            }

            if ($supplierReplacementStatus === 'none') {
                throw new RuntimeException('No supplier replacement is expected for this claim.');
            }

            $stockNow++;
            warranty_update_product_stock($pdo, $productId, $stockNow);
            warranty_insert_stock_movement(
                $pdo,
                $productId,
                'warranty_supplier_in',
                1,
                $stockNow,
                $productCost,
                0,
                $claimId,
                'Supplier replacement received for ' . (string) $claim['claim_no'],
                (int) ($currentUser['id'] ?? 0) ?: null
            );
            $supplierReplacementStatus = 'received';
            $supplierReplacedAt = $now;
            $stockChanges[] = 'supplier replacement received';
        }

        if ($issueCustomerReplacement) {
            if ($customerReplacementStatus === 'issued') {
                throw new RuntimeException('Customer replacement was already issued for this claim.');
            }

            if ($customerReplacementStatus === 'refunded') {
                throw new RuntimeException('Customer was already refunded for this claim.');
            }

            if ($stockNow <= 0) {
                throw new RuntimeException('Not enough stock to issue a customer replacement.');
            }

            $unitCost = warranty_fifo_unit_cost($pdo, $productId, 1, $productCost);
            $stockNow--;
            warranty_update_product_stock($pdo, $productId, $stockNow);
            warranty_insert_stock_movement(
                $pdo,
                $productId,
                'warranty_customer_out',
                -1,
                $stockNow,
                $unitCost,
                0,
                $claimId,
                'Customer replacement issued for ' . (string) $claim['claim_no'],
                (int) ($currentUser['id'] ?? 0) ?: null
            );
            $customerReplacementStatus = 'issued';
            $customerReplacedAt = $now;
            $stockChanges[] = 'customer replacement issued';
        }

        if (! in_array($status, $finalStatuses, true)) {
            $customerDone = in_array($customerReplacementStatus, ['issued', 'refunded'], true);
            $supplierDone = in_array($supplierReplacementStatus, ['received', 'none'], true);

            if ($customerDone && $supplierDone) {
                $status = 'resolved';
            } elseif ($supplierReplacementStatus === 'received') {
                $status = 'ready_for_pickup';
            } elseif ($customerDone && $status === 'received') {
                $status = 'sent_to_supplier';
            }
        }

        if (in_array($status, $finalStatuses, true) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolvedDate)) {
            throw new RuntimeException('Resolved date is not valid.');
        }

        $statement = $pdo->prepare(
            'UPDATE warranty_claims
             SET status = :status,
                 resolved_date = :resolved_date,
                 supplier_notes = CASE
                    WHEN :supplier_notes_check IS NULL THEN supplier_notes
                    WHEN supplier_notes IS NULL OR supplier_notes = "" THEN :supplier_notes_new
                    ELSE CONCAT(supplier_notes, "\n", :supplier_notes_append)
                 END,
                 supplier_refund_amount = :supplier_refund_amount,
                 supplier_refund_date = :supplier_refund_date,
                 customer_replacement_status = :customer_replacement_status,
                 customer_replaced_at = :customer_replaced_at,
                 supplier_replacement_status = :supplier_replacement_status,
                 supplier_replaced_at = :supplier_replaced_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'resolved_date' => in_array($status, $finalStatuses, true) ? $resolvedDate : null,
            'supplier_notes_check' => $supplierNotes,
            'supplier_notes_new' => $supplierNotes,
            'supplier_notes_append' => $supplierNotes,
            'supplier_refund_amount' => $supplierRefundAmount,
            'supplier_refund_date' => $supplierRefundAmount > 0 ? $supplierRefundDate : null,
            'customer_replacement_status' => $customerReplacementStatus,
            'customer_replaced_at' => $customerReplacedAt,
            'supplier_replacement_status' => $supplierReplacementStatus,
            'supplier_replaced_at' => $supplierReplacedAt,
            'id' => $claimId,
        ]);

        $pdo->commit();

        $activitySuffix = $stockChanges === [] ? '' : ' (' . implode(', ', $stockChanges) . ')';
        app_log_activity($pdo, $currentUser, 'warranty_update', 'Updated warranty claim ID ' . $claimId . ' to ' . $status . $activitySuffix . '.');
        set_flash('success', 'Warranty claim updated.');
        redirect($redirectTo);
    }

    if ($saleItemId <= 0 || $issueDescription === '') {
        throw new RuntimeException('Choose a sold item and describe the issue.');
    }

    if (! in_array($replacementMode, $validReplacementModes, true)) {
        throw new RuntimeException('Choose a valid replacement option.');
    }

    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedDate)) {
        throw new RuntimeException('Received date is not valid.');
    }

    if (in_array($status, $finalStatuses, true) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolvedDate)) {
        throw new RuntimeException('Resolved date is not valid.');
    }

    $pdo->beginTransaction();

    $saleItemStatement = $pdo->prepare(
        'SELECT si.id AS sale_item_id,
                si.sale_id,
                si.product_id,
                si.unit_cost AS sale_unit_cost,
                s.customer_id,
                s.invoice_no,
                DATE(s.sale_date) AS sale_date,
                p.name AS product_name,
                p.current_stock,
                p.cost_price,
                p.warranty_months
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         INNER JOIN products p ON p.id = si.product_id
         WHERE si.id = :id
         LIMIT 1
         FOR UPDATE'
    );
    $saleItemStatement->execute(['id' => $saleItemId]);
    $saleItem = $saleItemStatement->fetch();

    if (! is_array($saleItem)) {
        throw new RuntimeException('Selected sold item was not found.');
    }

    $warrantyMonths = (int) $saleItem['warranty_months'];

    if ($warrantyMonths <= 0) {
        throw new RuntimeException($saleItem['product_name'] . ' does not have a warranty period configured.');
    }

    $saleDate = new DateTimeImmutable((string) $saleItem['sale_date']);
    $claimDate = new DateTimeImmutable($receivedDate);
    $warrantyUntil = $saleDate->modify('+' . $warrantyMonths . ' months');

    if ($claimDate > $warrantyUntil) {
        throw new RuntimeException('Warranty expired on ' . $warrantyUntil->format('Y-m-d') . ' for invoice ' . $saleItem['invoice_no'] . '.');
    }

    if ($replacementMode === 'replace_now' && (int) $saleItem['current_stock'] <= 0) {
        throw new RuntimeException('Not enough stock to replace this item now.');
    }

    $claimNo = next_warranty_claim_no($pdo);
    $now = date('Y-m-d H:i:s');
    $customerReplacementStatus = $replacementMode === 'replace_now' ? 'issued' : 'pending';
    $customerReplacedAt = $replacementMode === 'replace_now' ? $now : null;
    $statement = $pdo->prepare(
        'INSERT INTO warranty_claims
            (customer_id, product_id, serial_id, sale_id, sale_item_id, claim_no, issue_description, status, received_date, resolved_date, supplier_notes, supplier_refund_amount, supplier_refund_date, replacement_mode, customer_replacement_status, customer_replaced_at, supplier_replacement_status, supplier_replaced_at)
         VALUES
            (:customer_id, :product_id, NULL, :sale_id, :sale_item_id, :claim_no, :issue_description, :status, :received_date, :resolved_date, :supplier_notes, :supplier_refund_amount, :supplier_refund_date, :replacement_mode, :customer_replacement_status, :customer_replaced_at, "pending", NULL)'
    );
    $statement->execute([
        'customer_id' => $saleItem['customer_id'],
        'product_id' => (int) $saleItem['product_id'],
        'sale_id' => (int) $saleItem['sale_id'],
        'sale_item_id' => (int) $saleItem['sale_item_id'],
        'claim_no' => $claimNo,
        'issue_description' => $issueDescription,
        'status' => $status,
        'received_date' => $receivedDate,
        'resolved_date' => in_array($status, $finalStatuses, true) ? $resolvedDate : null,
        'supplier_notes' => $supplierNotes,
        'supplier_refund_amount' => $supplierRefundAmount,
        'supplier_refund_date' => $supplierRefundAmount > 0 ? $supplierRefundDate : null,
        'replacement_mode' => $replacementMode,
        'customer_replacement_status' => $customerReplacementStatus,
        'customer_replaced_at' => $customerReplacedAt,
    ]);
    $newClaimId = (int) $pdo->lastInsertId();

    if ($replacementMode === 'replace_now') {
        $newStock = (int) $saleItem['current_stock'] - 1;
        $unitCost = warranty_fifo_unit_cost($pdo, (int) $saleItem['product_id'], 1, (float) $saleItem['cost_price']);
        warranty_update_product_stock($pdo, (int) $saleItem['product_id'], $newStock);
        warranty_insert_stock_movement(
            $pdo,
            (int) $saleItem['product_id'],
            'warranty_customer_out',
            -1,
            $newStock,
            $unitCost,
            0,
            $newClaimId,
            'Customer replacement issued for ' . $claimNo,
            (int) ($currentUser['id'] ?? 0) ?: null
        );
    }

    $pdo->commit();

    app_log_activity($pdo, $currentUser, 'warranty_create', 'Created warranty claim ' . $claimNo . ' for invoice ' . $saleItem['invoice_no'] . '.');
    set_flash('success', 'Warranty claim saved as ' . $claimNo . '.');
    redirect('?page=warranty-returns');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Warranty claim could not be saved.');
    redirect($claimId > 0 ? $redirectTo : '?page=warranty-returns' . ($saleItemId > 0 ? '&sale_item=' . $saleItemId : ''));
}

function warranty_safe_redirect(string $path): string
{
    $path = trim($path);

    if ($path === '?page=warranty' || $path === '?page=returns') {
        return '?page=warranty-returns';
    }

    if ($path === '' || ! str_starts_with($path, '?page=')) {
        return '?page=warranty-returns';
    }

    return $path;
}

function next_warranty_claim_no(PDO $pdo): string
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

function warranty_update_product_stock(PDO $pdo, int $productId, int $stock): void
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

function warranty_insert_stock_movement(PDO $pdo, int $productId, string $movementType, int $quantityChange, int $stockAfter, float $unitCost, int $warrantyMonths, int $claimId, string $notes, ?int $userId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO stock_movements
            (product_id, movement_type, quantity_change, stock_after, unit_cost, warranty_months, reference_type, reference_id, notes, created_by)
         VALUES
            (:product_id, :movement_type, :quantity_change, :stock_after, :unit_cost, :warranty_months, "warranty_claim", :reference_id, :notes, :created_by)'
    );
    $statement->execute([
        'product_id' => $productId,
        'movement_type' => $movementType,
        'quantity_change' => $quantityChange,
        'stock_after' => $stockAfter,
        'unit_cost' => $unitCost,
        'warranty_months' => $warrantyMonths,
        'reference_id' => $claimId,
        'notes' => $notes,
        'created_by' => $userId,
    ]);
}

function warranty_fifo_unit_cost(PDO $pdo, int $productId, int $quantity, float $fallbackCost): float
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
                ' . app_lot_unit_cost_sql('sm', 'pc') . ' AS unit_cost
         FROM stock_movements sm
         LEFT JOIN purchases pu ON sm.reference_type = "purchase" AND pu.id = sm.reference_id
         ' . app_purchase_cost_join_sql('sm', 'pc') . '
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
