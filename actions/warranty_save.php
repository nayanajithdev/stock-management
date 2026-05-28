<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=warranty');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving warranty claims.');
    redirect('?page=warranty');
}

$claimId = (int) ($_POST['claim_id'] ?? 0);
$saleItemId = (int) ($_POST['sale_item_id'] ?? 0);
$status = (string) ($_POST['status'] ?? 'received');
$receivedDate = trim((string) ($_POST['received_date'] ?? date('Y-m-d')));
$resolvedDate = trim((string) ($_POST['resolved_date'] ?? date('Y-m-d')));
$issueDescription = trim((string) ($_POST['issue_description'] ?? ''));
$supplierNotes = nullable_string((string) ($_POST['supplier_notes'] ?? ''));
$supplierRefundAmount = max(0.0, input_decimal('supplier_refund_amount'));
$supplierRefundDate = trim((string) ($_POST['supplier_refund_date'] ?? date('Y-m-d')));
$validStatuses = ['received', 'sent_to_supplier', 'ready_for_pickup', 'resolved', 'rejected'];
$finalStatuses = ['resolved', 'rejected'];

if (! in_array($status, $validStatuses, true)) {
    set_flash('error', 'Choose a valid warranty status.');
    redirect('?page=warranty');
}

try {
    if ($supplierRefundAmount > 0 && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $supplierRefundDate)) {
        throw new RuntimeException('Supplier refund date is not valid.');
    }

    if ($claimId > 0) {
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
            'id' => $claimId,
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Warranty claim was not found.');
        }

        app_log_activity($pdo, $currentUser, 'warranty_update', 'Updated warranty claim ID ' . $claimId . ' to ' . $status . ($supplierRefundAmount > 0 ? ' with supplier refund ' . format_money($supplierRefundAmount) : '') . '.');
        set_flash('success', 'Warranty claim updated.');
        redirect('?page=warranty');
    }

    if ($saleItemId <= 0 || $issueDescription === '') {
        throw new RuntimeException('Choose a sold item and describe the issue.');
    }

    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedDate)) {
        throw new RuntimeException('Received date is not valid.');
    }

    $saleItemStatement = $pdo->prepare(
        'SELECT si.id AS sale_item_id,
                si.sale_id,
                si.product_id,
                s.customer_id,
                s.invoice_no,
                DATE(s.sale_date) AS sale_date,
                p.name AS product_name,
                p.warranty_months
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         INNER JOIN products p ON p.id = si.product_id
         WHERE si.id = :id
         LIMIT 1'
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

    if (in_array($status, $finalStatuses, true) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $resolvedDate)) {
        throw new RuntimeException('Resolved date is not valid.');
    }

    $claimNo = next_warranty_claim_no($pdo);
    $statement = $pdo->prepare(
        'INSERT INTO warranty_claims
            (customer_id, product_id, serial_id, sale_id, claim_no, issue_description, status, received_date, resolved_date, supplier_notes, supplier_refund_amount, supplier_refund_date)
         VALUES
            (:customer_id, :product_id, NULL, :sale_id, :claim_no, :issue_description, :status, :received_date, :resolved_date, :supplier_notes, :supplier_refund_amount, :supplier_refund_date)'
    );
    $statement->execute([
        'customer_id' => $saleItem['customer_id'],
        'product_id' => (int) $saleItem['product_id'],
        'sale_id' => (int) $saleItem['sale_id'],
        'claim_no' => $claimNo,
        'issue_description' => $issueDescription,
        'status' => $status,
        'received_date' => $receivedDate,
        'resolved_date' => in_array($status, $finalStatuses, true) ? $resolvedDate : null,
        'supplier_notes' => $supplierNotes,
        'supplier_refund_amount' => $supplierRefundAmount,
        'supplier_refund_date' => $supplierRefundAmount > 0 ? $supplierRefundDate : null,
    ]);

    app_log_activity($pdo, $currentUser, 'warranty_create', 'Created warranty claim ' . $claimNo . ' for invoice ' . $saleItem['invoice_no'] . '.');
    set_flash('success', 'Warranty claim saved as ' . $claimNo . '.');
    redirect('?page=warranty');
} catch (Throwable $exception) {
    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Warranty claim could not be saved.');
    redirect('?page=warranty' . ($saleItemId > 0 ? '&sale_item=' . $saleItemId : ''));
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
