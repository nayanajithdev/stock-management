<?php
/** @var array $config */
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$saleId = (int) ($_GET['id'] ?? 0);
$sale = null;
$items = [];
$payments = [];
$returns = [];
$warrantyClaims = [];
$invoiceHasWarranty = false;

if ($dbReady && $pdo !== null && $saleId > 0) {
    $saleStatement = $pdo->prepare(
        'SELECT s.*,
                c.name AS customer_name,
                c.phone AS customer_phone,
                c.email AS customer_email,
                c.address AS customer_address,
                COALESCE(ret.returned_total, 0) AS returned_total,
                COALESCE(ret.refund_total, 0) AS refund_total
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN (
            SELECT sr.sale_id,
                   COALESCE(SUM(ri.returned_total), 0) AS returned_total,
                   COALESCE(SUM(sr.refund_amount), 0) AS refund_total
            FROM sales_returns sr
            LEFT JOIN (
                SELECT return_id, COALESCE(SUM(total), 0) AS returned_total
                FROM sales_return_items
                GROUP BY return_id
            ) ri ON ri.return_id = sr.id
            GROUP BY sr.sale_id
         ) ret ON ret.sale_id = s.id
         WHERE s.id = :id
         LIMIT 1'
    );
    $saleStatement->execute(['id' => $saleId]);
    $sale = $saleStatement->fetch() ?: null;

    if (is_array($sale)) {
        $itemStatement = $pdo->prepare(
            'SELECT si.*,
                    p.sku,
                    si.item_name,
                    p.name AS product_name,
                    p.model
             FROM sale_items si
             LEFT JOIN products p ON p.id = si.product_id
             WHERE si.sale_id = :sale_id
             ORDER BY si.id ASC'
        );
        $itemStatement->execute(['sale_id' => $saleId]);
        $items = $itemStatement->fetchAll();
        $invoiceHasWarranty = array_reduce(
            $items,
            static fn (bool $hasWarranty, array $item): bool => $hasWarranty || (int) ($item['warranty_months'] ?? 0) > 0,
            false
        );

        $paymentStatement = $pdo->prepare(
            'SELECT *
             FROM customer_payments
             WHERE sale_id = :sale_id
             ORDER BY payment_date ASC, id ASC'
        );
        $paymentStatement->execute(['sale_id' => $saleId]);
        $payments = $paymentStatement->fetchAll();

        $returnStatement = $pdo->prepare(
            'SELECT sr.*,
                    COUNT(sri.id) AS item_count,
                    COALESCE(SUM(sri.quantity), 0) AS total_units
             FROM sales_returns sr
             LEFT JOIN sales_return_items sri ON sri.return_id = sr.id
             WHERE sr.sale_id = :sale_id
             GROUP BY sr.id
             ORDER BY sr.return_date DESC, sr.id DESC'
        );
        $returnStatement->execute(['sale_id' => $saleId]);
        $returns = $returnStatement->fetchAll();

        $warrantyStatement = $pdo->prepare(
            'SELECT wc.*,
                    p.sku,
                    p.name AS product_name
             FROM warranty_claims wc
             INNER JOIN products p ON p.id = wc.product_id
             WHERE wc.sale_id = :sale_id
             ORDER BY wc.received_date DESC, wc.id DESC'
        );
        $warrantyStatement->execute(['sale_id' => $saleId]);
        $warrantyClaims = $warrantyStatement->fetchAll();
    }
}

$balance = is_array($sale) ? sale_receivable_balance($sale['total'], $sale['paid'], $sale['returned_total'] ?? 0, $sale['refund_total'] ?? 0) : 0.0;
?>

<?php if (! $dbReady): ?>
    <p class="empty-state">Import <code>database/schema.sql</code> before viewing invoices.</p>
<?php elseif ($saleId <= 0 || ! is_array($sale)): ?>
    <section class="panel">
        <p class="empty-state">Invoice was not found.</p>
        <a class="top-action inline-action" href="<?php echo e(app_url('?page=sales')); ?>">
            <i data-lucide="arrow-left"></i>
            Back to Sales
        </a>
    </section>
