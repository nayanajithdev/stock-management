<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

require_once __DIR__ . '/../includes/backup.php';

$tableCounts = [];
$recordCount = 0;
$shopImagePath = trim((string) ($config['shop_logo'] ?? ''));

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
                <p class="panel-label">Database backup</p>
                <h2>Download current backup</h2>
            </div>
            <?php if ($dbReady): ?>
                <div class="backup-meta-row">
                    <span><?php echo count($tableCounts); ?> tables</span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before creating backups.</p>
        <?php else: ?>
            <div class="backup-info-box">
                <strong>What this download contains</strong>
                <span>SQL backup includes current database structure and saved records.</span>
                <span><?php echo e(implode(', ', backup_table_names($pdo))); ?></span>
                <span>Full backup also includes the current shop profile image<?php echo $shopImagePath !== '' ? ': ' . e(basename($shopImagePath)) : ' when available'; ?>.</span>
            </div>

            <div class="backup-actions">
                <a class="top-action" href="<?php echo e(app_url('actions/backup_download.php?type=sql')); ?>">
                    <i data-lucide="database"></i>
                    Download Backup (.sql)
                </a>
                <a class="top-action" href="<?php echo e(app_url('actions/backup_download.php?type=full')); ?>">
                    <i data-lucide="archive"></i>
                    Download Full Backup (.zip)
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
            <div class="backup-required-note">
                <i data-lucide="triangle-alert"></i>
                <span><strong>Important:</strong> restore replaces current database. Backup is verified before import.</span>
            </div>

            <form class="backup-restore-form" method="post" action="<?php echo e(app_url('actions/backup_restore.php')); ?>" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>

                <label class="field backup-native-file">
                    <span>Backup File (.sql or .zip)</span>
                    <input type="file" name="backup_file" accept=".sql,.zip" required data-backup-file-input>
                    <small data-backup-file-name>No file selected</small>
                </label>

                <label class="checkbox-field backup-confirm-field">
                    <input type="checkbox" name="confirm_restore" value="1" required>
                    <span>I understand this will replace the current database.</span>
                </label>

                <button class="top-action danger-action backup-restore-submit" type="submit">
                    <i data-lucide="upload"></i>
                    Upload and Restore Backup
                </button>
            </form>
        <?php endif; ?>
    </article>
</section>
