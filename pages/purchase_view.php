<?php
/** @var array $config */
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var ?array $currentUser */

$purchaseId = (int) ($_GET['id'] ?? 0);
$purchase = null;
$items = [];
$payments = [];
$canViewProductCost = $dbReady && $pdo instanceof PDO && auth_can_view_product_cost($pdo, $currentUser ?? null);
$canPaySupplier = $dbReady
    && $pdo instanceof PDO
    && auth_user_has_permission($pdo, $currentUser ?? null, 'supplier_credit');

if ($dbReady && $pdo !== null && $purchaseId > 0) {
    $purchaseStatement = $pdo->prepare(
        'SELECT p.*,
                s.name AS supplier_name,
                s.contact_person AS supplier_contact,
                s.phone AS supplier_phone,
                s.email AS supplier_email,
                s.address AS supplier_address
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         WHERE p.id = :id
         LIMIT 1'
    );
    $purchaseStatement->execute(['id' => $purchaseId]);
    $purchase = $purchaseStatement->fetch() ?: null;

    if (is_array($purchase)) {
        $itemStatement = $pdo->prepare(
            'SELECT pi.*,
                    p.sku,
                    p.name AS product_name,
                    p.model
             FROM purchase_items pi
             INNER JOIN products p ON p.id = pi.product_id
             WHERE pi.purchase_id = :purchase_id
             ORDER BY pi.id ASC'
        );
        $itemStatement->execute(['purchase_id' => $purchaseId]);
        $items = $itemStatement->fetchAll();

        if ($canViewProductCost) {
            $paymentStatement = $pdo->prepare(
                'SELECT *
                 FROM supplier_payments
                 WHERE purchase_id = :purchase_id
                 ORDER BY payment_date ASC, id ASC'
            );
            $paymentStatement->execute(['purchase_id' => $purchaseId]);
            $payments = $paymentStatement->fetchAll();
        }
    }
}

$invoiceLabel = is_array($purchase) && trim((string) ($purchase['invoice_no'] ?? '')) !== ''
    ? (string) $purchase['invoice_no']
    : 'Purchase #' . $purchaseId;
$balance = is_array($purchase) ? max(0.0, (float) $purchase['total'] - (float) $purchase['paid']) : 0.0;
$hasWarranty = array_reduce(
    $items,
    static fn (bool $found, array $item): bool => $found || (int) ($item['warranty_months'] ?? 0) > 0,
    false
);
?>

<?php if (! $dbReady): ?>
    <p class="empty-state">Import <code>database/schema.sql</code> before viewing purchase invoices.</p>
<?php elseif ($purchaseId <= 0 || ! is_array($purchase)): ?>
    <section class="panel">
        <p class="empty-state">Purchase invoice was not found.</p>
        <a class="top-action inline-action" href="<?php echo e(app_url('?page=purchase-history')); ?>">
            <i data-lucide="arrow-left"></i>
            Purchase History
        </a>
    </section>
