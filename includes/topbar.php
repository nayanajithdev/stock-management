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
            'stock' => 'Search stock movement, SKU, notes...',
            'sales' => 'Search invoice, customer, phone...',
            'customers' => 'Search customer, phone, email...',
            'credit-sales' => 'Search credit invoice or customer...',
            'returns' => 'Search return, invoice, customer...',
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
        'inventory-setup' => '?page=products',
        'purchases' => '?page=purchases#purchase-form',
        'stock' => '?page=stock#stock-adjustment-form',
        'sales' => '?page=sales#sales-pos-form',
        'customers' => '?page=customers#customer-form',
        'credit-sales' => '?page=credit-sales#payment-collection-form',
        'returns' => '?page=returns#sales-return-form',
        default => '?page=inventory-setup',
    };
    $actionLabel = match ($currentPage) {
        'inventory-setup' => 'New Product',
        'purchases' => 'Receive Stock',
        'stock' => 'Adjust Stock',
        'sales' => 'New Invoice',
        'customers' => 'Add Customer',
        'credit-sales' => 'Collect Payment',
        'returns' => 'New Return',
        default => 'Setup',
    };
    ?>
    <a class="top-action" href="<?php echo e(app_url($actionHref)); ?>">
        <i data-lucide="plus"></i>
        <?php echo e($actionLabel); ?>
    </a>
</header>
