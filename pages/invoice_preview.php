<?php
/** @var array $config */

$sampleItems = [
    [
        'sku' => 'KEY-DELL-KM3322W',
        'name' => 'Dell KM3322W Wireless Keyboard and Mouse Combo',
        'model' => 'KM3322W',
        'warranty_months' => 12,
        'quantity' => 1,
        'unit_price' => 8900.00,
        'discount' => 0.00,
    ],
    [
        'sku' => 'USB-SND-32U3',
        'name' => 'SanDisk Ultra 32GB USB 3.0 Flash Drive',
        'model' => 'Ultra 32GB',
        'warranty_months' => 6,
        'quantity' => 2,
        'unit_price' => 2500.00,
        'discount' => 500.00,
    ],
];

$subtotal = 0.0;
$discount = 0.0;

foreach ($sampleItems as $index => $item) {
    $lineSubtotal = (float) $item['quantity'] * (float) $item['unit_price'];
    $sampleItems[$index]['total'] = max(0, $lineSubtotal - (float) $item['discount']);
    $subtotal += $lineSubtotal;
    $discount += (float) $item['discount'];
}

$taxPercent = (float) ($config['default_tax_percent'] ?? 0);
$tax = max(0, ($subtotal - $discount) * ($taxPercent / 100));
$total = max(0, $subtotal - $discount + $tax);
$paid = $total;
?>

<div class="page-heading no-print">
    <div>
        <h1>Invoice Preview</h1>
    </div>
    <div class="invoice-actions">
        <a class="top-action" href="<?php echo e(app_url('?page=invoice-settings')); ?>">
            <i data-lucide="arrow-left"></i>
            Invoice Settings
        </a>
        <button class="top-action" type="button" onclick="window.print()">
            <i data-lucide="printer"></i>
            Print
        </button>
    </div>
</div>

<section class="invoice-layout invoice-preview-layout">
    <article class="panel invoice-paper" id="invoice-preview-print-area">
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
                <span>PREVIEW-0001</span>
                <small><?php echo e(date('Y-m-d H:i')); ?></small>
            </div>
        </header>

        <section class="invoice-parties">
            <div>
                <span>Customer</span>
                <strong>Sample Customer</strong>
                <small>0712345678</small>
                <small>customer@example.com</small>
            </div>
            <div>
                <span>Payment</span>
                <strong>Cash</strong>
                <small>Status: Paid</small>
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
                    <?php foreach ($sampleItems as $item): ?>
                        <tr>
                            <td>
                                <strong class="table-title"><?php echo e($item['sku'] . ' - ' . $item['name']); ?></strong>
                                <span class="table-subtitle"><?php echo e($item['model']); ?></span>
                            </td>
                            <td><?php echo (int) $item['warranty_months']; ?> month warranty</td>
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
                    <dd><?php echo e(format_money($subtotal)); ?></dd>
                </div>
                <div>
                    <dt>Discount</dt>
                    <dd><?php echo e(format_money($discount)); ?></dd>
                </div>
                <div>
                    <dt>Tax</dt>
                    <dd><?php echo e(format_money($tax)); ?></dd>
                </div>
                <div class="invoice-total-row">
                    <dt>Total</dt>
                    <dd><?php echo e(format_money($total)); ?></dd>
                </div>
                <div>
                    <dt>Paid</dt>
                    <dd><?php echo e(format_money($paid)); ?></dd>
                </div>
                <div>
                    <dt>Balance</dt>
                    <dd><?php echo e(format_money(0)); ?></dd>
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
</section>
