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
                    p.name AS product_name,
                    p.model,
                    p.warranty_months
             FROM sale_items si
             INNER JOIN products p ON p.id = si.product_id
             WHERE si.sale_id = :sale_id
             ORDER BY si.id ASC'
        );
        $itemStatement->execute(['sale_id' => $saleId]);
        $items = $itemStatement->fetchAll();

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
            <a class="top-action" href="<?php echo e(app_url('?page=sales')); ?>">
                <i data-lucide="arrow-left"></i>
                Sales
            </a>
            <button class="top-action" type="button" onclick="window.print()">
                <i data-lucide="printer"></i>
                Print
            </button>
        </div>
    </div>

    <section class="invoice-layout">
        <article class="panel invoice-paper" id="invoice-print-area">
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
                    <strong>Invoice</strong>
                    <span><?php echo e($sale['invoice_no']); ?></span>
                    <small><?php echo e(date('Y-m-d H:i', strtotime((string) $sale['sale_date']))); ?></small>
                </div>
            </header>

            <section class="invoice-parties">
                <div>
                    <span>Customer</span>
                    <strong><?php echo e($sale['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                    <?php if ((string) ($sale['customer_phone'] ?? '') !== ''): ?>
                        <small><?php echo e($sale['customer_phone']); ?></small>
                    <?php endif; ?>
                    <?php if ((string) ($sale['customer_email'] ?? '') !== ''): ?>
                        <small><?php echo e($sale['customer_email']); ?></small>
                    <?php endif; ?>
                    <?php if ((string) ($sale['customer_address'] ?? '') !== ''): ?>
                        <small><?php echo nl2br(e($sale['customer_address'])); ?></small>
                    <?php endif; ?>
                </div>
                <div>
                    <span>Payment</span>
                    <strong><?php echo e(ucfirst((string) $sale['payment_method'])); ?></strong>
                    <small>Status: <?php echo e(ucfirst((string) $sale['status'])); ?></small>
                </div>
            </section>

            <div class="invoice-table">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Warranty</th>
                            <th>Qty</th>
                            <th>Price</th>
                            <th>Disc.</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <strong class="table-title"><?php echo e($item['sku'] . ' - ' . $item['product_name']); ?></strong>
                                    <span class="table-subtitle"><?php echo e($item['model'] ?? ''); ?></span>
                                </td>
                                <td><?php echo (int) $item['warranty_months']; ?> mo.</td>
                                <td><?php echo (int) $item['quantity']; ?></td>
                                <td><?php echo e(format_money($item['unit_price'])); ?></td>
                                <td><?php echo e(format_money($item['discount'])); ?></td>
                                <td><?php echo e(format_money($item['total'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <section class="invoice-summary">
                <dl>
                    <div>
                        <dt>Subtotal</dt>
                        <dd><?php echo e(format_money($sale['subtotal'])); ?></dd>
                    </div>
                    <div>
                        <dt>Discount</dt>
                        <dd><?php echo e(format_money($sale['discount'])); ?></dd>
                    </div>
                    <div>
                        <dt>Tax</dt>
                        <dd><?php echo e(format_money($sale['tax'])); ?></dd>
                    </div>
                    <div class="invoice-total-row">
                        <dt>Total</dt>
                        <dd><?php echo e(format_money($sale['total'])); ?></dd>
                    </div>
                    <div>
                        <dt>Paid</dt>
                        <dd><?php echo e(format_money($sale['paid'])); ?></dd>
                    </div>
                    <?php if ((float) ($sale['returned_total'] ?? 0) > 0): ?>
                        <div>
                            <dt>Returned</dt>
                            <dd><?php echo e(format_money($sale['returned_total'])); ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt>Balance</dt>
                        <dd><?php echo e(format_money($balance)); ?></dd>
                    </div>
                </dl>
            </section>

            <footer class="invoice-footer">
                <?php if ((string) ($config['invoice_footer'] ?? '') !== ''): ?>
                    <p><?php echo e($config['invoice_footer']); ?></p>
                <?php endif; ?>
                <?php if ((string) ($config['return_policy'] ?? '') !== ''): ?>
                    <small>Returns: <?php echo e($config['return_policy']); ?></small>
                <?php endif; ?>
                <?php if ((string) ($config['warranty_policy'] ?? '') !== ''): ?>
                    <small>Warranty: <?php echo e($config['warranty_policy']); ?></small>
                <?php endif; ?>
            </footer>
        </article>

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
