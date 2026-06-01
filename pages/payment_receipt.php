<?php
/** @var array $config */
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$paymentId = (int) ($_GET['id'] ?? 0);
$payment = null;
$paidThroughReceipt = 0.0;
$previousBalance = 0.0;
$remainingBalance = 0.0;

if ($dbReady && $pdo !== null && $paymentId > 0) {
    $paymentStatement = $pdo->prepare(
        'SELECT cp.*,
                s.invoice_no,
                s.sale_date,
                s.total AS invoice_total,
                s.paid AS invoice_paid,
                s.customer_id AS sale_customer_id,
                COALESCE(ret.returned_total, 0) AS returned_total,
                COALESCE(ret.refund_total, 0) AS refund_total,
                c.name AS customer_name,
                c.phone AS customer_phone,
                c.email AS customer_email,
                c.address AS customer_address
         FROM customer_payments cp
         INNER JOIN sales s ON s.id = cp.sale_id
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
         LEFT JOIN customers c ON c.id = COALESCE(cp.customer_id, s.customer_id)
         WHERE cp.id = :id
         LIMIT 1'
    );
    $paymentStatement->execute(['id' => $paymentId]);
    $payment = $paymentStatement->fetch() ?: null;

    if (is_array($payment)) {
        $paymentTotalStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM customer_payments
             WHERE sale_id = :sale_id'
        );
        $paymentTotalStatement->execute(['sale_id' => (int) $payment['sale_id']]);
        $recordedPayments = (float) $paymentTotalStatement->fetchColumn();
        $initialPaid = max(0.0, (float) $payment['invoice_paid'] - $recordedPayments);

        $paidThroughStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM customer_payments
             WHERE sale_id = :sale_id
               AND (
                    payment_date < :payment_date
                    OR (payment_date = :payment_date AND id <= :id)
               )'
        );
        $paidThroughStatement->execute([
            'sale_id' => (int) $payment['sale_id'],
            'payment_date' => (string) $payment['payment_date'],
            'id' => $paymentId,
        ]);
        $paidThroughReceipt = $initialPaid + (float) $paidThroughStatement->fetchColumn();
        $previousPaid = $paidThroughReceipt - (float) $payment['amount'];
        $previousBalance = sale_receivable_balance($payment['invoice_total'], $previousPaid, $payment['returned_total'], $payment['refund_total']);
        $remainingBalance = sale_receivable_balance($payment['invoice_total'], $paidThroughReceipt, $payment['returned_total'], $payment['refund_total']);
    }
}

$receiptNo = $paymentId > 0 ? 'RCPT-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT) : '';
?>

<?php if (! $dbReady): ?>
    <p class="empty-state">Import <code>database/schema.sql</code> before viewing payment receipts.</p>
<?php elseif ($paymentId <= 0 || ! is_array($payment)): ?>
    <section class="panel">
        <p class="empty-state">Payment receipt was not found.</p>
        <a class="top-action inline-action" href="<?php echo e(app_url('?page=credit-sales')); ?>">
            <i data-lucide="arrow-left"></i>
            Back to Credit Sales
        </a>
    </section>
<?php else: ?>
    <div class="page-heading no-print">
        <div>
            <h1><?php echo e($receiptNo); ?></h1>
        </div>
        <div class="invoice-actions">
            <a class="top-action" href="<?php echo e(app_url('?page=credit-sales')); ?>">
                <i data-lucide="arrow-left"></i>
                Credit Sales
            </a>
            <a class="top-action" href="<?php echo e(app_url('?page=sale-view&id=' . (int) $payment['sale_id'])); ?>">
                <i data-lucide="file-text"></i>
                Invoice
            </a>
            <button class="top-action" type="button" onclick="window.print()">
                <i data-lucide="printer"></i>
                Print
            </button>
        </div>
    </div>

    <section class="invoice-layout">
        <article class="panel invoice-paper payment-receipt-paper" id="payment-receipt-print-area">
            <header class="invoice-header">
                <div>
                    <h2><?php echo e($config['shop_name'] ?? 'Shop'); ?></h2>
                    <?php if ((string) ($config['shop_legal_name'] ?? '') !== ''): ?>
                        <span><?php echo e($config['shop_legal_name']); ?></span>
                    <?php endif; ?>
                    <?php if ((string) ($config['shop_address'] ?? '') !== ''): ?>
                        <span><?php echo nl2br(e($config['shop_address'])); ?></span>
                    <?php endif; ?>
                    <?php if ((string) ($config['shop_phone'] ?? '') !== '' || (string) ($config['shop_email'] ?? '') !== ''): ?>
                        <span><?php echo e(trim((string) ($config['shop_phone'] ?? '') . ' ' . (string) ($config['shop_email'] ?? ''))); ?></span>
                    <?php endif; ?>
                </div>
                <div class="invoice-meta">
                    <strong>Receipt</strong>
                    <span><?php echo e($receiptNo); ?></span>
                    <small><?php echo e(date('Y-m-d H:i', strtotime((string) $payment['payment_date']))); ?></small>
                </div>
            </header>

            <section class="invoice-parties">
                <div>
                    <span>Received From</span>
                    <strong><?php echo e($payment['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                    <?php if ((string) ($payment['customer_phone'] ?? '') !== ''): ?>
                        <small><?php echo e($payment['customer_phone']); ?></small>
                    <?php endif; ?>
                    <?php if ((string) ($payment['customer_email'] ?? '') !== ''): ?>
                        <small><?php echo e($payment['customer_email']); ?></small>
                    <?php endif; ?>
                    <?php if ((string) ($payment['customer_address'] ?? '') !== ''): ?>
                        <small><?php echo nl2br(e($payment['customer_address'])); ?></small>
                    <?php endif; ?>
                </div>
                <div>
                    <span>Payment</span>
                    <strong><?php echo e(format_money($payment['amount'])); ?></strong>
                    <small>Method: <?php echo e(ucfirst((string) $payment['payment_method'])); ?></small>
                    <small>Invoice: <?php echo e($payment['invoice_no']); ?></small>
                </div>
            </section>

            <section class="payment-receipt-amount">
                <span>Amount Received</span>
                <strong><?php echo e(format_money($payment['amount'])); ?></strong>
            </section>

            <section class="invoice-summary">
                <dl>
                    <div>
                        <dt>Invoice Total</dt>
                        <dd><?php echo e(format_money($payment['invoice_total'])); ?></dd>
                    </div>
                    <div>
                        <dt>Previous Balance</dt>
                        <dd><?php echo e(format_money($previousBalance)); ?></dd>
                    </div>
                    <div class="invoice-total-row">
                        <dt>Payment Received</dt>
                        <dd><?php echo e(format_money($payment['amount'])); ?></dd>
                    </div>
                    <div>
                        <dt>Balance After Payment</dt>
                        <dd><?php echo e(format_money($remainingBalance)); ?></dd>
                    </div>
                </dl>
            </section>

            <?php if ((string) ($payment['notes'] ?? '') !== ''): ?>
                <section class="receipt-note">
                    <span>Note</span>
                    <p><?php echo e($payment['notes']); ?></p>
                </section>
            <?php endif; ?>

            <footer class="invoice-footer">
                <p>Payment received for invoice <?php echo e($payment['invoice_no']); ?>.</p>
                <?php if ((string) ($config['invoice_footer'] ?? '') !== ''): ?>
                    <small><?php echo e($config['invoice_footer']); ?></small>
                <?php endif; ?>
            </footer>
        </article>
    </section>
<?php endif; ?>
