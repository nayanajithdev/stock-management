<header class="topbar">
    <button class="icon-button menu-toggle" type="button" aria-label="Open menu" data-sidebar-toggle>
        <i data-lucide="menu"></i>
    </button>

    <div class="breadcrumb">
        <span>Dashboard</span>
        <i data-lucide="chevron-right"></i>
        <strong><?php echo e($pageTitle); ?></strong>
    </div>

    <form class="search-box" role="search" method="get" action="<?php echo e(app_url('')); ?>">
        <i data-lucide="search"></i>
        <input type="hidden" name="page" value="<?php echo e($currentPage); ?>">
        <?php
        $searchName = $currentPage === 'products' ? 'product_search' : 'q';
        $placeholder = match ($currentPage) {
            'inventory-setup' => 'Search setup records from each table...',
            'purchases' => 'Search invoice or supplier...',
            'supplier-credit' => 'Search purchase invoice or supplier...',
            'expenses' => 'Search expense, category, vendor...',
            'stock' => 'Search stock movement, SKU, notes...',
            'sales' => 'Search invoice, customer, phone...',
            'sale-view' => 'Search invoices...',
            'warranty' => 'Search claim, invoice, customer, product...',
            'customers' => 'Search customer, phone, email...',
            'credit-sales' => 'Search credit invoice or customer...',
            'returns' => 'Search return, invoice, customer...',
            'reports' => 'Search report tables...',
            'users' => 'Search users, email, role...',
            'settings' => 'Search settings...',
            default => 'Search products, invoice, serial...',
        };
        ?>
        <input
            type="search"
            name="<?php echo e($searchName); ?>"
            value="<?php echo e((string) ($_GET[$searchName] ?? '')); ?>"
            placeholder="<?php echo e($placeholder); ?>"
            aria-label="Search"
        >
    </form>

    <?php
    $actionHref = match ($currentPage) {
        'products' => '?page=products&form=product#product-form',
        'inventory-setup' => '?page=products&form=product#product-form',
        'purchases' => '?page=purchases#purchase-form',
        'supplier-credit' => '?page=supplier-credit#supplier-payment-form',
        'expenses' => '?page=expenses#expense-form',
        'stock' => '?page=stock#stock-adjustment-form',
        'sales' => '?page=sales#sales-pos-form',
        'sale-view' => '?page=sales',
        'warranty' => '?page=warranty#warranty-claim-form',
        'customers' => '?page=customers&form=customer#customer-form',
        'credit-sales' => '?page=credit-sales#payment-collection-form',
        'returns' => '?page=returns#sales-return-form',
        'reports' => '?page=reports#report-filters',
        'users' => '?page=users&modal=user#user-form',
        'settings' => '?page=settings#shop-settings-form',
        default => '?page=inventory-setup',
    };
    $actionLabel = match ($currentPage) {
        'products' => 'Add Product',
        'inventory-setup' => 'New Product',
        'purchases' => 'Receive Stock',
        'supplier-credit' => 'Pay Supplier',
        'expenses' => 'New Expense',
        'stock' => 'Adjust Stock',
        'sales' => 'New Invoice',
        'sale-view' => 'Sales',
        'warranty' => 'New Claim',
        'customers' => 'Add Customer',
        'credit-sales' => 'Collect Payment',
        'returns' => 'New Return',
        'reports' => 'Filters',
        'users' => 'New Manager',
        'settings' => 'Settings',
        default => 'Setup',
    };
    $actionIcon = match ($currentPage) {
        'reports' => 'sliders-horizontal',
        'settings' => 'settings',
        'sale-view' => 'arrow-left',
        default => 'plus',
    };
    if ($currentPage === 'users' && ($currentUser['role'] ?? '') !== 'owner') {
        $actionHref = '?page=users';
        $actionLabel = 'Users';
        $actionIcon = 'users';
    }
    ?>
    <a class="top-action" href="<?php echo e(app_url($actionHref)); ?>">
        <i data-lucide="<?php echo e($actionIcon); ?>"></i>
        <?php echo e($actionLabel); ?>
    </a>
</header>
