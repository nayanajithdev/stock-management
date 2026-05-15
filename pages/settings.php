<?php
/** @var array $config */
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$timezones = [
    'Asia/Colombo',
    'Asia/Kolkata',
    'Asia/Dubai',
    'Asia/Singapore',
    'Europe/London',
    'America/New_York',
];

$settings = [
    'shop_name' => (string) ($config['shop_name'] ?? ''),
    'shop_legal_name' => (string) ($config['shop_legal_name'] ?? ''),
    'shop_phone' => (string) ($config['shop_phone'] ?? ''),
    'shop_email' => (string) ($config['shop_email'] ?? ''),
    'shop_address' => (string) ($config['shop_address'] ?? ''),
    'shop_website' => (string) ($config['shop_website'] ?? ''),
    'currency' => (string) ($config['currency'] ?? 'Rs.'),
    'timezone' => (string) ($config['timezone'] ?? 'Asia/Colombo'),
    'default_tax_percent' => (string) ($config['default_tax_percent'] ?? '0'),
    'default_reorder_level' => (string) ($config['default_reorder_level'] ?? '5'),
    'invoice_footer' => (string) ($config['invoice_footer'] ?? ''),
    'return_policy' => (string) ($config['return_policy'] ?? ''),
    'warranty_policy' => (string) ($config['warranty_policy'] ?? ''),
];
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Configuration</p>
        <h1>Shop Settings</h1>
    </div>
    <a class="top-action" href="#shop-settings-form">
        <i data-lucide="settings"></i>
        Edit Settings
    </a>
</div>

<section class="settings-layout">
    <article class="panel" id="shop-settings-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Shop Profile</p>
                <h2>Business details</h2>
            </div>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before saving settings.</p>
        <?php else: ?>
            <form class="settings-form" method="post" action="<?php echo e(app_url('actions/settings_save.php')); ?>">
                <?php echo csrf_field(); ?>

                <label class="field">
                    <span>Shop Name</span>
                    <input type="text" name="shop_name" value="<?php echo e($settings['shop_name']); ?>" maxlength="120" required>
                </label>

                <label class="field">
                    <span>Legal Name</span>
                    <input type="text" name="shop_legal_name" value="<?php echo e($settings['shop_legal_name']); ?>" maxlength="160">
                </label>

                <label class="field">
                    <span>Phone</span>
                    <input type="text" name="shop_phone" value="<?php echo e($settings['shop_phone']); ?>" maxlength="60">
                </label>

                <label class="field">
                    <span>Email</span>
                    <input type="email" name="shop_email" value="<?php echo e($settings['shop_email']); ?>">
                </label>

                <label class="field">
                    <span>Website</span>
                    <input type="url" name="shop_website" value="<?php echo e($settings['shop_website']); ?>" placeholder="https://example.com">
                </label>

                <label class="field">
                    <span>Currency</span>
                    <input type="text" name="currency" value="<?php echo e($settings['currency']); ?>" maxlength="12" required>
                </label>

                <label class="field">
                    <span>Timezone</span>
                    <select name="timezone">
                        <?php if (! in_array($settings['timezone'], $timezones, true)): ?>
                            <option value="<?php echo e($settings['timezone']); ?>" selected><?php echo e($settings['timezone']); ?></option>
                        <?php endif; ?>
                        <?php foreach ($timezones as $timezone): ?>
                            <option value="<?php echo e($timezone); ?>" <?php echo $settings['timezone'] === $timezone ? 'selected' : ''; ?>><?php echo e($timezone); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span>Default Tax %</span>
                    <input type="number" name="default_tax_percent" value="<?php echo e($settings['default_tax_percent']); ?>" min="0" max="100" step="0.01">
                </label>

                <label class="field">
                    <span>Default Reorder Level</span>
                    <input type="number" name="default_reorder_level" value="<?php echo e($settings['default_reorder_level']); ?>" min="0" max="99999" step="1">
                </label>

                <label class="field span-2">
                    <span>Address</span>
                    <textarea name="shop_address" rows="3" maxlength="500"><?php echo e($settings['shop_address']); ?></textarea>
                </label>

                <label class="field span-2">
                    <span>Invoice Footer</span>
                    <textarea name="invoice_footer" rows="3" maxlength="500"><?php echo e($settings['invoice_footer']); ?></textarea>
                </label>

                <label class="field span-2">
                    <span>Return Policy</span>
                    <textarea name="return_policy" rows="3" maxlength="500"><?php echo e($settings['return_policy']); ?></textarea>
                </label>

                <label class="field span-2">
                    <span>Warranty Policy</span>
                    <textarea name="warranty_policy" rows="3" maxlength="500"><?php echo e($settings['warranty_policy']); ?></textarea>
                </label>

                <div class="form-actions span-2">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Settings
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <aside class="panel settings-preview">
        <div class="panel-header compact">
            <div>
                <p class="panel-label">Preview</p>
                <h2>Invoice header</h2>
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
            <?php if ($settings['shop_website'] !== ''): ?>
                <span><?php echo e($settings['shop_website']); ?></span>
            <?php endif; ?>

            <div class="settings-preview-line"></div>

            <dl>
                <div>
                    <dt>Currency</dt>
                    <dd><?php echo e($settings['currency']); ?></dd>
                </div>
                <div>
                    <dt>Tax</dt>
                    <dd><?php echo e(number_format((float) $settings['default_tax_percent'], 2)); ?>%</dd>
                </div>
                <div>
                    <dt>Timezone</dt>
                    <dd><?php echo e($settings['timezone']); ?></dd>
                </div>
            </dl>

            <?php if ($settings['invoice_footer'] !== ''): ?>
                <p><?php echo e($settings['invoice_footer']); ?></p>
            <?php endif; ?>
        </div>
    </aside>
</section>
