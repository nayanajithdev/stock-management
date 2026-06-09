<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=backup');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=backup');
}

if ((string) ($_POST['confirm_restore'] ?? '') !== '1') {
    app_log_security_event('backup_restore_denied', 'Backup restore submitted without confirmation.', $currentUser);
    set_flash('error', 'Confirm that you understand this restore will replace the current database.');
    redirect('?page=backup');
}

try {
    app_log_security_event('backup_restore_attempt', 'Started backup restore verification.', $currentUser);
    $upload = backup_read_upload();
    $verifiedBackup = backup_verify_uploaded_backup($upload);

    backup_restore_sql($pdo, (string) $verifiedBackup['sql']);

    if (($verifiedBackup['type'] ?? '') === 'zip' && is_string($verifiedBackup['zip_path'])) {
        backup_restore_zip_files($verifiedBackup['zip_path']);
    }

    app_apply_schema_upgrades($pdo);
    app_log_activity($pdo, $currentUser, 'backup_restore', 'Restored a verified backup.');
    auth_logout_session();

    set_flash('success', 'Backup restored. Sign in again to continue.');
    redirect('?page=login');
} catch (Throwable $exception) {
    app_log_security_event('backup_restore_failed', 'Backup restore failed: ' . substr($exception->getMessage(), 0, 140), $currentUser);
    set_flash('error', $exception->getMessage());
    redirect('?page=backup');
}
