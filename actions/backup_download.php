<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method not allowed.');
}

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=backup');
}

$type = (string) ($_GET['type'] ?? 'sql');
$zipPath = null;

try {
    if ($type === 'full') {
        $zipPath = backup_create_full_zip($pdo);
        $filename = backup_filename('zip');

        app_log_activity($pdo, $currentUser, 'backup_download', 'Downloaded full backup.');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($zipPath);
        exit;
    }

    if ($type !== 'sql') {
        throw new RuntimeException('Choose a valid backup type.');
    }

    $sql = backup_generate_sql($pdo, 'sql');
    $filename = backup_filename('sql');

    app_log_activity($pdo, $currentUser, 'backup_download', 'Downloaded SQL backup.');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $sql;
    exit;
} catch (Throwable $exception) {
    set_flash('error', $exception->getMessage());
    redirect('?page=backup');
} finally {
    if ($zipPath !== null && is_file($zipPath)) {
        @unlink($zipPath);
    }
}