<?php else: ?>
    <div class="page-heading no-print">
        <div>
            <h1><?php echo e($invoiceLabel); ?></h1>
        </div>
        <div class="invoice-actions">
            <a class="top-action" href="<?php echo e(app_url('?page=purchase-history')); ?>">
                <i data-lucide="arrow-left"></i>
                Purchase History
            </a>
            <?php if ($canViewProductCost && $canPaySupplier && $balance > 0): ?>
                <a class="top-action" href="<?php echo e(app_url('?page=supplier-credit&collect=' . $purchaseId . '#supplier-payment-form')); ?>">
                    <i data-lucide="hand-coins"></i>
                    Pay Supplier
                </a>
            <?php endif; ?>
            <button class="top-action" type="button" onclick="window.print()">
                <i data-lucide="printer"></i>
                Print
            </button>
        </div>
    </div>

    <section class="invoice-layout">
        <article class="panel invoice-paper" id="purchase-invoice-print-area">
            <header class="invoice-header">
                <div>
                    <h2><?php echo e($config['shop_name'] ?? 'Shop'); ?></h2>
                    <?php if ((string) ($config['shop_address'] ?? '') !== ''): ?>
                        <span><?php echo nl2br(e($config['shop_address'])); ?></span>
                    <?php endif; ?>
                    <?php if ((string) ($config['shop_phone'] ?? '') !== '' || (string) ($config['shop_email'] ?? '') !== ''): ?>
                        <span><?php echo e(trim((string) ($config['shop_phone'] ?? '') . ' ' . (string) ($config['shop_email'] ?? ''))); ?></span>
                    <?php endif; ?>
                </div>
                <div class="invoice-meta">
                    <strong>Purchase Invoice</strong>
                    <span><?php echo e($invoiceLabel); ?></span>
                    <small><?php echo e(date('Y-m-d', strtotime((string) $purchase['purchase_date']))); ?></small>
                </div>
            </header>

            <section class="invoice-parties">
                <div>
                    <span>Supplier</span>
                    <strong><?php echo e($purchase['supplier_name'] ?: 'No supplier'); ?></strong>
                    <?php if ((string) ($purchase['supplier_contact'] ?? '') !== ''): ?>
                        <small><?php echo e($purchase['supplier_contact']); ?></small>
                    <?php endif; ?>
                    <?php if ((string) ($purchase['supplier_phone'] ?? '') !== ''): ?>
                        <small><?php echo e($purchase['supplier_phone']); ?></small>
                    <?php endif; ?>
                    <?php if ((string) ($purchase['supplier_email'] ?? '') !== ''): ?>
                        <small><?php echo e($purchase['supplier_email']); ?></small>
                    <?php endif; ?>
                    <?php if ((string) ($purchase['supplier_address'] ?? '') !== ''): ?>
                        <small><?php echo nl2br(e($purchase['supplier_address'])); ?></small>
                    <?php endif; ?>
                </div>
                <div>
                    <span>Stock Receipt</span>
                    <strong><?php echo count($items); ?> item(s)</strong>
                    <small>Status: <?php echo e($balance > 0 ? ucfirst((string) $purchase['status']) : 'Closed'); ?></small>
                </div>
            </section>

            <div class="invoice-table">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <?php if ($hasWarranty): ?><th>Warranty</th><?php endif; ?>
                            <th>Qty</th>
                            <?php if ($canViewProductCost): ?>
                                <th>Unit Cost</th>
                                <th>Total</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <strong class="table-title"><?php echo e($item['sku'] . ' - ' . $item['product_name']); ?></strong>
                                    <span class="table-subtitle"><?php echo e($item['model'] ?? ''); ?></span>
                                </td>
                                <?php if ($hasWarranty): ?>
                                    <td><?php echo (int) $item['warranty_months'] > 0 ? (int) $item['warranty_months'] . ' months' : '-'; ?></td>
                                <?php endif; ?>
                                <td><?php echo (int) $item['quantity']; ?></td>
                                <?php if ($canViewProductCost): ?>
                                    <td><?php echo e(format_money($item['unit_cost'])); ?></td>
                                    <td><?php echo e(format_money($item['total'])); ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($canViewProductCost): ?>
                <section class="invoice-summary">
                    <dl>
                        <div><dt>Subtotal</dt><dd><?php echo e(format_money($purchase['subtotal'])); ?></dd></div>
                        <div><dt>Discount</dt><dd><?php echo e(format_money($purchase['discount'])); ?></dd></div>
                        <div class="invoice-total-row"><dt>Total</dt><dd><?php echo e(format_money($purchase['total'])); ?></dd></div>
                        <div><dt>Paid</dt><dd><?php echo e(format_money($purchase['paid'])); ?></dd></div>
                        <div><dt>Balance</dt><dd><?php echo e(format_money($balance)); ?></dd></div>
                    </dl>
                </section>
            <?php endif; ?>
        </article>

        <?php if ($canViewProductCost): ?>
            <aside class="invoice-side no-print">
                <article class="panel">
                    <div class="panel-header compact">
                        <div>
                            <p class="panel-label">Payment Summary</p>
                            <h2><?php echo e(format_money($balance)); ?></h2>
                        </div>
                    </div>
                    <div class="invoice-mini-list">
                        <div><span>Total</span><strong><?php echo e(format_money($purchase['total'])); ?></strong></div>
                        <div><span>Paid</span><strong><?php echo e(format_money($purchase['paid'])); ?></strong></div>
                        <div><span>Balance</span><strong class="<?php echo $balance > 0 ? 'text-danger' : 'text-good'; ?>"><?php echo e(format_money($balance)); ?></strong></div>
                    </div>
                </article>

                <article class="panel">
                    <div class="panel-header compact">
                        <div>
                            <p class="panel-label">Supplier Payments</p>
                            <h2><?php echo count($payments); ?> record(s)</h2>
                        </div>
                    </div>
                    <div class="invoice-mini-list">
                        <?php if ($payments === []): ?>
                            <p class="empty-state">No later supplier payments recorded.</p>
                        <?php else: ?>
                            <?php foreach ($payments as $payment): ?>
                                <div>
                                    <span><?php echo e(date('Y-m-d', strtotime((string) $payment['payment_date']))); ?> / <?php echo e(ucfirst((string) $payment['payment_method'])); ?></span>
                                    <strong><?php echo e(format_money($payment['amount'])); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>
            </aside>
        <?php endif; ?>
    </section>
<?php endif; ?>
