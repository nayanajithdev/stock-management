<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=settings');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=settings');
}

$settings = [
    'shop_name' => trim((string) ($_POST['shop_name'] ?? '')),
    'shop_legal_name' => trim((string) ($_POST['shop_legal_name'] ?? '')),
    'shop_phone' => trim((string) ($_POST['shop_phone'] ?? '')),
    'shop_email' => trim((string) ($_POST['shop_email'] ?? '')),
    'shop_address' => trim((string) ($_POST['shop_address'] ?? '')),
    'shop_website' => trim((string) ($_POST['shop_website'] ?? '')),
    'currency' => trim((string) ($_POST['currency'] ?? '')),
    'timezone' => trim((string) ($_POST['timezone'] ?? 'Asia/Colombo')),
    'default_reorder_level' => trim((string) ($_POST['default_reorder_level'] ?? '5')),
];

try {
    $currentSettings = app_fetch_settings($pdo);
    $settings['shop_logo'] = (string) ($currentSettings['shop_logo'] ?? '');

    if ($settings['shop_name'] === '') {
        throw new RuntimeException('Shop name is required.');
    }

    if (strlen($settings['shop_name']) > 120) {
        throw new RuntimeException('Shop name is too long.');
    }

    if ($settings['shop_email'] !== '' && ! filter_var($settings['shop_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid shop email.');
    }

    if ($settings['shop_website'] !== '' && ! filter_var($settings['shop_website'], FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Enter a valid website URL including http:// or https://.');
    }

    if ($settings['currency'] === '' || strlen($settings['currency']) > 12) {
        throw new RuntimeException('Currency is required and must be short.');
    }

    if (! in_array($settings['timezone'], timezone_identifiers_list(), true)) {
        throw new RuntimeException('Choose a valid timezone.');
    }

    $reorderLevel = (int) $settings['default_reorder_level'];

    if ((string) $reorderLevel !== $settings['default_reorder_level'] || $reorderLevel < 0 || $reorderLevel > 99999) {
        throw new RuntimeException('Default reorder level must be a whole number.');
    }

    $settings['default_reorder_level'] = (string) $reorderLevel;
    $settings['shop_legal_name'] = limit_setting_text($settings['shop_legal_name'], 160);
    $settings['shop_phone'] = limit_setting_text($settings['shop_phone'], 60);
    $settings['shop_address'] = limit_setting_text($settings['shop_address'], 500);

    $uploadedLogo = handle_shop_logo_upload($settings['shop_logo']);

    if ($uploadedLogo !== null) {
        $settings['shop_logo'] = $uploadedLogo;
    }

    app_save_settings($pdo, $settings);
    app_log_activity($pdo, $currentUser, 'settings_update', 'Updated shop settings.');

    set_flash('success', 'Shop settings saved.');
    redirect('?page=settings');
} catch (RuntimeException $exception) {
    set_flash('error', $exception->getMessage());
    redirect('?page=settings');
}

function limit_setting_text(string $value, int $limit): string
{
    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit);
}

function handle_shop_logo_upload(string $currentLogo): ?string
{
    if (! isset($_FILES['shop_logo']) || ! is_array($_FILES['shop_logo'])) {
        return null;
    }

    $file = $_FILES['shop_logo'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Shop image upload failed.');
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('Shop image must be 2MB or smaller.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || ! is_uploaded_file($tmpName)) {
        throw new RuntimeException('Shop image upload is invalid.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';

    if ($finfo) {
        finfo_close($finfo);
    }

    $allowedTypes = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (! isset($allowedTypes[$mime]) || getimagesize($tmpName) === false) {
        throw new RuntimeException('Shop image must be PNG, JPG, WEBP, or GIF.');
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'shop';

    if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0775, true)) {
        throw new RuntimeException('Could not create shop upload folder.');
    }

    $extension = $allowedTypes[$mime];
    $fileName = 'shop-logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (! move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save shop image.');
    }

    delete_old_shop_logo($currentLogo, $destination);

    return 'uploads/shop/' . $fileName;
}

function delete_old_shop_logo(string $currentLogo, string $newDestination): void
{
    if (! str_starts_with($currentLogo, 'uploads/shop/')) {
        return;
    }

    $uploadDir = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'shop');
    $oldPath = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $currentLogo));
    $newPath = realpath($newDestination);

    if ($uploadDir === false || $oldPath === false || $newPath === false) {
        return;
    }

    if ($oldPath === $newPath || dirname($oldPath) !== $uploadDir) {
        return;
    }

    if (is_file($oldPath)) {
        unlink($oldPath);
    }
}
