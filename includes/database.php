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
    app_create_missing_tables($pdo);
    app_add_missing_columns($pdo);
    app_add_missing_indexes($pdo);
    app_seed_default_settings($pdo);
    app_migrate_legacy_permissions($pdo);
}

function app_schema_exec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable) {
        return;
    }
}

function app_index_exists(PDO $pdo, string $table, string $index): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = :table_name
           AND index_name = :index_name'
    );
    $statement->execute([
        'table_name' => $table,
        'index_name' => $index,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

function app_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (! app_tables_exist($pdo, [$table]) || app_column_exists($pdo, $table, $column)) {
        return;
    }

    app_schema_exec($pdo, 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function app_add_index_if_missing(PDO $pdo, string $table, string $index, string $sql): void
{
    if (! app_tables_exist($pdo, [$table]) || app_index_exists($pdo, $table, $index)) {
        return;
    }

    app_schema_exec($pdo, $sql);
}

function app_create_missing_tables(PDO $pdo): void
{
    $tables = [
        'users' => <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NULL UNIQUE,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'owner',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'user_permissions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT UNSIGNED NOT NULL,
    permission_key VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_key),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'categories' => <<<'SQL'
CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'brands' => <<<'SQL'
CREATE TABLE IF NOT EXISTS brands (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'suppliers' => <<<'SQL'
CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL UNIQUE,
    contact_person VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(120) NULL,
    address TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'customers' => <<<'SQL'
CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(120) NULL,
    address TEXT NULL,
    credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'products' => <<<'SQL'
CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NULL,
    brand_id INT UNSIGNED NULL,
    supplier_id INT UNSIGNED NULL,
    sku VARCHAR(80) NOT NULL UNIQUE,
    barcode VARCHAR(120) NULL UNIQUE,
    name VARCHAR(180) NOT NULL,
    model VARCHAR(120) NULL,
    description TEXT NULL,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    warranty_months INT UNSIGNED NOT NULL DEFAULT 0,
    item_tracking TINYINT(1) NOT NULL DEFAULT 0,
    reorder_level INT UNSIGNED NOT NULL DEFAULT 0,
    current_stock INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    INDEX idx_products_name (name),
    INDEX idx_products_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'product_serials' => <<<'SQL'
CREATE TABLE IF NOT EXISTS product_serials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    serial_number VARCHAR(160) NOT NULL UNIQUE,
    status VARCHAR(30) NOT NULL DEFAULT 'in_stock',
    purchase_item_id BIGINT UNSIGNED NULL,
    sale_item_id BIGINT UNSIGNED NULL,
    purchase_date DATE NULL,
    supplier_warranty_months INT UNSIGNED NOT NULL DEFAULT 0,
    warranty_expires_at DATE NULL,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    source_type VARCHAR(40) NULL,
    source_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_serials_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_serials_status (status),
    INDEX idx_product_serials_product_status (product_id, status),
    INDEX idx_product_serials_warranty (warranty_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'stock_movements' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    movement_type VARCHAR(40) NOT NULL,
    quantity_change INT NOT NULL,
    stock_after INT NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    warranty_months INT UNSIGNED NOT NULL DEFAULT 0,
    reference_type VARCHAR(40) NULL,
    reference_id BIGINT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_movements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_movements_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_stock_movements_product (product_id),
    INDEX idx_stock_movements_type (movement_type),
    INDEX idx_stock_movements_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'purchases' => <<<'SQL'
CREATE TABLE IF NOT EXISTS purchases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT UNSIGNED NULL,
    invoice_no VARCHAR(80) NULL,
    purchase_date DATE NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(30) NOT NULL DEFAULT 'received',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    INDEX idx_purchases_date (purchase_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'purchase_items' => <<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    warranty_months INT UNSIGNED NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'supplier_payments' => <<<'SQL'
CREATE TABLE IF NOT EXISTS supplier_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id BIGINT UNSIGNED NOT NULL,
    supplier_id INT UNSIGNED NULL,
    payment_date DATETIME NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(40) NOT NULL DEFAULT 'cash',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_supplier_payments_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_supplier_payments_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
    INDEX idx_supplier_payments_date (payment_date),
    INDEX idx_supplier_payments_supplier (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'expenses' => <<<'SQL'
CREATE TABLE IF NOT EXISTS expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category VARCHAR(80) NOT NULL,
    vendor VARCHAR(160) NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(40) NOT NULL DEFAULT 'cash',
    reference_no VARCHAR(100) NULL,
    notes TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_expenses_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_category (category),
    INDEX idx_expenses_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'sales' => <<<'SQL'
CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NULL,
    invoice_no VARCHAR(80) NOT NULL UNIQUE,
    sale_date DATETIME NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(40) NOT NULL DEFAULT 'cash',
    status VARCHAR(30) NOT NULL DEFAULT 'paid',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    INDEX idx_sales_date (sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'sale_items' => <<<'SQL'
CREATE TABLE IF NOT EXISTS sale_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'customer_payments' => <<<'SQL'
CREATE TABLE IF NOT EXISTS customer_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    payment_date DATETIME NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(40) NOT NULL DEFAULT 'cash',
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_payments_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_payments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    INDEX idx_customer_payments_date (payment_date),
    INDEX idx_customer_payments_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'sales_returns' => <<<'SQL'
CREATE TABLE IF NOT EXISTS sales_returns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id BIGINT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    return_no VARCHAR(80) NOT NULL UNIQUE,
    return_date DATETIME NOT NULL,
    refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    refund_method VARCHAR(40) NOT NULL DEFAULT 'cash',
    notes TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'completed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sales_returns_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_returns_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    INDEX idx_sales_returns_date (return_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'sales_return_items' => <<<'SQL'
CREATE TABLE IF NOT EXISTS sales_return_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id BIGINT UNSIGNED NOT NULL,
    sale_item_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    restock TINYINT(1) NOT NULL DEFAULT 1,
    condition_status VARCHAR(40) NOT NULL DEFAULT 'resellable',
    total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_sales_return_items_return FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_return_items_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sales_return_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    INDEX idx_sales_return_items_sale_item (sale_item_id),
    INDEX idx_sales_return_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'warranty_claims' => <<<'SQL'
CREATE TABLE IF NOT EXISTS warranty_claims (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NULL,
    product_id INT UNSIGNED NOT NULL,
    serial_id INT UNSIGNED NULL,
    sale_id BIGINT UNSIGNED NULL,
    sale_item_id BIGINT UNSIGNED NULL,
    claim_no VARCHAR(80) NOT NULL UNIQUE,
    issue_description TEXT NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'received',
    received_date DATE NOT NULL,
    resolved_date DATE NULL,
    supplier_notes TEXT NULL,
    supplier_refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    supplier_refund_date DATE NULL,
    replacement_mode VARCHAR(30) NOT NULL DEFAULT 'wait_supplier',
    customer_replacement_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    customer_replaced_at DATETIME NULL,
    supplier_replacement_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    supplier_replaced_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_warranty_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_warranty_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_warranty_serial FOREIGN KEY (serial_id) REFERENCES product_serials(id) ON DELETE SET NULL,
    CONSTRAINT fk_warranty_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_warranty_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id) ON DELETE SET NULL,
    INDEX idx_warranty_claims_status (status),
    INDEX idx_warranty_claims_received_date (received_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'activity_logs' => <<<'SQL'
CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_activity_logs_user (user_id),
    INDEX idx_activity_logs_action (action),
    INDEX idx_activity_logs_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];

    foreach ($tables as $sql) {
        app_schema_exec($pdo, $sql);
    }
}

function app_add_missing_columns(PDO $pdo): void
{
    $columnsByTable = [
        'users' => [
            'full_name' => 'VARCHAR(120) NOT NULL DEFAULT \'\'',
            'email' => 'VARCHAR(160) NULL',
            'username' => 'VARCHAR(80) NULL',
            'password_hash' => 'VARCHAR(255) NOT NULL DEFAULT \'\'',
            'role' => 'VARCHAR(40) NOT NULL DEFAULT \'owner\'',
            'status' => 'VARCHAR(20) NOT NULL DEFAULT \'active\'',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'user_permissions' => [
            'user_id' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'permission_key' => 'VARCHAR(80) NOT NULL DEFAULT \'\'',
            'allowed' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'categories' => [
            'name' => 'VARCHAR(120) NOT NULL DEFAULT \'\'',
            'description' => 'VARCHAR(255) NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'brands' => [
            'name' => 'VARCHAR(120) NOT NULL DEFAULT \'\'',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'suppliers' => [
            'name' => 'VARCHAR(160) NOT NULL DEFAULT \'\'',
            'contact_person' => 'VARCHAR(120) NULL',
            'phone' => 'VARCHAR(40) NULL',
            'email' => 'VARCHAR(120) NULL',
            'address' => 'TEXT NULL',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'customers' => [
            'name' => 'VARCHAR(160) NOT NULL DEFAULT \'\'',
            'phone' => 'VARCHAR(40) NULL',
            'email' => 'VARCHAR(120) NULL',
            'address' => 'TEXT NULL',
            'credit_limit' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'products' => [
            'category_id' => 'INT UNSIGNED NULL',
            'brand_id' => 'INT UNSIGNED NULL',
            'supplier_id' => 'INT UNSIGNED NULL',
            'sku' => 'VARCHAR(80) NULL',
            'barcode' => 'VARCHAR(120) NULL',
            'name' => 'VARCHAR(180) NOT NULL DEFAULT \'\'',
            'model' => 'VARCHAR(120) NULL',
            'description' => 'TEXT NULL',
            'cost_price' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'selling_price' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'wholesale_price' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'warranty_months' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'item_tracking' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'reorder_level' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'current_stock' => 'INT NOT NULL DEFAULT 0',
            'status' => 'VARCHAR(20) NOT NULL DEFAULT \'active\'',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'product_serials' => [
            'product_id' => 'INT UNSIGNED NULL',
            'serial_number' => 'VARCHAR(160) NULL',
            'status' => 'VARCHAR(30) NOT NULL DEFAULT \'in_stock\'',
            'purchase_item_id' => 'BIGINT UNSIGNED NULL',
            'sale_item_id' => 'BIGINT UNSIGNED NULL',
            'purchase_date' => 'DATE NULL',
            'supplier_warranty_months' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'warranty_expires_at' => 'DATE NULL',
            'unit_cost' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'source_type' => 'VARCHAR(40) NULL',
            'source_id' => 'BIGINT UNSIGNED NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'stock_movements' => [
            'product_id' => 'INT UNSIGNED NULL',
            'movement_type' => 'VARCHAR(40) NOT NULL DEFAULT \'adjustment_in\'',
            'quantity_change' => 'INT NOT NULL DEFAULT 0',
            'stock_after' => 'INT NOT NULL DEFAULT 0',
            'unit_cost' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'warranty_months' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'reference_type' => 'VARCHAR(40) NULL',
            'reference_id' => 'BIGINT UNSIGNED NULL',
            'notes' => 'VARCHAR(255) NULL',
            'created_by' => 'INT UNSIGNED NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
        'purchases' => [
            'supplier_id' => 'INT UNSIGNED NULL',
            'invoice_no' => 'VARCHAR(80) NULL',
            'purchase_date' => 'DATE NULL',
            'subtotal' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'discount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'total' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'paid' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'status' => 'VARCHAR(30) NOT NULL DEFAULT \'received\'',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'purchase_items' => [
            'purchase_id' => 'BIGINT UNSIGNED NULL',
            'product_id' => 'INT UNSIGNED NULL',
            'quantity' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'unit_cost' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'warranty_months' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'total' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        ],
        'supplier_payments' => [
            'purchase_id' => 'BIGINT UNSIGNED NULL',
            'supplier_id' => 'INT UNSIGNED NULL',
            'payment_date' => 'DATETIME NULL',
            'amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'payment_method' => 'VARCHAR(40) NOT NULL DEFAULT \'cash\'',
            'notes' => 'VARCHAR(255) NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
        'expenses' => [
            'expense_date' => 'DATE NULL',
            'category' => 'VARCHAR(80) NOT NULL DEFAULT \'General\'',
            'vendor' => 'VARCHAR(160) NULL',
            'amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'payment_method' => 'VARCHAR(40) NOT NULL DEFAULT \'cash\'',
            'reference_no' => 'VARCHAR(100) NULL',
            'notes' => 'TEXT NULL',
            'status' => 'VARCHAR(30) NOT NULL DEFAULT \'active\'',
            'created_by' => 'INT UNSIGNED NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'sales' => [
            'customer_id' => 'INT UNSIGNED NULL',
            'invoice_no' => 'VARCHAR(80) NULL',
            'sale_date' => 'DATETIME NULL',
            'subtotal' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'discount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'tax' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'total' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'paid' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'payment_method' => 'VARCHAR(40) NOT NULL DEFAULT \'cash\'',
            'status' => 'VARCHAR(30) NOT NULL DEFAULT \'paid\'',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'sale_items' => [
            'sale_id' => 'BIGINT UNSIGNED NULL',
            'product_id' => 'INT UNSIGNED NULL',
            'quantity' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'unit_price' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'unit_cost' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'discount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'total' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        ],
        'customer_payments' => [
            'sale_id' => 'BIGINT UNSIGNED NULL',
            'customer_id' => 'INT UNSIGNED NULL',
            'payment_date' => 'DATETIME NULL',
            'amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'payment_method' => 'VARCHAR(40) NOT NULL DEFAULT \'cash\'',
            'notes' => 'VARCHAR(255) NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
        'sales_returns' => [
            'sale_id' => 'BIGINT UNSIGNED NULL',
            'customer_id' => 'INT UNSIGNED NULL',
            'return_no' => 'VARCHAR(80) NULL',
            'return_date' => 'DATETIME NULL',
            'refund_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'refund_method' => 'VARCHAR(40) NOT NULL DEFAULT \'cash\'',
            'notes' => 'TEXT NULL',
            'status' => 'VARCHAR(30) NOT NULL DEFAULT \'completed\'',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'sales_return_items' => [
            'return_id' => 'BIGINT UNSIGNED NULL',
            'sale_item_id' => 'BIGINT UNSIGNED NULL',
            'product_id' => 'INT UNSIGNED NULL',
            'quantity' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'unit_price' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'unit_cost' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'restock' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'condition_status' => 'VARCHAR(40) NOT NULL DEFAULT \'resellable\'',
            'total' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        ],
        'warranty_claims' => [
            'customer_id' => 'INT UNSIGNED NULL',
            'product_id' => 'INT UNSIGNED NULL',
            'serial_id' => 'INT UNSIGNED NULL',
            'sale_id' => 'BIGINT UNSIGNED NULL',
            'sale_item_id' => 'BIGINT UNSIGNED NULL',
            'claim_no' => 'VARCHAR(80) NULL',
            'issue_description' => 'TEXT NULL',
            'status' => 'VARCHAR(40) NOT NULL DEFAULT \'received\'',
            'received_date' => 'DATE NULL',
            'resolved_date' => 'DATE NULL',
            'supplier_notes' => 'TEXT NULL',
            'supplier_refund_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'supplier_refund_date' => 'DATE NULL',
            'replacement_mode' => 'VARCHAR(30) NOT NULL DEFAULT \'wait_supplier\'',
            'customer_replacement_status' => 'VARCHAR(30) NOT NULL DEFAULT \'pending\'',
            'customer_replaced_at' => 'DATETIME NULL',
            'supplier_replacement_status' => 'VARCHAR(30) NOT NULL DEFAULT \'pending\'',
            'supplier_replaced_at' => 'DATETIME NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'settings' => [
            'setting_key' => 'VARCHAR(120) NULL',
            'setting_value' => 'TEXT NULL',
            'updated_at' => 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
        ],
        'activity_logs' => [
            'user_id' => 'INT UNSIGNED NULL',
            'action' => 'VARCHAR(80) NOT NULL DEFAULT \'system\'',
            'description' => 'VARCHAR(255) NOT NULL DEFAULT \'Schema upgrade\'',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        ],
    ];

    foreach ($columnsByTable as $table => $columns) {
        foreach ($columns as $column => $definition) {
            app_add_column_if_missing($pdo, $table, $column, $definition);
        }
    }
}

function app_add_missing_indexes(PDO $pdo): void
{
    $indexes = [
        ['customers', 'idx_customers_phone', 'ALTER TABLE customers ADD INDEX idx_customers_phone (phone)'],
        ['products', 'idx_products_name', 'ALTER TABLE products ADD INDEX idx_products_name (name)'],
        ['products', 'idx_products_status', 'ALTER TABLE products ADD INDEX idx_products_status (status)'],
        ['product_serials', 'idx_product_serials_status', 'ALTER TABLE product_serials ADD INDEX idx_product_serials_status (status)'],
        ['product_serials', 'idx_product_serials_product_status', 'ALTER TABLE product_serials ADD INDEX idx_product_serials_product_status (product_id, status)'],
        ['product_serials', 'idx_product_serials_warranty', 'ALTER TABLE product_serials ADD INDEX idx_product_serials_warranty (warranty_expires_at)'],
        ['stock_movements', 'idx_stock_movements_product', 'ALTER TABLE stock_movements ADD INDEX idx_stock_movements_product (product_id)'],
        ['stock_movements', 'idx_stock_movements_type', 'ALTER TABLE stock_movements ADD INDEX idx_stock_movements_type (movement_type)'],
        ['stock_movements', 'idx_stock_movements_created_at', 'ALTER TABLE stock_movements ADD INDEX idx_stock_movements_created_at (created_at)'],
        ['purchases', 'idx_purchases_date', 'ALTER TABLE purchases ADD INDEX idx_purchases_date (purchase_date)'],
        ['supplier_payments', 'idx_supplier_payments_date', 'ALTER TABLE supplier_payments ADD INDEX idx_supplier_payments_date (payment_date)'],
        ['supplier_payments', 'idx_supplier_payments_supplier', 'ALTER TABLE supplier_payments ADD INDEX idx_supplier_payments_supplier (supplier_id)'],
        ['expenses', 'idx_expenses_date', 'ALTER TABLE expenses ADD INDEX idx_expenses_date (expense_date)'],
        ['expenses', 'idx_expenses_category', 'ALTER TABLE expenses ADD INDEX idx_expenses_category (category)'],
        ['expenses', 'idx_expenses_status', 'ALTER TABLE expenses ADD INDEX idx_expenses_status (status)'],
        ['sales', 'idx_sales_date', 'ALTER TABLE sales ADD INDEX idx_sales_date (sale_date)'],
        ['customer_payments', 'idx_customer_payments_date', 'ALTER TABLE customer_payments ADD INDEX idx_customer_payments_date (payment_date)'],
        ['customer_payments', 'idx_customer_payments_customer', 'ALTER TABLE customer_payments ADD INDEX idx_customer_payments_customer (customer_id)'],
        ['sales_returns', 'idx_sales_returns_date', 'ALTER TABLE sales_returns ADD INDEX idx_sales_returns_date (return_date)'],
        ['sales_return_items', 'idx_sales_return_items_sale_item', 'ALTER TABLE sales_return_items ADD INDEX idx_sales_return_items_sale_item (sale_item_id)'],
        ['sales_return_items', 'idx_sales_return_items_product', 'ALTER TABLE sales_return_items ADD INDEX idx_sales_return_items_product (product_id)'],
        ['warranty_claims', 'idx_warranty_claims_status', 'ALTER TABLE warranty_claims ADD INDEX idx_warranty_claims_status (status)'],
        ['warranty_claims', 'idx_warranty_claims_received_date', 'ALTER TABLE warranty_claims ADD INDEX idx_warranty_claims_received_date (received_date)'],
        ['activity_logs', 'idx_activity_logs_user', 'ALTER TABLE activity_logs ADD INDEX idx_activity_logs_user (user_id)'],
        ['activity_logs', 'idx_activity_logs_action', 'ALTER TABLE activity_logs ADD INDEX idx_activity_logs_action (action)'],
        ['activity_logs', 'idx_activity_logs_created_at', 'ALTER TABLE activity_logs ADD INDEX idx_activity_logs_created_at (created_at)'],
    ];

    foreach ($indexes as [$table, $index, $sql]) {
        app_add_index_if_missing($pdo, $table, $index, $sql);
    }
}

function app_seed_default_settings(PDO $pdo): void
{
    if (! app_tables_exist($pdo, ['settings'])) {
        return;
    }

    $defaults = [
        'shop_name' => 'Tech Accessories Hub',
        'shop_legal_name' => '',
        'shop_phone' => '',
        'shop_email' => '',
        'shop_address' => '',
        'shop_website' => '',
        'shop_logo' => '',
        'currency' => 'Rs.',
        'timezone' => 'Asia/Colombo',
        'default_tax_percent' => '0',
        'default_reorder_level' => '5',
        'invoice_footer' => 'Thank you for your business.',
        'return_policy' => 'Returns are accepted with invoice and original condition.',
        'warranty_policy' => 'Warranty claims require invoice and item inspection.',
    ];
    $statement = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value)
         SELECT :setting_key, :setting_value
         WHERE NOT EXISTS (
            SELECT 1 FROM settings WHERE setting_key = :setting_key_check
         )'
    );

    foreach ($defaults as $key => $value) {
        try {
            $statement->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'setting_key_check' => $key,
            ]);
        } catch (Throwable) {
            return;
        }
    }
}

function app_migrate_legacy_permissions(PDO $pdo): void
{
    if (! app_tables_exist($pdo, ['user_permissions'])) {
        return;
    }

    app_schema_exec(
        $pdo,
        'INSERT INTO user_permissions (user_id, permission_key, allowed)
         SELECT source.user_id, "backup", source.allowed
         FROM (
            SELECT user_id, MAX(CASE WHEN allowed = 1 THEN 1 ELSE 0 END) AS allowed
            FROM user_permissions
            WHERE permission_key = "settings"
            GROUP BY user_id
         ) source
         WHERE NOT EXISTS (
            SELECT 1
            FROM user_permissions existing
            WHERE existing.user_id = source.user_id
              AND existing.permission_key = "backup"
         )'
    );

    app_schema_exec(
        $pdo,
        'INSERT INTO user_permissions (user_id, permission_key, allowed)
         SELECT source.user_id, "warranty_returns", source.allowed
         FROM (
            SELECT user_id, MAX(CASE WHEN allowed = 1 THEN 1 ELSE 0 END) AS allowed
            FROM user_permissions
            WHERE permission_key IN ("warranty", "returns")
            GROUP BY user_id
         ) source
         WHERE NOT EXISTS (
            SELECT 1
            FROM user_permissions existing
            WHERE existing.user_id = source.user_id
              AND existing.permission_key = "warranty_returns"
         )'
    );
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
