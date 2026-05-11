<?php
/** @var array $currentUser */
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$stats = [
    ['label' => 'Stock Value', 'value' => format_money(0), 'meta' => 'Import schema to calculate', 'icon' => 'boxes'],
    ['label' => 'Active Products', 'value' => '0', 'meta' => 'Products ready', 'icon' => 'package-search'],
    ['label' => 'Low Stock', 'value' => '0', 'meta' => 'Needs reorder', 'icon' => 'triangle-alert'],
    ['label' => 'Categories', 'value' => '0', 'meta' => 'Accessory groups', 'icon' => 'tags'],
];
$lowStockItems = [];
$recentProducts = [];

if ($dbReady && $pdo !== null) {
    $stockValue = (float) $pdo->query('SELECT COALESCE(SUM(current_stock * cost_price), 0) FROM products WHERE status = "active"')->fetchColumn();
    $activeProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active"')->fetchColumn();
    $lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active" AND current_stock <= reorder_level AND reorder_level > 0')->fetchColumn();
    $categoryCount = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE is_active = 1')->fetchColumn();

    $stats = [
        ['label' => 'Stock Value', 'value' => format_money($stockValue), 'meta' => 'Cost value on hand', 'icon' => 'boxes'],
        ['label' => 'Active Products', 'value' => (string) $activeProducts, 'meta' => 'Ready for sales', 'icon' => 'package-search'],
        ['label' => 'Low Stock', 'value' => (string) $lowStockCount, 'meta' => 'Needs reorder', 'icon' => 'triangle-alert'],
        ['label' => 'Categories', 'value' => (string) $categoryCount, 'meta' => 'Accessory groups', 'icon' => 'tags'],
    ];

    $lowStockStatement = $pdo->query(
        'SELECT name, current_stock, reorder_level
         FROM products
         WHERE status = "active"
           AND reorder_level > 0
           AND current_stock <= reorder_level
         ORDER BY current_stock ASC, name ASC
         LIMIT 5'
    );
    $lowStockItems = $lowStockStatement->fetchAll();

    $recentProductStatement = $pdo->query(
        'SELECT p.sku, p.name, p.current_stock, p.selling_price, c.name AS category_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.status = "active"
         ORDER BY p.created_at DESC
         LIMIT 5'
    );
    $recentProducts = $recentProductStatement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Stock management system</p>
        <h1>Welcome back, <?php echo e($currentUser['name']); ?></h1>
    </div>
    <select class="period-select" aria-label="Report period">
        <option>Today</option>
        <option selected>This Month</option>
        <option>This Year</option>
    </select>
</div>

<section class="stats-grid" aria-label="Overview statistics">
    <?php foreach ($stats as $stat): ?>
        <article class="stat-card">
            <div>
                <span><?php echo e($stat['label']); ?></span>
                <strong><?php echo e($stat['value']); ?></strong>
            </div>
            <div class="stat-icon">
                <i data-lucide="<?php echo e($stat['icon']); ?>"></i>
            </div>
            <small><?php echo e($stat['meta']); ?></small>
        </article>
    <?php endforeach; ?>
</section>

<section class="dashboard-grid">
    <article class="panel sales-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Sales Trend</p>
                <h2>Monthly overview</h2>
            </div>
            <div class="segmented">
                <button type="button">Weekly</button>
                <button type="button" class="active">Monthly</button>
                <button type="button">Yearly</button>
            </div>
        </div>

        <div class="chart" aria-label="Monthly sales chart">
            <?php
            $bars = [18, 28, 42, 58, 46, 35, 22, 52, 33, 39, 51, 29, 24, 47, 64, 41, 28, 21, 35, 49, 38, 24, 69, 45, 31, 20, 37, 53, 76, 44, 32, 27, 59, 43, 25, 34, 56, 71, 48, 36, 23, 30, 40, 61, 36, 26, 44, 57];
            foreach ($bars as $height):
            ?>
                <span style="--height: <?php echo (int) $height; ?>%"></span>
            <?php endforeach; ?>
        </div>

        <div class="month-row">
            <span>Jan</span><span>Feb</span><span>Mar</span><span>Apr</span><span>May</span><strong>Jun</strong><span>Jul</span><span>Aug</span><span>Sep</span><span>Oct</span><span>Nov</span><span>Dec</span>
        </div>
    </article>

    <aside class="panel alert-panel">
        <div class="panel-header compact">
            <div>
                <p class="panel-label">Low Stock</p>
                <h2>Reorder Soon</h2>
            </div>
            <a class="icon-button" href="<?php echo e(app_url('?page=products')); ?>" aria-label="Open products">
                <i data-lucide="arrow-up-right"></i>
            </a>
        </div>

        <div class="stock-list">
            <?php if ($lowStockItems === []): ?>
                <p class="empty-state">No low-stock items yet.</p>
            <?php endif; ?>

            <?php foreach ($lowStockItems as $item): ?>
                <div class="stock-item">
                    <div>
                        <strong><?php echo e($item['name']); ?></strong>
                        <span><?php echo (int) $item['current_stock']; ?> left / reorder <?php echo (int) $item['reorder_level']; ?></span>
                    </div>
                    <meter min="0" max="<?php echo max(1, (int) $item['reorder_level']); ?>" value="<?php echo max(0, (int) $item['current_stock']); ?>"></meter>
                </div>
            <?php endforeach; ?>
        </div>
    </aside>
</section>

<section class="panel table-panel">
    <div class="panel-header">
        <div>
            <p class="panel-label">Recent Products</p>
            <h2>Latest stock items</h2>
        </div>
        <a class="top-action inline-action" href="<?php echo e(app_url('?page=products')); ?>">
            <i data-lucide="package-plus"></i>
            Add Product
        </a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th>Selling Price</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentProducts === []): ?>
                    <tr>
                        <td colspan="5">No products yet. Add your first product from the Products page.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($recentProducts as $product): ?>
                    <tr>
                        <td><?php echo e($product['sku']); ?></td>
                        <td><?php echo e($product['name']); ?></td>
                        <td><?php echo e($product['category_name'] ?? 'Uncategorized'); ?></td>
                        <td><?php echo (int) $product['current_stock']; ?></td>
                        <td><?php echo e(format_money($product['selling_price'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
