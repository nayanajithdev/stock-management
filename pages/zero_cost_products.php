<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$products = [];

if ($dbReady && $pdo instanceof PDO) {
    $latestCostSql = 'COALESCE((
        SELECT ' . app_lot_unit_cost_sql('sm_latest', 'pc_latest', 'lco_latest') . '
        FROM stock_movements sm_latest
        LEFT JOIN purchases pu_latest ON sm_latest.reference_type = "purchase" AND pu_latest.id = sm_latest.reference_id
        ' . app_purchase_cost_join_sql('sm_latest', 'pc_latest') . '
        ' . app_lot_cost_override_join_sql('sm_latest', 'lco_latest') . '
        WHERE sm_latest.product_id = p.id
          AND sm_latest.quantity_change > 0
          AND sm_latest.movement_type IN ("opening", "purchase")
        ORDER BY COALESCE(pu_latest.purchase_date, DATE(sm_latest.created_at)) DESC, sm_latest.id DESC
        LIMIT 1
    ), p.cost_price)';

    $statement = $pdo->query(
        'SELECT p.id,
                p.sku,
                p.name,
                p.model,
                p.current_stock,
                p.status,
                c.name AS category_name,
                b.name AS brand_name,
                ' . $latestCostSql . ' AS latest_cost_price
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN brands b ON b.id = p.brand_id
         WHERE ' . $latestCostSql . ' <= 0
         ORDER BY p.status ASC, p.name ASC'
    );
    $products = $statement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <h1>Zero cost products</h1>
        <p><?php echo count($products); ?> product(s)</p>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=products')); ?>">Products</a>
</div>

<section class="panel">
    <?php if (! $dbReady): ?>
        <p class="empty-state">Database is not ready.</p>
    <?php elseif ($products === []): ?>
        <p class="empty-state">No products with zero cost found.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Brand</th>
                        <th>Category</th>
                        <th>Stock</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Links</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td><?php echo e($product['sku']); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($product['name']); ?></strong>
                                <span class="table-subtitle"><?php echo e($product['model'] ?? ''); ?></span>
                            </td>
                            <td><?php echo e($product['brand_name'] ?? 'No brand'); ?></td>
                            <td><?php echo e($product['category_name'] ?? 'Uncategorized'); ?></td>
                            <td><?php echo (int) $product['current_stock']; ?></td>
                            <td><?php echo e(format_money($product['latest_cost_price'])); ?></td>
                            <td><?php echo e(ucfirst((string) $product['status'])); ?></td>
                            <td>
                                <a href="<?php echo e(app_url('?page=product-history&id=' . (int) $product['id'])); ?>">History</a>
                                /
                                <a href="<?php echo e(app_url('?page=products&edit=' . (int) $product['id'] . '#product-form')); ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
