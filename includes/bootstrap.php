<?php

declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => ! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/database.php';

$config = require __DIR__ . '/../config/app.php';
date_default_timezone_set((string) ($config['timezone'] ?? 'Asia/Colombo'));

$dbError = null;
$pdo = app_pdo($dbError);
$dbReady = $pdo !== null && app_database_ready($pdo);
$flash = get_flash();

$currentUser = [
    'name' => 'Salunga',
    'role' => 'Owner',
];

$menuSections = [
    'Main Menu' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard'],
        ['key' => 'products', 'label' => 'Products', 'icon' => 'package-search'],
        ['key' => 'inventory-setup', 'label' => 'Inventory Setup', 'icon' => 'sliders-horizontal'],
        ['key' => 'purchases', 'label' => 'Purchases', 'icon' => 'shopping-cart'],
        ['key' => 'stock', 'label' => 'Stock Movements', 'icon' => 'boxes'],
        ['key' => 'sales', 'label' => 'Sales POS', 'icon' => 'scan-barcode'],
        ['key' => 'warranty', 'label' => 'Warranty / RMA', 'icon' => 'shield-check', 'disabled' => true],
    ],
    'Customers' => [
        ['key' => 'customers', 'label' => 'Customer List', 'icon' => 'users'],
        ['key' => 'credit-sales', 'label' => 'Credit Sales', 'icon' => 'receipt-text'],
        ['key' => 'returns', 'label' => 'Returns', 'icon' => 'rotate-ccw'],
    ],
    'Management' => [
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-no-axes-combined', 'disabled' => true],
        ['key' => 'users', 'label' => 'Users & Roles', 'icon' => 'user-cog', 'disabled' => true],
    ],
    'Settings' => [
        ['key' => 'settings', 'label' => 'Shop Settings', 'icon' => 'settings', 'disabled' => true],
        ['key' => 'backup', 'label' => 'Backup', 'icon' => 'database-backup', 'disabled' => true],
    ],
];

$pages = [
    'dashboard' => [
        'title' => 'Dashboard',
        'description' => 'Stock, sales, and reorder overview',
        'view' => __DIR__ . '/../pages/dashboard.php',
    ],
    'products' => [
        'title' => 'Products',
        'description' => 'Create products, prices, warranty rules, and reorder levels',
        'view' => __DIR__ . '/../pages/products.php',
    ],
    'inventory-setup' => [
        'title' => 'Inventory Setup',
        'description' => 'Manage categories, brands, and suppliers',
        'view' => __DIR__ . '/../pages/inventory_setup.php',
    ],
    'purchases' => [
        'title' => 'Purchases',
        'description' => 'Receive supplier stock and update inventory',
        'view' => __DIR__ . '/../pages/purchases.php',
    ],
    'stock' => [
        'title' => 'Stock Movements',
        'description' => 'Audit stock changes and make controlled adjustments',
        'view' => __DIR__ . '/../pages/stock.php',
    ],
    'sales' => [
        'title' => 'Sales POS',
        'description' => 'Create invoices and reduce stock',
        'view' => __DIR__ . '/../pages/sales.php',
    ],
    'customers' => [
        'title' => 'Customers',
        'description' => 'Manage customer profiles and balances',
        'view' => __DIR__ . '/../pages/customers.php',
    ],
    'credit-sales' => [
        'title' => 'Credit Sales',
        'description' => 'Track unpaid and partially paid invoices',
        'view' => __DIR__ . '/../pages/credit_sales.php',
    ],
    'returns' => [
        'title' => 'Returns',
        'description' => 'Process sales returns and restock eligible items',
        'view' => __DIR__ . '/../pages/returns.php',
    ],
];

$currentPage = (string) ($_GET['page'] ?? 'dashboard');

if (! isset($pages[$currentPage])) {
    http_response_code(404);
    $currentPage = 'dashboard';
    set_flash('error', 'That page is not available yet.');
}

$page = $pages[$currentPage];
$pageTitle = $page['title'];
$pageDescription = $page['description'];
