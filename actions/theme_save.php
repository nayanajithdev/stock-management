<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=dashboard');
}

verify_csrf();

if (! $dbReady || $pdo === null || ! is_array($currentUser)) {
    set_flash('error', 'Theme cannot be saved right now.');
    redirect('?page=dashboard');
}

$themeMode = (string) ($_POST['theme_mode'] ?? 'dark');

if (! in_array($themeMode, ['dark', 'light'], true)) {
    $themeMode = 'dark';
}

$statement = $pdo->prepare(
    'UPDATE users
     SET theme_mode = :theme_mode
     WHERE id = :id
     LIMIT 1'
);
$statement->execute([
    'theme_mode' => $themeMode,
    'id' => (int) $currentUser['id'],
]);

app_log_activity($pdo, $currentUser, 'theme_update', 'Changed interface theme to ' . $themeMode . '.');

$returnTo = preg_replace('/[\r\n]/', '', (string) ($_POST['return_to'] ?? '')) ?? '';
$returnPath = parse_url($returnTo, PHP_URL_PATH);
$returnScheme = parse_url($returnTo, PHP_URL_SCHEME);
$returnHost = parse_url($returnTo, PHP_URL_HOST);
$basePath = app_base_path();
$allowedPrefix = $basePath === '' ? '/' : $basePath . '/';

if (
    is_string($returnPath)
    && $returnScheme === null
    && $returnHost === null
    && ! str_starts_with($returnTo, '//')
    && str_starts_with($returnPath, $allowedPrefix)
) {
    header('Location: ' . $returnTo);
    exit;
}

redirect('?page=dashboard');
