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

function app_database_ready(PDO $pdo): bool
{
    return app_tables_exist($pdo, [
        'users',
        'categories',
        'brands',
        'suppliers',
        'products',
        'stock_movements',
        'sales',
        'customer_payments',
        'sales_returns',
        'sales_return_items',
        'purchases',
    ]);
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
