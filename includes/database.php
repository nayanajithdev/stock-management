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

    return app_column_exists($pdo, 'users', 'email');
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
