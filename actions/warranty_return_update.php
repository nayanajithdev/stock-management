<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=warranty-returns');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before updating warranty or return records.');
    redirect('?page=warranty-returns');
}

$canManageProductCost = auth_can_view_product_cost($pdo, $currentUser ?? null);
$claimId = (int) ($_POST['claim_id'] ?? 0);
$status = (string) ($_POST['status'] ?? 'received');
$resolvedDate = trim((string) ($_POST['resolved_date'] ?? date('Y-m-d')));
$supplierNotes = nullable_string((string) ($_POST['supplier_notes'] ?? ''));
$supplierRefundAmount = max(0.0, input_decimal('supplier_refund_amount'));
$supplierRefundDate = trim((string) ($_POST['supplier_refund_date'] ?? date('Y-m-d')));
$supplierDecision = (string) ($_POST['supplier_decision'] ?? '');
$markSupplierReplacementReceived = isset($_POST['supplier_replacement_received']);
$issueCustomerReplacement = isset($_POST['customer_replacement_issued']);
$validStatuses = ['received', 'sent_to_supplier', 'ready_for_pickup', 'resolved', 'rejected'];
$finalStatuses = ['resolved', 'rejected'];
$validSupplierDecisions = ['', 'send_to_supplier', 'no_supplier_warranty'];

if ($claimId <= 0) {
    set_flash('error', 'Choose a warranty or return case to update.');
    redirect('?page=warranty-returns');
}

if ($supplierRefundAmount > 0.0 && ! $canManageProductCost) {
    set_flash('error', 'Product Cost permission is required to record supplier refund amounts.');
    redirect('?page=warranty-returns');
}

if (! in_array($status, $validStatuses, true)) {
    set_flash('error', 'Choose a valid case status.');
    redirect('?page=warranty-returns');
}

if (! in_array($supplierDecision, $validSupplierDecisions, true)) {
    set_flash('error', 'Choose a valid supplier update.');
    redirect('?page=warranty-returns');
}

try {
    if ($supplierRefundAmount > 0 && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $supplierRefundDate)) {
        throw new RuntimeException('Supplier refund date is not valid.');
    }

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
        throw new RuntimeException('Warranty or return case was not found.');
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

    if (! $canManageProductCost) {
        $supplierRefundAmount = (float) ($claim['supplier_refund_amount'] ?? 0);
        $supplierRefundDate = (string) ($claim['supplier_refund_date'] ?? date('Y-m-d'));
    }

    if ($supplierDecision === 'send_to_supplier') {
        if (in_array($supplierReplacementStatus, ['received', 'none'], true)) {
            throw new RuntimeException('Supplier handling is already finalized for this case.');
        }

        $status = 'sent_to_supplier';
        $stockChanges[] = 'sent to supplier';
    }

    if ($supplierDecision === 'no_supplier_warranty') {
        if ($supplierReplacementStatus === 'received') {
            throw new RuntimeException('Supplier replacement was already received for this case.');
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
            throw new RuntimeException('Supplier replacement was already recorded for this case.');
        }

        if ($supplierReplacementStatus === 'none') {
            throw new RuntimeException('No supplier replacement is expected for this case.');
        }

        $stockNow++;
        warranty_return_update_product_stock($pdo, $productId, $stockNow);
        warranty_return_insert_stock_movement(
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
            throw new RuntimeException('Customer replacement was already issued for this case.');
        }

        if ($customerReplacementStatus === 'refunded') {
            throw new RuntimeException('Customer was already refunded for this case.');
        }

        if ($stockNow <= 0) {
            throw new RuntimeException('Not enough stock to issue a customer replacement.');
        }

        $unitCost = warranty_return_fifo_unit_cost($pdo, $productId, 1, $productCost);
        $stockNow--;
        warranty_return_update_product_stock($pdo, $productId, $stockNow);
        warranty_return_insert_stock_movement(
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
    app_log_activity($pdo, $currentUser, 'warranty_return_update', 'Updated warranty/return case ID ' . $claimId . ' to ' . $status . $activitySuffix . '.');
    set_flash('success', 'Warranty / return case updated.');
    redirect('?page=warranty-returns');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Warranty / return case could not be updated.');
    redirect('?page=warranty-returns');
}

function warranty_return_update_product_stock(PDO $pdo, int $productId, int $stock): void
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

function warranty_return_insert_stock_movement(PDO $pdo, int $productId, string $movementType, int $quantityChange, int $stockAfter, float $unitCost, int $warrantyMonths, int $claimId, string $notes, ?int $userId): void
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

function warranty_return_fifo_unit_cost(PDO $pdo, int $productId, int $quantity, float $fallbackCost): float
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
