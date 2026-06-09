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

function app_security_request_target(): string
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '');
    $uri = preg_replace('/[^\x20-\x7E]/', '', $uri) ?? '';

    return trim($method . ' ' . substr($uri, 0, 140));
}

function app_security_client_ip(): string
{
    if (function_exists('auth_client_ip')) {
        return auth_client_ip();
    }

    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return filter_var($ipAddress, FILTER_VALIDATE_IP) ? substr($ipAddress, 0, 45) : 'unknown';
}

function app_log_security_event(string $action, string $description, ?array $user = null): void
{
    $pdo = $GLOBALS['pdo'] ?? null;

    if (! $pdo instanceof PDO || ! function_exists('app_log_activity')) {
        return;
    }

    $currentUser = $user ?? ($GLOBALS['currentUser'] ?? null);
    $actor = is_array($currentUser) ? $currentUser : null;
    $ipAddress = app_security_client_ip();
    $description = trim($description);

    if ($ipAddress !== '') {
        $description .= ' IP ' . $ipAddress . '.';
    }

    app_log_activity($pdo, $actor, $action, $description);
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
        app_log_security_event('csrf_failed', 'Blocked invalid CSRF request: ' . app_security_request_target() . '.');
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

function app_stock_value_total(PDO $pdo): float
{
    $values = app_stock_values_by_product($pdo);
    $total = 0.0;

    foreach ($values as $value) {
        $total += (float) ($value['value'] ?? 0);
    }

    return round($total, 2);
}

function app_purchase_cost_join_sql(string $movementAlias = 'sm', string $costAlias = 'pc'): string
{
    foreach ([$movementAlias, $costAlias] as $alias) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new InvalidArgumentException('Invalid SQL alias.');
        }
    }

    return 'LEFT JOIN (
                SELECT pi.purchase_id,
                       pi.product_id,
                       CASE
                           WHEN SUM(pi.quantity) > 0 THEN ROUND(
                               GREATEST(
                                   0,
                                   SUM(pi.total) -
                                   CASE
                                       WHEN COALESCE(p.subtotal, 0) > 0
                                           THEN LEAST(SUM(pi.total), COALESCE(p.discount, 0) * (SUM(pi.total) / p.subtotal))
                                       ELSE 0
                                   END
                               ) / SUM(pi.quantity),
                               2
                           )
                           ELSE NULL
                       END AS net_unit_cost
                FROM purchase_items pi
                INNER JOIN purchases p ON p.id = pi.purchase_id
                GROUP BY pi.purchase_id, pi.product_id, p.subtotal, p.discount
            ) ' . $costAlias . ' ON ' . $movementAlias . '.reference_type = "purchase"
                AND ' . $costAlias . '.purchase_id = ' . $movementAlias . '.reference_id
                AND ' . $costAlias . '.product_id = ' . $movementAlias . '.product_id';
}

function app_lot_unit_cost_sql(string $movementAlias = 'sm', string $costAlias = 'pc'): string
{
    foreach ([$movementAlias, $costAlias] as $alias) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new InvalidArgumentException('Invalid SQL alias.');
        }
    }

    return 'CASE
                WHEN ' . $movementAlias . '.movement_type = "purchase"
                    THEN COALESCE(' . $costAlias . '.net_unit_cost, ' . $movementAlias . '.unit_cost)
                ELSE ' . $movementAlias . '.unit_cost
            END';
}

