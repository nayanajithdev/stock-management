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
require_once __DIR__ . '/auth.php';

$config = require __DIR__ . '/../config/app.php';

$dbError = null;
$pdo = app_pdo($dbError);
$dbReady = $pdo !== null && app_database_ready($pdo);

if ($pdo !== null && app_tables_exist($pdo, ['settings'])) {
    $config = array_replace($config, app_fetch_settings($pdo));
}

date_default_timezone_set((string) ($config['timezone'] ?? 'Asia/Colombo'));

$flash = get_flash();
$currentPage = (string) ($_GET['page'] ?? 'dashboard');
$scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$authPages = ['login', 'setup-owner'];
$ownerIsConfigured = $dbReady && $pdo !== null && auth_configured_owner_exists($pdo);
$publicAuthPages = $ownerIsConfigured ? ['login'] : ['setup-owner'];
$publicActions = $ownerIsConfigured ? ['login.php', 'logout.php'] : ['setup_owner.php'];
$isPublicAuthRequest = ($scriptName === 'index.php' && in_array($currentPage, $publicAuthPages, true))
    || in_array($scriptName, $publicActions, true);
$currentUser = null;

if ($dbReady && $pdo !== null) {
    if (! $ownerIsConfigured) {
        if (! ($scriptName === 'index.php' && $currentPage === 'setup-owner') && $scriptName !== 'setup_owner.php') {
            redirect('?page=setup-owner');
        }
    } else {
        $currentUser = auth_current_user($pdo);

        if ($currentUser === null && ! $isPublicAuthRequest) {
            redirect('?page=login');
        }

        if ($currentUser !== null && in_array($currentPage, $authPages, true) && $scriptName === 'index.php') {
            redirect('?page=dashboard');
        }
    }
}

if ($currentUser !== null) {
    $currentUser['name'] = $currentUser['full_name'];
    $currentUser['role_label'] = auth_role_label((string) $currentUser['role']);
}

if ($dbReady && $pdo !== null && $currentUser !== null && str_ends_with($scriptName, '.php')) {
    $requiredActionPermission = auth_action_permission($scriptName);

    if ($requiredActionPermission !== null) {
        auth_require_permission($pdo, $currentUser, $requiredActionPermission);
    }
}

$menuSections = [
    'Main Menu' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard'],
        ['key' => 'products', 'label' => 'Products', 'icon' => 'package-search'],
        ['key' => 'inventory-setup', 'label' => 'Inventory Setup', 'icon' => 'sliders-horizontal'],
        ['key' => 'purchases', 'label' => 'Purchases', 'icon' => 'shopping-cart'],
        ['key' => 'supplier-credit', 'label' => 'Supplier Credit', 'icon' => 'hand-coins'],
        ['key' => 'expenses', 'label' => 'Expenses', 'icon' => 'receipt'],
        ['key' => 'stock', 'label' => 'Stock Movements', 'icon' => 'boxes'],
        ['key' => 'warranty', 'label' => 'Warranty / RMA', 'icon' => 'shield-check'],
    ],
    'Customers' => [
        ['key' => 'customers', 'label' => 'Customer List', 'icon' => 'users'],
        ['key' => 'credit-sales', 'label' => 'Credit Sales', 'icon' => 'receipt-text'],
        ['key' => 'returns', 'label' => 'Returns', 'icon' => 'rotate-ccw'],
    ],
    'Management' => [
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-no-axes-combined'],
        ['key' => 'users', 'label' => 'Users & Roles', 'icon' => 'user-cog'],
        ['key' => 'activity-logs', 'label' => 'Activity Logs', 'icon' => 'list-checks'],
    ],
    'Settings' => [
        ['key' => 'settings', 'label' => 'Shop Settings', 'icon' => 'settings'],
        ['key' => 'invoice-settings', 'label' => 'Invoice Settings', 'icon' => 'file-text'],
        ['key' => 'backup', 'label' => 'Backup', 'icon' => 'database-backup', 'disabled' => true],
    ],
];

if ($dbReady && $pdo !== null && $currentUser !== null) {
    $menuSections = auth_visible_menu_sections($pdo, $currentUser, $menuSections);
}

$pages = [
    'login' => [
        'title' => 'Login',
        'description' => 'Sign in to continue',
        'view' => __DIR__ . '/../pages/login.php',
    ],
    'setup-owner' => [
        'title' => 'Setup',
        'description' => 'Create the first owner account',
        'view' => __DIR__ . '/../pages/setup_owner.php',
    ],
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
    'product-history' => [
        'title' => 'Product History',
        'description' => 'View stock movement history and warranty tracking for one product',
        'view' => __DIR__ . '/../pages/product_history.php',
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
    'supplier-credit' => [
        'title' => 'Supplier Credit',
        'description' => 'Track purchase balances and supplier payments',
        'view' => __DIR__ . '/../pages/supplier_credit.php',
    ],
    'expenses' => [
        'title' => 'Expenses',
        'description' => 'Record operating expenses and track net profit',
        'view' => __DIR__ . '/../pages/expenses.php',
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
    'sale-view' => [
        'title' => 'Invoice Details',
        'description' => 'View sale details and print customer invoice',
        'view' => __DIR__ . '/../pages/sale_view.php',
    ],
    'warranty' => [
        'title' => 'Warranty / RMA',
        'description' => 'Track warranty claims, supplier repair status, and resolutions',
        'view' => __DIR__ . '/../pages/warranty.php',
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
    'reports' => [
        'title' => 'Reports',
        'description' => 'Sales, profit, stock, returns, warranty, and receivable reporting',
        'view' => __DIR__ . '/../pages/reports.php',
    ],
    'users' => [
        'title' => 'Users & Roles',
        'description' => 'Manage owner and manager accounts',
        'view' => __DIR__ . '/../pages/users.php',
    ],
    'profile' => [
        'title' => 'Profile',
        'description' => 'Manage your account details and password',
        'view' => __DIR__ . '/../pages/profile.php',
    ],
    'activity-logs' => [
        'title' => 'Activity Logs',
        'description' => 'Review user actions and system changes',
        'view' => __DIR__ . '/../pages/activity_logs.php',
    ],
    'settings' => [
        'title' => 'Shop Settings',
        'description' => 'Configure shop identity, currency, and system defaults',
        'view' => __DIR__ . '/../pages/settings.php',
    ],
    'invoice-settings' => [
        'title' => 'Invoice Settings',
        'description' => 'Configure invoice tax, footer, and policy text',
        'view' => __DIR__ . '/../pages/invoice_settings.php',
    ],
];

if (! isset($pages[$currentPage])) {
    http_response_code(404);
    $currentPage = 'dashboard';
    set_flash('error', 'That page is not available yet.');
}

if ($dbReady && $pdo !== null && $currentUser !== null && ! in_array($currentPage, $authPages, true)) {
    $requiredPermission = auth_page_permission($currentPage);

    if ($requiredPermission !== null) {
        auth_require_permission($pdo, $currentUser, $requiredPermission);
    }
}

$page = $pages[$currentPage];
$pageTitle = $page['title'];
$pageDescription = $page['description'];
