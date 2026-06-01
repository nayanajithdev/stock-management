<?php

declare(strict_types=1);

function auth_users_have_email_column(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = "users"
           AND column_name = "email"'
    );
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

function auth_configured_owner_exists(PDO $pdo): bool
{
    if (! auth_users_have_email_column($pdo)) {
        return false;
    }

    $statement = $pdo->query(
        'SELECT COUNT(*)
         FROM users
         WHERE role = "owner"
           AND email IS NOT NULL
           AND email <> ""'
    );

    return (int) $statement->fetchColumn() > 0;
}

function auth_current_user(PDO $pdo): ?array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId <= 0 || ! auth_users_have_email_column($pdo)) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, full_name, email, username, role, status
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    if (! is_array($user)) {
        auth_logout_session();

        return null;
    }

    if ((string) $user['status'] !== 'active') {
        auth_logout_session();
        set_flash('error', 'Your account is inactive. Contact the owner.');

        return null;
    }

    return $user;
}

function auth_login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = (string) $user['role'];
}

function auth_logout_session(): void
{
    unset($_SESSION['user_id'], $_SESSION['user_role']);
    session_regenerate_id(true);
}

function auth_permission_definitions(): array
{
    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'description' => 'Overview, KPIs, alerts, and recent activity.',
            'pages' => ['dashboard'],
        ],
        'products' => [
            'label' => 'Products',
            'description' => 'Create, update, archive, and view products.',
            'pages' => ['products', 'product-history'],
        ],
        'inventory_setup' => [
            'label' => 'Inventory Setup',
            'description' => 'Manage categories, brands, and suppliers.',
            'pages' => ['inventory-setup'],
        ],
        'purchases' => [
            'label' => 'Purchases',
            'description' => 'Receive supplier stock and view purchase history.',
            'pages' => ['purchases', 'purchase-history'],
        ],
        'supplier_credit' => [
            'label' => 'Supplier Credit',
            'description' => 'Track payable purchases and record supplier payments.',
            'pages' => ['supplier-credit'],
        ],
        'expenses' => [
            'label' => 'Expenses',
            'description' => 'Record, view, and void operating expenses.',
            'pages' => ['expenses'],
        ],
        'stock' => [
            'label' => 'Stock Movements',
            'description' => 'View stock history and make manual adjustments.',
            'pages' => ['stock'],
        ],
        'sales' => [
            'label' => 'Sales & Invoices',
            'description' => 'Create sales, view invoices, and print bills.',
            'pages' => ['sales', 'sales-history', 'sale-view'],
        ],
        'warranty' => [
            'label' => 'Warranty / RMA',
            'description' => 'Create and update warranty claims.',
            'pages' => ['warranty'],
        ],
        'customers' => [
            'label' => 'Customers',
            'description' => 'Manage customer accounts and balances.',
            'pages' => ['customers'],
        ],
        'credit_sales' => [
            'label' => 'Credit Sales',
            'description' => 'View receivables and collect customer payments.',
            'pages' => ['credit-sales', 'payment-receipt'],
        ],
        'returns' => [
            'label' => 'Returns',
            'description' => 'Process sales returns and refunds.',
            'pages' => ['returns'],
        ],
        'reports' => [
            'label' => 'Reports',
            'description' => 'View business, sales, stock, and profit reports.',
            'pages' => ['reports'],
        ],
        'activity_logs' => [
            'label' => 'Activity Logs',
            'description' => 'Review audit history and user actions.',
            'pages' => ['activity-logs'],
        ],
        'settings' => [
            'label' => 'Shop Settings',
            'description' => 'Update business profile, invoice settings, and system defaults.',
            'pages' => ['settings', 'invoice-settings', 'backup'],
        ],
    ];
}

function auth_permission_keys(): array
{
    return array_keys(auth_permission_definitions());
}

function auth_page_permission(string $page): ?string
{
    foreach (auth_permission_definitions() as $key => $definition) {
        if (in_array($page, $definition['pages'], true)) {
            return $key;
        }
    }

    return null;
}

function auth_action_permission(string $scriptName): ?string
{
    return match ($scriptName) {
        'product_save.php', 'product_delete.php' => 'products',
        'master_save.php', 'master_archive.php' => 'inventory_setup',
        'purchase_save.php', 'product_search.php', 'supplier_search.php' => 'purchases',
        'supplier_payment_collect.php' => 'supplier_credit',
        'expense_save.php', 'expense_void.php' => 'expenses',
        'stock_adjust.php' => 'stock',
        'sale_save.php', 'sale_product_search.php', 'customer_search.php' => 'sales',
        'warranty_save.php', 'warranty_lookup.php' => 'warranty',
        'customer_save.php', 'customer_archive.php' => 'customers',
        'payment_collect.php' => 'credit_sales',
        'sales_return_save.php', 'return_lookup.php' => 'returns',
        'settings_save.php', 'invoice_settings_save.php', 'backup_download.php', 'backup_restore.php' => 'settings',
        default => null,
    };
}

function auth_permissions_table_exists(PDO $pdo): bool
{
    return app_tables_exist($pdo, ['user_permissions']);
}

function auth_user_permissions(PDO $pdo, int $userId): array
{
    if ($userId <= 0 || ! auth_permissions_table_exists($pdo)) {
        return [];
    }

    $statement = $pdo->prepare('SELECT permission_key, allowed FROM user_permissions WHERE user_id = :user_id');
    $statement->execute(['user_id' => $userId]);
    $permissions = [];

    foreach ($statement->fetchAll() as $row) {
        $permissions[(string) $row['permission_key']] = (int) $row['allowed'] === 1;
    }

    return $permissions;
}

function auth_user_has_permission(PDO $pdo, ?array $user, string $permission): bool
{
    if (! is_array($user)) {
        return false;
    }

    if (($user['role'] ?? '') === 'owner') {
        return true;
    }

    if ($permission === 'dashboard') {
        return true;
    }

    if (! in_array($permission, auth_permission_keys(), true)) {
        return false;
    }

    $permissions = auth_user_permissions($pdo, (int) ($user['id'] ?? 0));

    if ($permissions === []) {
        return true;
    }

    return $permissions[$permission] ?? false;
}

function auth_visible_menu_sections(PDO $pdo, ?array $user, array $menuSections): array
{
    if (! is_array($user) || ($user['role'] ?? '') === 'owner') {
        return $menuSections;
    }

    foreach ($menuSections as $section => $items) {
        $visibleItems = [];

        foreach ($items as $item) {
            $permission = auth_page_permission((string) ($item['key'] ?? ''));

            if ($permission === null || auth_user_has_permission($pdo, $user, $permission)) {
                $visibleItems[] = $item;
            }
        }

        if ($visibleItems === []) {
            unset($menuSections[$section]);
        } else {
            $menuSections[$section] = $visibleItems;
        }
    }

    return $menuSections;
}

function auth_require_permission(PDO $pdo, ?array $user, string $permission): void
{
    if (auth_user_has_permission($pdo, $user, $permission)) {
        return;
    }

    set_flash('error', 'You do not have permission to access that module.');
    redirect('?page=dashboard');
}

function auth_require_owner(?array $currentUser): void
{
    if (($currentUser['role'] ?? '') === 'owner') {
        return;
    }

    set_flash('error', 'Only the owner can manage users.');
    redirect('?page=dashboard');
}

function auth_role_label(string $role): string
{
    return match ($role) {
        'owner' => 'Owner',
        'manager' => 'Manager',
        default => ucfirst($role),
    };
}
