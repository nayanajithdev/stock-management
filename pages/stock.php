<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$products = [];
$movements = [];
$stockSearch = trim((string) ($_GET['q'] ?? ''));
$typeFilter = trim((string) ($_GET['movement_type'] ?? ''));
$movementLabels = stock_movement_labels();
$summary = [
    'stock_units' => 0,
    'stock_value' => 0.0,
    'low_stock' => 0,
    'manual_month' => 0,
];

if (! array_key_exists($typeFilter, $movementLabels)) {
    $typeFilter = '';
}

if ($dbReady && $pdo !== null) {
    $products = $pdo->query(
        'SELECT id, sku, name, model, current_stock, cost_price
         FROM products
         WHERE status = "active"
         ORDER BY name ASC'
    )->fetchAll();

    $summary['stock_units'] = (int) $pdo->query('SELECT COALESCE(SUM(current_stock), 0) FROM products WHERE status = "active"')->fetchColumn();
    $summary['stock_value'] = (float) $pdo->query('SELECT COALESCE(SUM(current_stock * cost_price), 0) FROM products WHERE status = "active"')->fetchColumn();
    $summary['low_stock'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active" AND reorder_level > 0 AND current_stock <= reorder_level')->fetchColumn();
    $summary['manual_month'] = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM stock_movements
         WHERE reference_type = "manual_adjustment"
           AND created_at >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    )->fetchColumn();

    $movementSql = 'SELECT sm.*,
                           p.sku,
                           p.name AS product_name,
                           p.model
                    FROM stock_movements sm
                    INNER JOIN products p ON p.id = sm.product_id';
    $where = [];
    $params = [];

    if ($stockSearch !== '') {
        $where[] = '(p.name LIKE :search OR p.sku LIKE :search OR p.model LIKE :search OR sm.notes LIKE :search)';
        $params['search'] = '%' . $stockSearch . '%';
    }

    if ($typeFilter !== '') {
        $where[] = 'sm.movement_type = :movement_type';
        $params['movement_type'] = $typeFilter;
    }

    if ($where !== []) {
        $movementSql .= ' WHERE ' . implode(' AND ', $where);
    }

    $movementSql .= ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT 100';
    $movementStatement = $pdo->prepare($movementSql);
    $movementStatement->execute($params);
    $movements = $movementStatement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <h1>Stock Movements</h1>
    </div>
</div>

<section class="stats-grid compact-stats" aria-label="Stock movement summary">
    <article class="stat-card">
        <div>
            <span>Stock Units</span>
            <strong><?php echo (int) $summary['stock_units']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="boxes"></i></div>
        <small>Total quantity on hand</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Low Stock</span>
            <strong><?php echo (int) $summary['low_stock']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="triangle-alert"></i></div>
        <small>Needs reorder</small>
    </article>
</section>

<section class="stock-layout">
    <article class="panel form-panel" id="stock-adjustment-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Manual Adjustment</p>
                <h2>Correct stock</h2>
            </div>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before adjusting stock.</p>
        <?php elseif ($products === []): ?>
            <p class="empty-state">Add products before making stock adjustments.</p>
        <?php else: ?>
            <form class="product-form single-form" method="post" action="<?php echo e(app_url('actions/stock_adjust.php')); ?>" data-stock-adjust-form>
                <?php echo csrf_field(); ?>

                <label class="field">
                    <span>Product</span>
                    <select name="product_id" data-stock-product required>
                        <option value="">Choose product</option>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $label = $product['sku'] . ' - ' . $product['name'];
                            if ((string) ($product['model'] ?? '') !== '') {
                                $label .= ' (' . $product['model'] . ')';
                            }
                            ?>
                            <option value="<?php echo (int) $product['id']; ?>" data-stock="<?php echo (int) $product['current_stock']; ?>" data-cost="<?php echo e($product['cost_price']); ?>">
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="stock-current">
                    <span>Current Stock</span>
                    <strong data-stock-current>Choose product</strong>
                </div>

                <label class="field">
                    <span>Adjustment Type</span>
                    <select name="adjustment_type" data-stock-adjust-type required>
                        <option value="increase">Manual increase</option>
                        <option value="decrease">Manual decrease</option>
                        <option value="damage">Damage or loss</option>
                        <option value="count">Set exact count</option>
                    </select>
                </label>

                <label class="field" data-stock-quantity-field>
                    <span>Quantity</span>
                    <input type="number" name="quantity" value="1" min="1" step="1" data-stock-quantity>
                </label>

                <label class="field hidden-field" data-stock-exact-field>
                    <span>Exact Stock Count</span>
                    <input type="number" name="exact_stock" value="0" min="0" step="1" data-stock-exact>
                </label>

                <label class="field">
                    <span>Notes</span>
                    <textarea name="notes" rows="4" placeholder="Example: Physical count correction, damaged item, missing stock found" required></textarea>
                </label>

                <div class="adjustment-preview">
                    <i data-lucide="activity"></i>
                    <span data-stock-preview>Select a product to preview the stock change.</span>
                </div>

                <div class="form-actions">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Adjustment
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Movement Ledger</p>
                <h2>Stock audit trail</h2>
                <?php if ($stockSearch !== '' || $typeFilter !== ''): ?>
                    <p class="search-note panel-search-note">
                        Showing stock movements<?php echo $stockSearch !== '' ? ' matching ' : ''; ?>
                        <?php if ($stockSearch !== ''): ?><strong><?php echo e($stockSearch); ?></strong><?php endif; ?>
                        <?php if ($typeFilter !== ''): ?> filtered by <strong><?php echo e($movementLabels[$typeFilter]); ?></strong><?php endif; ?>.
                        <a class="muted-link" href="<?php echo e(app_url('?page=stock')); ?>">Clear</a>
                    </p>
                <?php endif; ?>
            </div>

            <form class="filter-row movement-filter" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="stock">
                <input type="search" name="q" value="<?php echo e($stockSearch); ?>" placeholder="Search product, SKU, notes">
                <select name="movement_type">
                    <option value="">All Types</option>
                    <?php foreach ($movementLabels as $type => $label): ?>
                        <option value="<?php echo e($type); ?>" <?php echo $typeFilter === $type ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="icon-button" type="submit" aria-label="Apply filters">
                    <i data-lucide="search"></i>
                </button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th>Change</th>
                        <th>Stock After</th>
                        <th>Unit Cost</th>
                        <th>Reference</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($movements === []): ?>
                        <tr>
                            <td colspan="8">No stock movements found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($movements as $movement): ?>
                        <?php
                        $quantityChange = (int) $movement['quantity_change'];
                        $reference = trim((string) ($movement['reference_type'] ?? ''));
                        if ($reference !== '' && $movement['reference_id'] !== null) {
                            $reference .= ' #' . (int) $movement['reference_id'];
                        }
                        ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $movement['created_at']))); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($movement['product_name']); ?></strong>
                                <span class="table-subtitle"><?php echo e($movement['sku'] . (($movement['model'] ?? '') !== '' ? ' / ' . $movement['model'] : '')); ?></span>
                            </td>
                            <td><span class="status <?php echo e(stock_movement_status_class((string) $movement['movement_type'])); ?>"><?php echo e($movementLabels[$movement['movement_type']] ?? ucfirst((string) $movement['movement_type'])); ?></span></td>
                            <td class="<?php echo $quantityChange < 0 ? 'text-danger' : 'text-good'; ?>"><?php echo e(($quantityChange > 0 ? '+' : '') . $quantityChange); ?></td>
                            <td><?php echo (int) $movement['stock_after']; ?></td>
                            <td><?php echo e(format_money($movement['unit_cost'])); ?></td>
                            <td><?php echo e($reference !== '' ? $reference : 'Manual'); ?></td>
                            <td><?php echo e($movement['notes'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php
function stock_movement_labels(): array
{
    return [
        'opening' => 'Opening Stock',
        'purchase' => 'Purchase',
        'sale' => 'Sale',
        'return_in' => 'Sales Return',
        'return_out' => 'Purchase Return',
        'adjustment_in' => 'Manual Increase',
        'adjustment_out' => 'Manual Decrease',
        'damage' => 'Damage / Loss',
        'stock_count' => 'Stock Count',
    ];
}

function stock_movement_status_class(string $type): string
{
    return match ($type) {
        'purchase', 'opening', 'return_in', 'adjustment_in' => 'status-active',
        'damage', 'sale', 'return_out', 'adjustment_out' => 'status-pending',
        'stock_count' => 'status-warranty',
        default => 'status-inactive',
    };
}
