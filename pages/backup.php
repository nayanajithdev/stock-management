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
                <h2>Download backup</h2>
            </div>
            <?php if ($dbReady): ?>
                <div class="backup-meta-row">
                    <span><?php echo count($tableCounts); ?> tables</span>
                    <span><?php echo (int) $recordCount; ?> records</span>
                    <span><?php echo $shopImagePath !== '' ? 'Shop image included' : 'No shop image'; ?></span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before creating backups.</p>
        <?php else: ?>
            <div class="backup-summary-grid">
                <div>
                    <strong>SQL backup</strong>
                    <span>Database structure and records.</span>
                </div>
                <div>
                    <strong>Full backup</strong>
                    <span>Database plus shop profile image.</span>
                </div>
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
                <h2>Restore backup</h2>
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

                <label class="backup-file-picker">
                    <input class="backup-file-input" type="file" name="backup_file" accept=".sql,.zip" required data-backup-file-input>
                    <span class="backup-file-icon"><i data-lucide="file-archive"></i></span>
                    <span class="backup-file-copy">
                        <strong>Select backup file</strong>
                        <small data-backup-file-name>No file selected</small>
                    </span>
                </label>

                <div class="backup-restore-actions">
                    <label class="checkbox-field backup-confirm-field">
                        <input type="checkbox" name="confirm_restore" value="1" required>
                        <span>Replace current database</span>
                    </label>

                    <button class="top-action danger-action" type="submit">
                        <i data-lucide="upload"></i>
                        Upload and Restore Backup
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>
</section>
