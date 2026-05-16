<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=invoice-settings');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=invoice-settings');
}

$settings = [
    'default_tax_percent' => trim((string) ($_POST['default_tax_percent'] ?? '0')),
    'invoice_footer' => trim((string) ($_POST['invoice_footer'] ?? '')),
    'return_policy' => trim((string) ($_POST['return_policy'] ?? '')),
    'warranty_policy' => trim((string) ($_POST['warranty_policy'] ?? '')),
];

try {
    $taxPercent = str_replace(',', '', $settings['default_tax_percent']);

    if (! is_numeric($taxPercent) || (float) $taxPercent < 0 || (float) $taxPercent > 100) {
        throw new RuntimeException('Default tax percent must be between 0 and 100.');
    }

    $settings['default_tax_percent'] = number_format((float) $taxPercent, 2, '.', '');
    $settings['invoice_footer'] = limit_invoice_setting_text($settings['invoice_footer'], 500);
    $settings['return_policy'] = limit_invoice_setting_text($settings['return_policy'], 500);
    $settings['warranty_policy'] = limit_invoice_setting_text($settings['warranty_policy'], 500);

    app_save_settings($pdo, $settings);
    app_log_activity($pdo, $currentUser, 'invoice_settings_update', 'Updated invoice settings.');

    set_flash('success', 'Invoice settings saved.');
    redirect('?page=invoice-settings');
} catch (RuntimeException $exception) {
    set_flash('error', $exception->getMessage());
    redirect('?page=invoice-settings');
}

function limit_invoice_setting_text(string $value, int $limit): string
{
    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit);
}
