<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim(dirname($scriptName), '/');

    foreach (['/actions'] as $scriptFolder) {
        if (str_ends_with($base, $scriptFolder)) {
            $base = substr($base, 0, -strlen($scriptFolder));
        }
    }

    return in_array($base, ['/', '.', '\\'], true) ? '' : $base;
}

function app_url(string $path = ''): string
{
    $base = app_base_path();

    if ($path === '') {
        return $base . '/';
    }

    if (str_starts_with($path, '?')) {
        return $base . '/index.php' . $path;
    }

    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function csrf_token(): string
{
    if (! isset($_SESSION['csrf_token']) || ! is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');

    if (! hash_equals(csrf_token(), $submitted)) {
        set_flash('error', 'Your form expired. Please try again.');
        redirect('?page=dashboard');
    }
}

function format_money(float|int|string|null $amount): string
{
    $config = $GLOBALS['config'] ?? ['currency' => 'Rs.'];
    $currency = (string) ($config['currency'] ?? 'Rs.');

    return $currency . ' ' . number_format((float) $amount, 2);
}

function input_decimal(string $key): float
{
    $value = str_replace(',', '', trim((string) ($_POST[$key] ?? '0')));

    return is_numeric($value) ? (float) $value : 0.0;
}

function input_int(string $key): int
{
    return max(0, (int) ($_POST[$key] ?? 0));
}

function nullable_string(string $value): ?string
{
    $value = trim($value);

    return $value === '' ? null : $value;
}

function sale_receivable_balance(float|int|string|null $total, float|int|string|null $paid, float|int|string|null $returnedTotal = 0, float|int|string|null $refundTotal = 0): float
{
    return max(0.0, round((float) $total - (float) $paid - (float) $returnedTotal + (float) $refundTotal, 2));
}

function sale_discounted_line_total(float|int|string|null $lineTotal, float|int|string|null $saleSubtotal, float|int|string|null $saleDiscount = 0): float
{
    $lineTotal = max(0.0, (float) $lineTotal);
    $saleSubtotal = max(0.0, (float) $saleSubtotal);
    $saleDiscount = max(0.0, (float) $saleDiscount);

    if ($lineTotal <= 0.0 || $saleSubtotal <= 0.0 || $saleDiscount <= 0.0) {
        return round($lineTotal, 2);
    }

    $discountShare = min($lineTotal, $saleDiscount * ($lineTotal / $saleSubtotal));

    return round($lineTotal - $discountShare, 2);
}

function sale_discounted_unit_price(float|int|string|null $lineTotal, float|int|string|null $saleSubtotal, float|int|string|null $saleDiscount, int $quantity): float
{
    $quantity = max(1, $quantity);

    return round(sale_discounted_line_total($lineTotal, $saleSubtotal, $saleDiscount) / $quantity, 2);
}
