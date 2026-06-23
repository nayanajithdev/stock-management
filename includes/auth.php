<?php

declare(strict_types=1);

const AUTH_LOGIN_WINDOW_MINUTES = 15;
const AUTH_LOGIN_IDENTIFIER_LIMIT = 5;
const AUTH_LOGIN_IP_LIMIT = 25;
const AUTH_LOGIN_ATTEMPT_RETENTION_DAYS = 7;

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
        app_log_security_event('inactive_user_logout', 'Inactive account session blocked for user #' . (int) $user['id'] . '.', $user);
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

function auth_login_identifier(string $login): string
{
    return substr(strtolower(trim($login)), 0, 190);
}

function auth_client_ip(): string
{
    $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        return substr($ipAddress, 0, 45);
    }

    return 'unknown';
}

function auth_prune_login_attempts(PDO $pdo): void
{
    if (! app_tables_exist($pdo, ['login_attempts'])) {
        return;
    }

    try {
        $pdo->exec(
            'DELETE FROM login_attempts
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ' . AUTH_LOGIN_ATTEMPT_RETENTION_DAYS . ' DAY)'
        );
    } catch (Throwable) {
        return;
    }
}

function auth_login_lockout(PDO $pdo, string $identifier, string $ipAddress): ?array
{
    if ($identifier === '' || ! app_tables_exist($pdo, ['login_attempts'])) {
        return null;
    }

    $identifierWindow = auth_login_failure_window($pdo, $identifier, $ipAddress);
    $ipWindow = auth_login_failure_window($pdo, null, $ipAddress);

    $minutes = 0;

    if ($identifierWindow['fail_count'] >= AUTH_LOGIN_IDENTIFIER_LIMIT) {
        $minutes = max($minutes, $identifierWindow['remaining_minutes']);
    }

    if ($ipWindow['fail_count'] >= AUTH_LOGIN_IP_LIMIT) {
        $minutes = max($minutes, $ipWindow['remaining_minutes']);
    }

    if ($minutes <= 0) {
        return null;
    }

    return [
        'remaining_minutes' => $minutes,
    ];
}

function auth_login_failure_window(PDO $pdo, ?string $identifier, string $ipAddress): array
{
    try {
        if ($identifier === null) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) AS fail_count,
                        COALESCE(GREATEST(1, ' . AUTH_LOGIN_WINDOW_MINUTES . ' - TIMESTAMPDIFF(MINUTE, MIN(attempted_at), NOW())), ' . AUTH_LOGIN_WINDOW_MINUTES . ') AS remaining_minutes
                 FROM login_attempts
                 WHERE was_success = 0
                   AND ip_address = :ip_address
                   AND attempted_at >= DATE_SUB(NOW(), INTERVAL ' . AUTH_LOGIN_WINDOW_MINUTES . ' MINUTE)'
            );
            $statement->execute(['ip_address' => $ipAddress]);
        } else {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) AS fail_count,
                        COALESCE(GREATEST(1, ' . AUTH_LOGIN_WINDOW_MINUTES . ' - TIMESTAMPDIFF(MINUTE, MIN(attempted_at), NOW())), ' . AUTH_LOGIN_WINDOW_MINUTES . ') AS remaining_minutes
                 FROM login_attempts
                 WHERE was_success = 0
                   AND login_identifier = :login_identifier
                   AND ip_address = :ip_address
                   AND attempted_at >= DATE_SUB(NOW(), INTERVAL ' . AUTH_LOGIN_WINDOW_MINUTES . ' MINUTE)'
            );
            $statement->execute([
                'login_identifier' => $identifier,
                'ip_address' => $ipAddress,
            ]);
        }

        $row = $statement->fetch();
    } catch (Throwable) {
        return [
            'fail_count' => 0,
            'remaining_minutes' => 0,
        ];
    }

    if (! is_array($row)) {
        return [
            'fail_count' => 0,
            'remaining_minutes' => 0,
        ];
    }

    return [
        'fail_count' => (int) ($row['fail_count'] ?? 0),
        'remaining_minutes' => max(1, (int) ($row['remaining_minutes'] ?? AUTH_LOGIN_WINDOW_MINUTES)),
    ];
}

function auth_record_login_attempt(PDO $pdo, string $identifier, string $ipAddress, ?int $userId, bool $success, ?string $failureReason = null): void
{
    if ($identifier === '' || ! app_tables_exist($pdo, ['login_attempts'])) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO login_attempts (user_id, login_identifier, ip_address, was_success, failure_reason)
             VALUES (:user_id, :login_identifier, :ip_address, :was_success, :failure_reason)'
        );
        $statement->execute([
            'user_id' => $userId,
            'login_identifier' => $identifier,
            'ip_address' => $ipAddress,
            'was_success' => $success ? 1 : 0,
            'failure_reason' => $success ? null : substr((string) $failureReason, 0, 80),
        ]);
    } catch (Throwable) {
        return;
    }
}

