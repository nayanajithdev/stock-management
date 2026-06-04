<?php

declare(strict_types=1);

function app_pdo(?string &$error = null): ?PDO
{
    $dbConfig = require __DIR__ . '/../config/database.php';

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $dbConfig['host'],
            (int) $dbConfig['port'],
            $dbConfig['database']
        );

        return new PDO($dsn, (string) $dbConfig['username'], (string) $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $exception) {
        $error = $exception->getMessage();

        return null;
    }
}

function app_tables_exist(PDO $pdo, array $tables): bool
{
    foreach ($tables as $table) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        if ((int) $statement->fetchColumn() === 0) {
            return false;
        }
    }

    return true;
}

function app_column_exists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND column_name = :column_name'
    );
    $statement->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function app_database_ready(PDO $pdo): bool
{
    if (! app_tables_exist($pdo, [
        'users',
        'user_permissions',
        'settings',
        'categories',
        'brands',
        'suppliers',
        'customers',
        'products',
        'product_serials',
        'stock_movements',
        'sales',
        'sale_items',
        'customer_payments',
        'sales_returns',
        'sales_return_items',
        'warranty_claims',
        'purchases',
        'purchase_items',
        'supplier_payments',
        'expenses',
        'activity_logs',
    ])) {
        return false;
    }

    return app_column_exists($pdo, 'users', 'email')
        && app_column_exists($pdo, 'products', 'item_tracking')
        && app_column_exists($pdo, 'purchase_items', 'warranty_months')
        && app_column_exists($pdo, 'stock_movements', 'warranty_months')
        && app_column_exists($pdo, 'warranty_claims', 'sale_item_id')
        && app_column_exists($pdo, 'warranty_claims', 'replacement_mode');
}

function app_apply_schema_upgrades(PDO $pdo): void
{
    if (! app_tables_exist($pdo, ['warranty_claims'])) {
        return;
    }

    $columns = [
        'supplier_refund_amount' => 'ALTER TABLE warranty_claims ADD COLUMN supplier_refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER supplier_notes',
        'supplier_refund_date' => 'ALTER TABLE warranty_claims ADD COLUMN supplier_refund_date DATE NULL AFTER supplier_refund_amount',
        'sale_item_id' => 'ALTER TABLE warranty_claims ADD COLUMN sale_item_id BIGINT UNSIGNED NULL AFTER sale_id',
        'replacement_mode' => 'ALTER TABLE warranty_claims ADD COLUMN replacement_mode VARCHAR(30) NOT NULL DEFAULT \'wait_supplier\' AFTER supplier_refund_date',
        'customer_replacement_status' => 'ALTER TABLE warranty_claims ADD COLUMN customer_replacement_status VARCHAR(30) NOT NULL DEFAULT \'pending\' AFTER replacement_mode',
        'customer_replaced_at' => 'ALTER TABLE warranty_claims ADD COLUMN customer_replaced_at DATETIME NULL AFTER customer_replacement_status',
        'supplier_replacement_status' => 'ALTER TABLE warranty_claims ADD COLUMN supplier_replacement_status VARCHAR(30) NOT NULL DEFAULT \'pending\' AFTER customer_replaced_at',
        'supplier_replaced_at' => 'ALTER TABLE warranty_claims ADD COLUMN supplier_replaced_at DATETIME NULL AFTER supplier_replacement_status',
    ];

    foreach ($columns as $column => $sql) {
        if (! app_column_exists($pdo, 'warranty_claims', $column)) {
            $pdo->exec($sql);
        }
    }
}

function app_fetch_settings(PDO $pdo): array
{
    if (! app_tables_exist($pdo, ['settings'])) {
        return [];
    }

    $statement = $pdo->query('SELECT setting_key, setting_value FROM settings');
    $settings = [];

    foreach ($statement->fetchAll() as $row) {
        $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }

    return $settings;
}

function app_save_settings(PDO $pdo, array $settings): void
{
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at)
         VALUES (:setting_key, :setting_value, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($settings as $key => $value) {
        $statement->execute([
            'setting_key' => (string) $key,
            'setting_value' => $value === null ? null : (string) $value,
        ]);
    }
}

function app_log_activity(PDO $pdo, ?array $user, string $action, string $description): void
{
    if (! app_tables_exist($pdo, ['activity_logs'])) {
        return;
    }

    $action = substr(trim($action), 0, 80);
    $description = substr(trim($description), 0, 255);

    if ($action === '' || $description === '') {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO activity_logs (user_id, action, description)
             VALUES (:user_id, :action, :description)'
        );
        $statement->execute([
            'user_id' => isset($user['id']) ? (int) $user['id'] : null,
            'action' => $action,
            'description' => $description,
        ]);
    } catch (Throwable) {
        return;
    }
}

function app_fetch_options(PDO $pdo, string $table): array
{
    $allowedTables = ['categories', 'brands', 'suppliers'];

    if (! in_array($table, $allowedTables, true)) {
        return [];
    }

    $statement = $pdo->query("SELECT id, name FROM {$table} WHERE is_active = 1 ORDER BY name");

    return $statement->fetchAll();
}
