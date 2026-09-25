<?php
/**
 * @var array $config
 * @var array $invoiceDocument
 * @var array $invoiceDocumentItems
 */

$invoiceNumber = (string) ($invoiceDocument['invoice_no'] ?? '');
$invoiceDate = (string) ($invoiceDocument['date'] ?? '');
$invoiceCustomerName = trim((string) ($invoiceDocument['customer_name'] ?? '')) ?: 'Walk-in Customer';
$invoiceCustomerPhone = trim((string) ($invoiceDocument['customer_phone'] ?? ''));
$invoiceCustomerEmail = trim((string) ($invoiceDocument['customer_email'] ?? ''));
$invoiceCustomerAddress = trim((string) ($invoiceDocument['customer_address'] ?? ''));
$invoiceSubtotal = (float) ($invoiceDocument['subtotal'] ?? 0);
$invoiceDiscount = (float) ($invoiceDocument['discount'] ?? 0);
$invoiceTax = (float) ($invoiceDocument['tax'] ?? 0);
$invoiceTotal = (float) ($invoiceDocument['total'] ?? 0);
$invoiceBalance = max(0, (float) ($invoiceDocument['balance'] ?? 0));
?>
<article class="invoice-document" id="<?php echo e((string) ($invoiceDocument['id'] ?? 'invoice-print-area')); ?>">
    <?php include __DIR__ . '/invoice_header.php'; ?>

    <section class="invoice-billing-strip">
        <div class="invoice-bill-to">
            <span class="invoice-label">Bill to</span>
            <strong><?php echo e($invoiceCustomerName); ?></strong>
            <?php if ($invoiceCustomerPhone !== ''): ?>
                <span class="invoice-label">Phone</span>
                <small><?php echo e($invoiceCustomerPhone); ?></small>
            <?php endif; ?>
            <?php if ($invoiceCustomerEmail !== ''): ?><small><?php echo e($invoiceCustomerEmail); ?></small><?php endif; ?>
            <?php if ($invoiceCustomerAddress !== ''): ?><small><?php echo nl2br(e($invoiceCustomerAddress)); ?></small><?php endif; ?>
        </div>
        <dl class="invoice-number-date">
            <div><dt>Invoice number</dt><dd><?php echo e($invoiceNumber); ?></dd></div>
            <div><dt>Issued date</dt><dd><?php echo e($invoiceDate); ?></dd></div>
        </dl>
    </section>

    <div class="invoice-document-table">
        <table>
            <colgroup>
                <col class="invoice-col-description">
                <col class="invoice-col-price">
                <col class="invoice-col-quantity">
                <col class="invoice-col-amount">
            </colgroup>
            <thead>
                <tr>
                    <th>Item description</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoiceDocumentItems as $item): ?>
                    <?php
                    $invoiceItemName = trim((string) ($item['name'] ?? $item['item_name'] ?? 'Item'));
                    $invoiceItemWarranty = (int) ($item['warranty_months'] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo e($invoiceItemName); ?></strong>
                            <?php if ($invoiceItemWarranty > 0): ?>
                                <small>(<?php echo $invoiceItemWarranty; ?>m warranty)</small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(format_money($item['unit_price'] ?? 0)); ?></td>
                        <td><?php echo (int) ($item['quantity'] ?? 0); ?></td>
                        <td><?php echo e(format_money($item['total'] ?? 0)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <section class="invoice-document-summary">
        <dl>
            <div><dt>Subtotal</dt><dd><?php echo e(format_money($invoiceSubtotal)); ?></dd></div>
            <?php if ($invoiceDiscount > 0): ?>
                <div><dt>Discount</dt><dd>-<?php echo e(format_money($invoiceDiscount)); ?></dd></div>
            <?php endif; ?>
            <?php if ($invoiceTax > 0): ?>
                <div><dt>Tax</dt><dd><?php echo e(format_money($invoiceTax)); ?></dd></div>
            <?php endif; ?>
            <div class="invoice-document-total"><dt>Total</dt><dd><?php echo e(format_money($invoiceTotal)); ?></dd></div>
            <?php if ($invoiceBalance > 0): ?>
                <div class="invoice-document-due"><dt>Amount due</dt><dd><?php echo e(format_money($invoiceBalance)); ?></dd></div>
            <?php endif; ?>
        </dl>
    </section>

    <footer class="invoice-document-footer">
        <strong><?php echo e((string) ($config['invoice_footer'] ?? 'Thank you for your business!')); ?></strong>
        <div class="invoice-footer-details">
            <div class="invoice-footer-policies">
                <?php if (trim((string) ($config['return_policy'] ?? '')) !== ''): ?>
                    <p><?php echo e((string) $config['return_policy']); ?></p>
                <?php endif; ?>
                <?php if (trim((string) ($config['warranty_policy'] ?? '')) !== ''): ?>
                    <p><?php echo e((string) $config['warranty_policy']); ?></p>
                <?php endif; ?>
            </div>
            <div class="invoice-footer-contact">
                <?php if (trim((string) ($config['shop_phone'] ?? '')) !== ''): ?>
                    <span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.69 2.8a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.33 1.84.56 2.8.69A2 2 0 0 1 22 16.92z"/></svg>
                        <?php echo e((string) $config['shop_phone']); ?>
                    </span>
                <?php endif; ?>
                <?php if (trim((string) ($config['shop_email'] ?? '')) !== ''): ?>
                    <span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 7 9 6 9-6"/></svg>
                        <?php echo e((string) $config['shop_email']); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </footer>
</article>
