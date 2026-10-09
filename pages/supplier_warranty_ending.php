<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$expiringLots = $dbReady && $pdo instanceof PDO
    ? app_supplier_warranty_expiring_lots($pdo)
    : [];
?>

<div class="page-heading">
    <div>
        <h1>Supplier Warranty Ending</h1>
        <p><?php echo count($expiringLots); ?> stock lot(s) ending within 30 days</p>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=dashboard')); ?>">
        <i data-lucide="arrow-left"></i>
        Dashboard
    </a>
</div>

<section class="panel">
    <?php if (! $dbReady): ?>
        <p class="empty-state">Database is not ready.</p>
    <?php elseif ($expiringLots === []): ?>
        <p class="empty-state">No in-stock supplier warranty lots end within the next 30 days.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Supplier / Source</th>
                        <th>Remaining Qty</th>
                        <th>Warranty Start</th>
                        <th>Warranty Ends</th>
                        <th>Time Left</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expiringLots as $lot): ?>
                        <?php
                        $source = trim((string) ($lot['supplier_name'] ?? ''));
                        $purchaseInvoice = trim((string) ($lot['purchase_invoice_no'] ?? ''));
                        if ($source === '') {
                            $source = ucfirst(str_replace('_', ' ', (string) ($lot['movement_type'] ?? 'Stock entry')));
                        }
                        ?>
                        <tr>
                            <td>
                                <strong class="table-title"><?php echo e($lot['product_name']); ?></strong>
                                <span class="table-subtitle">
                                    <?php echo e($lot['sku']); ?>
                                    <?php if (trim((string) ($lot['model'] ?? '')) !== ''): ?>
                                        / <?php echo e($lot['model']); ?>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td>
                                <?php echo e($source); ?>
                                <?php if ($purchaseInvoice !== ''): ?>
                                    <span class="table-subtitle">Invoice <?php echo e($purchaseInvoice); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int) $lot['remaining_quantity']; ?></td>
                            <td><?php echo e(date('M j, Y', strtotime((string) $lot['warranty_start']))); ?></td>
                            <td><?php echo e(date('M j, Y', strtotime((string) $lot['warranty_ends_at']))); ?></td>
                            <td>
                                <span class="status <?php echo (int) $lot['days_remaining'] <= 7 ? 'status-pending' : 'status-warranty'; ?>">
                                    <?php echo (int) $lot['days_remaining'] === 0 ? 'Ends today' : (int) $lot['days_remaining'] . ' day(s)'; ?>
                                </span>
                            </td>
                            <td>
                                <a class="icon-button" href="<?php echo e(app_url('?page=product-history&id=' . (int) $lot['product_id'])); ?>" aria-label="View product history">
                                    <i data-lucide="history"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
