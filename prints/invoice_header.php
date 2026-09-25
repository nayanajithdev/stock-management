<?php
/** @var array $config */

$invoiceShopName = trim((string) ($config['shop_name'] ?? 'Shop'));
$invoiceShopLogo = trim((string) ($config['shop_logo'] ?? ''));
$invoiceShopAddress = trim((string) ($config['shop_address'] ?? ''));
$invoiceShopPhone = trim((string) ($config['shop_phone'] ?? ''));
?>
<header class="invoice-document-header">
    <h1>Invoice</h1>

    <div class="invoice-document-business-row">
        <div class="invoice-business-details">
            <strong><?php echo e($invoiceShopName); ?></strong>
            <?php if ($invoiceShopAddress !== ''): ?>
                <span><?php echo nl2br(e($invoiceShopAddress)); ?></span>
            <?php endif; ?>
            <?php if ($invoiceShopPhone !== ''): ?>
                <span><?php echo e($invoiceShopPhone); ?></span>
            <?php endif; ?>
        </div>

        <div class="invoice-document-brand" aria-label="<?php echo e($invoiceShopName); ?>">
            <?php if ($invoiceShopLogo !== ''): ?>
                <img src="<?php echo e(app_url($invoiceShopLogo)); ?>" alt="<?php echo e($invoiceShopName); ?> logo">
            <?php else: ?>
                <strong><?php echo e($invoiceShopName); ?></strong>
            <?php endif; ?>
        </div>
    </div>
</header>
