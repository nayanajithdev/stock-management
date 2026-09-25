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
$invoiceDocument = [
    'id' => 'invoice-preview-print-area',
    'invoice_no' => 'PREVIEW-0001',
    'date' => date('M j, Y'),
    'customer_name' => 'Sample Customer',
    'customer_phone' => '0712345678',
    'customer_email' => 'customer@example.com',
    'customer_address' => 'Sample customer address',
    'subtotal' => $subtotal,
    'discount' => $discount,
    'tax' => $tax,
    'total' => $total,
    'balance' => 0,
];
$invoiceDocumentItems = array_map(
    static fn (array $item): array => [
        'name' => (string) $item['name'],
        'model' => (string) $item['model'],
        'warranty_months' => (int) $item['warranty_months'],
        'quantity' => (int) $item['quantity'],
        'unit_price' => (float) $item['unit_price'],
        'total' => (float) $item['total'],
    ],
    $sampleItems
);
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
    <?php include __DIR__ . '/../prints/invoice_body.php'; ?>
</section>
