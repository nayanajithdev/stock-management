<?php
/** @var array $config */
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$settings = [
    'shop_name' => (string) ($config['shop_name'] ?? ''),
    'shop_legal_name' => (string) ($config['shop_legal_name'] ?? ''),
    'shop_phone' => (string) ($config['shop_phone'] ?? ''),
    'shop_email' => (string) ($config['shop_email'] ?? ''),
    'shop_address' => (string) ($config['shop_address'] ?? ''),
    'currency' => (string) ($config['currency'] ?? 'Rs.'),
    'default_tax_percent' => (string) ($config['default_tax_percent'] ?? '0'),
    'invoice_footer' => (string) ($config['invoice_footer'] ?? ''),
    'return_policy' => (string) ($config['return_policy'] ?? ''),
    'warranty_policy' => (string) ($config['warranty_policy'] ?? ''),
];
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Invoice configuration</p>
        <h1>Invoice Settings</h1>
    </div>
</div>

<section class="settings-layout">
    <article class="panel" id="invoice-settings-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Invoice Settings</p>
                <h2>Tax, footer, and policy text</h2>
            </div>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before saving invoice settings.</p>
        <?php else: ?>
            <form class="settings-form" method="post" action="<?php echo e(app_url('actions/invoice_settings_save.php')); ?>">
                <?php echo csrf_field(); ?>

                <section class="settings-section span-2">
                    <div class="settings-section-heading">
                        <p class="panel-label">Invoice Defaults</p>
                        <h3>Amounts and receipt footer</h3>
                    </div>

                    <div class="settings-section-grid">
                        <label class="field">
                            <span>Default Tax %</span>
                            <input type="number" name="default_tax_percent" value="<?php echo e($settings['default_tax_percent']); ?>" min="0" max="100" step="0.01">
                        </label>

                        <label class="field span-2">
                            <span>Invoice Footer</span>
                            <textarea name="invoice_footer" rows="3" maxlength="500"><?php echo e($settings['invoice_footer']); ?></textarea>
                        </label>
                    </div>
                </section>

                <section class="settings-section span-2">
                    <div class="settings-section-heading">
                        <p class="panel-label">Invoice Policies</p>
                        <h3>Printed return and warranty notes</h3>
                    </div>

                    <div class="settings-section-grid">
                        <label class="field span-2">
                            <span>Return Policy</span>
                            <textarea name="return_policy" rows="3" maxlength="500"><?php echo e($settings['return_policy']); ?></textarea>
                        </label>

                        <label class="field span-2">
                            <span>Warranty Policy</span>
                            <textarea name="warranty_policy" rows="3" maxlength="500"><?php echo e($settings['warranty_policy']); ?></textarea>
                        </label>
                    </div>
                </section>

                <div class="form-actions span-2">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Invoice Settings
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <aside class="panel settings-preview">
        <div class="panel-header compact">
            <div>
                <p class="panel-label">Preview</p>
                <h2>Invoice text</h2>
            </div>
        </div>

        <div class="settings-receipt">
            <strong><?php echo e($settings['shop_name']); ?></strong>
            <?php if ($settings['shop_legal_name'] !== ''): ?>
                <span><?php echo e($settings['shop_legal_name']); ?></span>
            <?php endif; ?>
            <?php if ($settings['shop_address'] !== ''): ?>
                <span><?php echo nl2br(e($settings['shop_address'])); ?></span>
            <?php endif; ?>
            <?php if ($settings['shop_phone'] !== '' || $settings['shop_email'] !== ''): ?>
                <span><?php echo e(trim($settings['shop_phone'] . ' ' . $settings['shop_email'])); ?></span>
            <?php endif; ?>

            <div class="settings-preview-line"></div>

            <dl>
                <div>
                    <dt>Currency</dt>
                    <dd><?php echo e($settings['currency']); ?></dd>
                </div>
                <div>
                    <dt>Default Tax</dt>
                    <dd><?php echo e(number_format((float) $settings['default_tax_percent'], 2)); ?>%</dd>
                </div>
            </dl>

            <?php if ($settings['invoice_footer'] !== ''): ?>
                <p><?php echo e($settings['invoice_footer']); ?></p>
            <?php endif; ?>
            <?php if ($settings['return_policy'] !== ''): ?>
                <p><?php echo e($settings['return_policy']); ?></p>
            <?php endif; ?>
            <?php if ($settings['warranty_policy'] !== ''): ?>
                <p><?php echo e($settings['warranty_policy']); ?></p>
            <?php endif; ?>
        </div>
    </aside>
</section>
