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
