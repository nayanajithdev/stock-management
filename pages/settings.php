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
    'shop_logo' => (string) ($config['shop_logo'] ?? ''),
    'currency' => (string) ($config['currency'] ?? 'Rs.'),
    'timezone' => (string) ($config['timezone'] ?? 'Asia/Colombo'),
    'default_reorder_level' => (string) ($config['default_reorder_level'] ?? '5'),
];
$shopLogoUrl = $settings['shop_logo'] !== '' ? app_url($settings['shop_logo']) : '';
$shopInitial = strtoupper(substr(trim($settings['shop_name']) !== '' ? trim($settings['shop_name']) : 'S', 0, 1));
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Configuration</p>
        <h1>Shop Settings</h1>
    </div>
</div>

<section class="settings-layout settings-single-layout">
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
            <form class="settings-form" method="post" action="<?php echo e(app_url('actions/settings_save.php')); ?>" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>

                <section class="settings-section span-2">
                    <div class="settings-section-grid settings-section-grid-three">
                        <label class="field shop-logo-field span-3">
                            <span>Shop Profile Image</span>
                            <div class="shop-logo-upload">
                                <div class="shop-logo-preview">
                                    <?php if ($shopLogoUrl !== ''): ?>
                                        <img src="<?php echo e($shopLogoUrl); ?>" alt="">
                                    <?php else: ?>
                                        <span><?php echo e($shopInitial); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="shop-logo-control">
                                    <input type="file" name="shop_logo" accept="image/png,image/jpeg,image/webp,image/gif">
                                    <small>PNG, JPG, WEBP, or GIF. Max 2MB. Used for sidebar logo and favicon.</small>
                                </div>
                            </div>
                        </label>

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

                        <label class="field span-3">
                            <span>Address</span>
                            <textarea name="shop_address" rows="3" maxlength="500"><?php echo e($settings['shop_address']); ?></textarea>
                        </label>
                    </div>
                </section>

                <section class="settings-section span-2">
                    <div class="settings-section-heading">
                        <p class="panel-label">System Settings</p>
                        <h3>Defaults and regional setup</h3>
                    </div>

                    <div class="settings-section-grid settings-section-grid-three">
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
                            <span>Default Reorder Level</span>
                            <input type="number" name="default_reorder_level" value="<?php echo e($settings['default_reorder_level']); ?>" min="0" max="99999" step="1">
                        </label>
                    </div>
                </section>

                <div class="form-actions span-2">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Settings
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

</section>