function auth_clear_login_failures(PDO $pdo, string $identifier, string $ipAddress): void
{
    if ($identifier === '' || ! app_tables_exist($pdo, ['login_attempts'])) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'DELETE FROM login_attempts
             WHERE login_identifier = :login_identifier
               AND ip_address = :ip_address
               AND was_success = 0'
        );
        $statement->execute([
            'login_identifier' => $identifier,
            'ip_address' => $ipAddress,
        ]);
    } catch (Throwable) {
        return;
    }
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
        'product_cost' => [
            'label' => 'Product Cost',
            'description' => 'View stock value, purchase cost, supplier balances, lot cost, backups, and profit details.',
            'pages' => [],
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
        'warranty_returns' => [
            'label' => 'Warranty / Returns',
            'description' => 'Handle customer returns, refunds, warranty exchanges, and supplier recovery.',
            'pages' => ['warranty-returns'],
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
            'pages' => ['settings', 'invoice-settings', 'invoice-preview'],
        ],
        'backup' => [
            'label' => 'Backup / Restore',
            'description' => 'Download full backups and restore verified backup files.',
            'pages' => ['backup'],
        ],
    ];
}

function auth_permission_keys(): array
{
    return array_keys(auth_permission_definitions());
}

function auth_staff_role_definitions(): array
{
    return [
        'cashier' => [
            'label' => 'Cashier',
            'description' => 'Sales, customers, payments, returns, and warranty intake. No product cost access.',
            'permissions' => ['dashboard', 'sales', 'customers', 'credit_sales', 'warranty_returns'],
        ],
        'stock_clerk' => [
            'label' => 'Stock Clerk',
            'description' => 'Products, inventory setup, stock movements, and warranty handling. No product cost access.',
            'permissions' => ['dashboard', 'products', 'inventory_setup', 'stock', 'warranty_returns'],
        ],
        'purchase_manager' => [
            'label' => 'Purchase Manager',
            'description' => 'Purchasing, product costs, supplier credit, inventory setup, and stock control.',
            'permissions' => ['dashboard', 'products', 'product_cost', 'inventory_setup', 'purchases', 'supplier_credit', 'stock'],
        ],
        'full_manager' => [
            'label' => 'Full Manager',
            'description' => 'All modules including reports, backups, settings, and product cost.',
            'permissions' => auth_permission_keys(),
        ],
    ];
}

function auth_staff_role_keys(): array
{
    return array_keys(auth_staff_role_definitions());
}

function auth_is_staff_role(string $role): bool
{
    return in_array($role, auth_staff_role_keys(), true) || $role === 'manager';
}

function auth_role_default_permissions(string $role): array
{
    $roles = auth_staff_role_definitions();

    if (isset($roles[$role])) {
        return $roles[$role]['permissions'];
    }

    if ($role === 'owner') {
        return auth_permission_keys();
    }

    if ($role === 'manager') {
        return array_values(array_filter(
            auth_permission_keys(),
            static fn (string $permission): bool => $permission !== 'product_cost'
        ));
    }

    return ['dashboard'];
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
        'customer_save.php', 'customer_archive.php' => 'customers',
        'payment_collect.php' => 'credit_sales',
        'warranty_return_save.php', 'warranty_return_update.php', 'warranty_return_lookup.php' => 'warranty_returns',
        'settings_save.php', 'invoice_settings_save.php' => 'settings',
        'backup_download.php', 'backup_restore.php' => 'backup',
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
        $defaultPermissions = auth_role_default_permissions((string) ($user['role'] ?? ''));

        if ($permission === 'warranty_returns') {
            return in_array('warranty_returns', $defaultPermissions, true)
                || in_array('returns', $defaultPermissions, true)
                || in_array('warranty', $defaultPermissions, true);
        }

        if (in_array($permission, ['purchases', 'supplier_credit', 'backup'], true)) {
            return in_array($permission, $defaultPermissions, true)
                && in_array('product_cost', $defaultPermissions, true);
        }

        return in_array($permission, $defaultPermissions, true);
    }

    if ($permission === 'product_cost') {
        return $permissions['product_cost'] ?? false;
    }

    if (in_array($permission, ['purchases', 'supplier_credit', 'backup'], true)) {
        return ($permissions[$permission] ?? false) && ($permissions['product_cost'] ?? false);
    }

    if ($permission === 'warranty_returns') {
        return $permissions['warranty_returns'] ?? $permissions['returns'] ?? $permissions['warranty'] ?? false;
    }

    return $permissions[$permission] ?? false;
}

function auth_can_view_product_cost(PDO $pdo, ?array $user): bool
{
    return auth_user_has_permission($pdo, $user, 'product_cost');
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
            $requiresProductCost = in_array((string) ($item['key'] ?? ''), ['purchases', 'supplier-credit'], true);

            if ($requiresProductCost && ! auth_can_view_product_cost($pdo, $user)) {
                continue;
            }

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

    app_log_security_event(
        'permission_denied',
        'Denied permission "' . substr($permission, 0, 60) . '" for ' . app_security_request_target() . '.',
        $user
    );
    set_flash('error', 'You do not have permission to access that module.');
    redirect('?page=dashboard');
}

function auth_require_owner(?array $currentUser): void
{
    if (($currentUser['role'] ?? '') === 'owner') {
        return;
    }

    app_log_security_event('owner_required_denied', 'Denied owner-only access for ' . app_security_request_target() . '.', $currentUser);
    set_flash('error', 'Only the owner can manage users.');
    redirect('?page=dashboard');
}

function auth_role_label(string $role): string
{
    $staffRoles = auth_staff_role_definitions();

    if (isset($staffRoles[$role])) {
        return $staffRoles[$role]['label'];
    }

    return match ($role) {
        'owner' => 'Owner',
        'manager' => 'Manager',
        default => ucfirst($role),
    };
}