function app_stock_values_by_product(PDO $pdo, array $productIds = []): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
    $productSql = 'SELECT id, current_stock, cost_price FROM products WHERE status = "active"';

    if ($productIds !== []) {
        $productSql .= ' AND id IN (' . implode(',', $productIds) . ')';
    }

    $productRows = $pdo->query($productSql)->fetchAll();
    $products = [];
    $values = [];

    foreach ($productRows as $product) {
        $productId = (int) $product['id'];
        $products[$productId] = [
            'current_stock' => max(0, (int) $product['current_stock']),
            'fallback_cost' => max(0.0, (float) $product['cost_price']),
        ];
        $values[$productId] = [
            'units' => max(0, (int) $product['current_stock']),
            'value' => 0.0,
        ];
    }

    if ($products === []) {
        return [];
    }

    $idList = implode(',', array_keys($products));
    $lotRows = $pdo->query(
        'SELECT sm.id,
                sm.product_id,
                sm.quantity_change,
                ' . app_lot_unit_cost_sql('sm', 'pc') . ' AS unit_cost
         FROM stock_movements sm
         LEFT JOIN purchases pu ON sm.reference_type = "purchase" AND pu.id = sm.reference_id
         ' . app_purchase_cost_join_sql('sm', 'pc') . '
         WHERE sm.product_id IN (' . $idList . ')
           AND sm.quantity_change > 0
           AND (
                sm.movement_type IN ("opening", "purchase", "return_in", "adjustment_in", "warranty_supplier_in")
                OR (sm.movement_type = "stock_count" AND (sm.reference_type IS NULL OR sm.reference_type <> "stock_lot"))
           )
         ORDER BY sm.product_id ASC, COALESCE(pu.purchase_date, DATE(sm.created_at)) ASC, sm.id ASC'
    )->fetchAll();

    $adjustmentRows = $pdo->query(
        'SELECT product_id, reference_id, COALESCE(SUM(quantity_change), 0) AS quantity_change
         FROM stock_movements
         WHERE product_id IN (' . $idList . ')
           AND reference_type = "stock_lot"
           AND reference_id IS NOT NULL
         GROUP BY product_id, reference_id'
    )->fetchAll();

    $outboundRows = $pdo->query(
        'SELECT product_id, COALESCE(SUM(ABS(quantity_change)), 0) AS stock_out
         FROM stock_movements
         WHERE product_id IN (' . $idList . ')
           AND quantity_change < 0
           AND (reference_type IS NULL OR reference_type <> "stock_lot")
         GROUP BY product_id'
    )->fetchAll();

    $lotAdjustments = [];
    foreach ($adjustmentRows as $row) {
        $lotAdjustments[(int) $row['product_id']][(int) $row['reference_id']] = (int) $row['quantity_change'];
    }

    $stockOutByProduct = [];
    foreach ($outboundRows as $row) {
        $stockOutByProduct[(int) $row['product_id']] = (int) $row['stock_out'];
    }

    $lotsByProduct = [];
    foreach ($lotRows as $lot) {
        $lotsByProduct[(int) $lot['product_id']][] = $lot;
    }

    foreach ($products as $productId => $product) {
        $targetUnits = (int) $product['current_stock'];
        $remainingOutbound = (int) ($stockOutByProduct[$productId] ?? 0);
        $valuedUnits = 0;
        $stockValue = 0.0;

        foreach ($lotsByProduct[$productId] ?? [] as $lot) {
            $lotId = (int) $lot['id'];
            $lotQuantity = max(0, (int) $lot['quantity_change'] + (int) ($lotAdjustments[$productId][$lotId] ?? 0));
            $deducted = min($lotQuantity, $remainingOutbound);
            $remainingOutbound = max(0, $remainingOutbound - $deducted);
            $availableQuantity = $lotQuantity - $deducted;

            if ($availableQuantity <= 0 || $valuedUnits >= $targetUnits) {
                continue;
            }

            $unitsToValue = min($availableQuantity, $targetUnits - $valuedUnits);
            $stockValue += $unitsToValue * max(0.0, (float) $lot['unit_cost']);
            $valuedUnits += $unitsToValue;
        }

        if ($valuedUnits < $targetUnits) {
            $stockValue += ($targetUnits - $valuedUnits) * (float) $product['fallback_cost'];
        }

        $values[$productId]['value'] = round($stockValue, 2);
    }

    return $values;
}
