<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

require_once __DIR__ . '/../includes/backup.php';

$tableCounts = [];
$recordCount = 0;
$shopImagePath = trim((string) ($config['shop_logo'] ?? ''));
$shopImageName = $shopImagePath !== '' ? basename($shopImagePath) : 'No shop image saved';

if ($dbReady && $pdo !== null) {
    try {
        $tableCounts = backup_table_counts($pdo);
        $recordCount = array_sum($tableCounts);
    } catch (Throwable) {
        $tableCounts = [];
        $recordCount = 0;
    }
}
?>

<div class="page-heading">
    <div>
        <h1>Backup</h1>
    </div>
</div>

<section class="backup-layout">
    <article class="panel backup-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Database Backup</p>
                <h2>Download current backup</h2>
            </div>
            <?php if ($dbReady): ?>
                <span class="dashboard-pill"><?php echo count($tableCounts); ?> tables / <?php echo (int) $recordCount; ?> records</span>
            <?php endif; ?>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before creating backups.</p>
        <?php else: ?>
            <div class="backup-info">
                <h3>What this download contains</h3>
                <p>SQL backup includes current database structure and saved records.</p>
                <p class="backup-muted"><?php echo e(implode(', ', array_keys($tableCounts))); ?></p>
                <p class="backup-muted">Full backup also includes the current shop profile image: <strong><?php echo e($shopImageName); ?></strong>.</p>
            </div>

            <div class="backup-actions">
                <a class="top-action" href="<?php echo e(app_url('actions/backup_download.php?type=sql')); ?>">
                    <i data-lucide="database"></i>
                    Download SQL
                </a>
                <a class="top-action" href="<?php echo e(app_url('actions/backup_download.php?type=full')); ?>">
                    <i data-lucide="archive"></i>
                    Download Full Backup
                </a>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel backup-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Recovery</p>
                <h2>Upload and restore backup</h2>
            </div>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Database must be connected before restore.</p>
        <?php else: ?>
            <div class="setup-notice backup-warning">
                <i data-lucide="triangle-alert"></i>
                <div>
                    <strong>Warning</strong>
                    <span>Restoring a backup will replace the current database structure and data. Download a fresh backup first if needed.</span>
                </div>
            </div>

            <form class="backup-restore-form" method="post" action="<?php echo e(app_url('actions/backup_restore.php')); ?>" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>

                <label class="field">
                    <span>Backup File (.sql or .zip)</span>
                    <input type="file" name="backup_file" accept=".sql,.zip" required>
                </label>

                <label class="checkbox-field">
                    <input type="checkbox" name="confirm_restore" value="1" required>
                    <span>I understand this will replace the current database.</span>
                </label>

                <div class="form-actions">
                    <button class="top-action danger-action" type="submit">
                        <i data-lucide="upload"></i>
                        Upload and Restore Backup
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>
</section>