<?php else: ?>
    <div class="page-heading no-print">
        <div>
            <h1><?php echo e($sale['invoice_no']); ?></h1>
        </div>
        <div class="invoice-actions">
            <a class="top-action" href="<?php echo e(app_url('?page=sales-history')); ?>">
                <i data-lucide="arrow-left"></i>
                Sales History
            </a>
            <a class="top-action" href="<?php echo e(app_url('?page=sales&edit=' . (int) $sale['id'])); ?>">
                <i data-lucide="pencil"></i>
                Edit
            </a>
            <button class="top-action" type="button" onclick="window.print()">
                <i data-lucide="printer"></i>
                Print
            </button>
        </div>
    </div>

    <section class="invoice-layout">
        <?php
        $invoiceDocument = [
            'id' => 'invoice-print-area',
            'invoice_no' => (string) $sale['invoice_no'],
            'date' => date('M j, Y', strtotime((string) $sale['sale_date'])),
            'customer_name' => (string) ($sale['customer_name'] ?? ''),
            'customer_phone' => (string) ($sale['customer_phone'] ?? ''),
            'customer_email' => (string) ($sale['customer_email'] ?? ''),
            'customer_address' => (string) ($sale['customer_address'] ?? ''),
            'subtotal' => (float) $sale['subtotal'],
            'discount' => (float) $sale['discount'],
            'tax' => (float) $sale['tax'],
            'total' => (float) $sale['total'],
            'balance' => $balance,
        ];
        $invoiceDocumentItems = array_map(
            static function (array $item): array {
                $itemName = trim((string) ($item['item_name'] ?? ''));
                return [
                    'name' => $itemName !== ''
                        ? $itemName
                        : trim((string) ($item['product_name'] ?? '')),
                    'model' => $itemName !== '' ? 'Non-stock item' : (string) ($item['model'] ?? ''),
                    'warranty_months' => (int) ($item['warranty_months'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'total' => (float) ($item['total'] ?? 0),
                ];
            },
            $items
        );
        include __DIR__ . '/../prints/invoice_body.php';
        ?>

        <aside class="invoice-side no-print">
            <article class="panel">
                <div class="panel-header compact">
                    <div>
                        <p class="panel-label">Payment Summary</p>
                        <h2><?php echo e(format_money($balance)); ?></h2>
                    </div>
                </div>
                <div class="invoice-mini-list">
                    <div><span>Total</span><strong><?php echo e(format_money($sale['total'])); ?></strong></div>
                    <div><span>Paid</span><strong><?php echo e(format_money($sale['paid'])); ?></strong></div>
                    <?php if ((float) ($sale['returned_total'] ?? 0) > 0): ?>
                        <div><span>Returned</span><strong><?php echo e(format_money($sale['returned_total'])); ?></strong></div>
                    <?php endif; ?>
                    <div><span>Balance</span><strong class="<?php echo $balance > 0 ? 'text-danger' : 'text-good'; ?>"><?php echo e(format_money($balance)); ?></strong></div>
                </div>
            </article>

            <article class="panel">
                <div class="panel-header compact">
                    <div>
                        <p class="panel-label">Payments</p>
                        <h2>Collections</h2>
                    </div>
                </div>
                <div class="invoice-mini-list">
                    <?php if ($payments === []): ?>
                        <p class="empty-state">No later payments recorded.</p>
                    <?php endif; ?>
                    <?php foreach ($payments as $payment): ?>
                        <div>
                            <span><?php echo e(date('Y-m-d H:i', strtotime((string) $payment['payment_date']))); ?></span>
                            <a class="table-title" href="<?php echo e(app_url('?page=payment-receipt&id=' . (int) $payment['id'])); ?>"><?php echo e(format_money($payment['amount'])); ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="panel">
                <div class="panel-header compact">
                    <div>
                        <p class="panel-label">Returns</p>
                        <h2>Linked returns</h2>
                    </div>
                </div>
                <div class="invoice-mini-list">
                    <?php if ($returns === []): ?>
                        <p class="empty-state">No returns for this invoice.</p>
                    <?php endif; ?>
                    <?php foreach ($returns as $return): ?>
                        <div>
                            <span><?php echo e($return['return_no']); ?> / <?php echo (int) $return['total_units']; ?> unit(s)</span>
                            <strong><?php echo e(format_money($return['refund_amount'])); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="panel">
                <div class="panel-header compact">
                    <div>
                        <p class="panel-label">Warranty</p>
                        <h2>Claims</h2>
                    </div>
                </div>
                <div class="invoice-mini-list">
                    <?php if ($warrantyClaims === []): ?>
                        <p class="empty-state">No warranty claims for this invoice.</p>
                    <?php endif; ?>
                    <?php foreach ($warrantyClaims as $claim): ?>
                        <div>
                            <span><?php echo e($claim['claim_no']); ?> / <?php echo e($claim['sku']); ?></span>
                            <strong><?php echo e(invoice_status_label((string) $claim['status'])); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        </aside>
    </section>

    <?php if ((string) ($_GET['print'] ?? '') === '1'): ?>
        <script>
            window.addEventListener('load', () => {
                window.setTimeout(() => window.print(), 250);
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php
function invoice_status_label(string $status): string
{
    return match ($status) {
        'sent_to_supplier' => 'Supplier',
        'ready_for_pickup' => 'Ready',
        'resolved' => 'Resolved',
        'rejected' => 'Rejected',
        default => 'Received',
    };
}
