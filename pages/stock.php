<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var ?array $currentUser */

$products = [];
$movements = [];
$stockSearch = trim((string) ($_GET['q'] ?? ''));
$typeFilter = trim((string) ($_GET['movement_type'] ?? ''));
$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($pageNumber - 1) * $perPage;
$totalMovements = 0;
$totalPages = 1;
$movementLabels = stock_movement_labels();
$filterMovementLabels = stock_movement_filter_labels();
$canViewProductCost = $dbReady && $pdo instanceof PDO && auth_can_view_product_cost($pdo, $currentUser ?? null);
$movementTableColspan = $canViewProductCost ? 7 : 6;
$summary = [
    'stock_units' => 0,
    'stock_value' => 0.0,
    'low_stock' => 0,
];

if (! array_key_exists($typeFilter, $filterMovementLabels)) {
    $typeFilter = '';
}

if ($dbReady && $pdo !== null) {
    $products = $pdo->query(
        'SELECT id, sku, name, model, current_stock
         FROM products
         WHERE status = "active"
         ORDER BY name ASC'
    )->fetchAll();

    $summary['stock_units'] = (int) $pdo->query('SELECT COALESCE(SUM(current_stock), 0) FROM products WHERE status = "active" AND unlimited_stock = 0')->fetchColumn();
    if ($canViewProductCost) {
        $summary['stock_value'] = app_stock_value_total($pdo);
    }
    $summary['low_stock'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active" AND unlimited_stock = 0 AND reorder_level IS NOT NULL AND current_stock <= reorder_level')->fetchColumn();
    $costSelect = $canViewProductCost
        ? ', ' . app_lot_unit_cost_sql('sm', 'pc', 'lco') . ' AS display_unit_cost'
        : '';
    $costJoins = $canViewProductCost
        ? app_purchase_cost_join_sql('sm', 'pc') . ' ' . app_lot_cost_override_join_sql('sm', 'lco')
        : '';
    $movementSql = 'SELECT sm.*,
                           p.sku,
                           p.name AS product_name,
                           p.model,
                           sale_ref.invoice_no AS sale_invoice_no,
                           purchase_ref.invoice_no AS purchase_invoice_no,
                           u.full_name AS created_by_name' . $costSelect . '
                    FROM stock_movements sm
                    INNER JOIN products p ON p.id = sm.product_id
                    LEFT JOIN sales sale_ref ON sm.reference_type = "sale" AND sale_ref.id = sm.reference_id
                    LEFT JOIN purchases purchase_ref ON sm.reference_type = "purchase" AND purchase_ref.id = sm.reference_id
                    LEFT JOIN users u ON u.id = sm.created_by
                    ' . $costJoins;
    $where = [];
    $params = [];

    if ($stockSearch !== '') {
        $where[] = '(p.name LIKE :search OR p.sku LIKE :search OR p.model LIKE :search OR sm.notes LIKE :search OR sale_ref.invoice_no LIKE :search OR purchase_ref.invoice_no LIKE :search OR u.full_name LIKE :search)';
        $params['search'] = '%' . $stockSearch . '%';
    }

    if ($typeFilter !== '') {
        $where[] = 'sm.movement_type = :movement_type';
        $params['movement_type'] = $typeFilter;
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $countSql = 'SELECT COUNT(*)
                 FROM stock_movements sm
                 INNER JOIN products p ON p.id = sm.product_id
                 LEFT JOIN sales sale_ref ON sm.reference_type = "sale" AND sale_ref.id = sm.reference_id
                 LEFT JOIN purchases purchase_ref ON sm.reference_type = "purchase" AND purchase_ref.id = sm.reference_id
                 LEFT JOIN users u ON u.id = sm.created_by' . $whereSql;
    $countStatement = $pdo->prepare($countSql);
    $countStatement->execute($params);
    $totalMovements = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalMovements / $perPage));

    if ($pageNumber > $totalPages) {
        $pageNumber = $totalPages;
        $offset = ($pageNumber - 1) * $perPage;
    }

    $movementSql .= $whereSql . ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT :limit OFFSET :offset';
    $movementStatement = $pdo->prepare($movementSql);
    foreach ($params as $key => $value) {
        $movementStatement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $movementStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $movementStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $movementStatement->execute();
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

<section class="stock-layout stock-ledger-layout">
    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Movement Ledger</p>
                <h2>Stock audit trail</h2>
                <?php if ($stockSearch !== '' || $typeFilter !== ''): ?>
                    <p class="search-note panel-search-note">
                        <span>
                            Showing stock movements<?php echo $stockSearch !== '' ? ' matching ' : ''; ?>
                            <?php if ($stockSearch !== ''): ?><strong><?php echo e($stockSearch); ?></strong><?php endif; ?>
                            <?php if ($typeFilter !== ''): ?> filtered by <strong><?php echo e($filterMovementLabels[$typeFilter]); ?></strong><?php endif; ?>.
                        </span>
                        <a class="filter-clear-link" href="<?php echo e(app_url('?page=stock')); ?>">
                            <i data-lucide="x"></i>
                            Clear
                        </a>
                    </p>
                <?php endif; ?>
            </div>

            <form class="filter-row movement-filter" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="stock">
                <input type="search" name="q" value="<?php echo e($stockSearch); ?>" placeholder="Search product, SKU, invoice, user">
                <select name="movement_type">
                    <option value="">All Types</option>
                    <?php foreach ($filterMovementLabels as $type => $label): ?>
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
                        <th>Movement</th>
                        <th>Stock After</th>
                        <?php if ($canViewProductCost): ?>
                            <th>Unit Cost</th>
                        <?php endif; ?>
                        <th>User</th>
                        <th>Invoice</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($movements === []): ?>
                        <tr>
                            <td colspan="<?php echo $movementTableColspan; ?>">No stock movements found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($movements as $movement): ?>
                        <?php
                        $quantityChange = (int) $movement['quantity_change'];
                        $reference = stock_movement_reference($movement);
                        ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $movement['created_at']))); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($movement['product_name']); ?></strong>
                                <span class="table-subtitle"><?php echo e($movement['sku'] . (($movement['model'] ?? '') !== '' ? ' / ' . $movement['model'] : '')); ?></span>
                            </td>
                            <td>
                                <div class="stock-movement-cell">
                                    <span class="status <?php echo e(stock_movement_status_class((string) $movement['movement_type'])); ?>"><?php echo e($movementLabels[$movement['movement_type']] ?? ucfirst((string) $movement['movement_type'])); ?></span>
                                    <strong class="stock-movement-change <?php echo $quantityChange < 0 ? 'text-danger' : 'text-good'; ?>"><?php echo e(($quantityChange > 0 ? '+' : '') . $quantityChange); ?></strong>
                                </div>
                            </td>
                            <td><?php echo (int) $movement['stock_after']; ?></td>
                            <?php if ($canViewProductCost): ?>
                                <td><?php echo e(format_money($movement['display_unit_cost'])); ?></td>
                            <?php endif; ?>
                            <td><?php echo e($movement['created_by_name'] ?: '-'); ?></td>
                            <td>
                                <?php if ($reference['label'] !== '' && $reference['url'] !== ''): ?>
                                    <a class="table-title stock-invoice-link" href="<?php echo e($reference['url']); ?>" aria-label="<?php echo e($reference['aria_label']); ?>" title="<?php echo e($reference['aria_label']); ?>"><?php echo e($reference['label']); ?></a>
                                <?php else: ?>
                                    <span class="table-subtitle">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination-row product-pagination" aria-label="Stock movement pages">
                <?php if ($pageNumber <= 1): ?>
                    <span class="product-page-button disabled">Previous</span>
                <?php else: ?>
                    <a class="product-page-button" href="<?php echo e(app_url('?' . stock_page_query($pageNumber - 1))); ?>">Previous</a>
                <?php endif; ?>

                <?php foreach (stock_pagination_pages($pageNumber, $totalPages) as $paginationPage): ?>
                    <?php if ($paginationPage === 'ellipsis'): ?>
                        <span class="product-page-ellipsis">...</span>
                    <?php elseif ((int) $paginationPage === $pageNumber): ?>
                        <span class="product-page-button active" aria-current="page"><?php echo (int) $paginationPage; ?></span>
                    <?php else: ?>
                        <a class="product-page-button" href="<?php echo e(app_url('?' . stock_page_query((int) $paginationPage))); ?>"><?php echo (int) $paginationPage; ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($pageNumber >= $totalPages): ?>
                    <span class="product-page-button disabled">Next</span>
                <?php else: ?>
                    <a class="product-page-button" href="<?php echo e(app_url('?' . stock_page_query($pageNumber + 1))); ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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
        'warranty_supplier_in' => 'Supplier Replacement',
        'warranty_customer_out' => 'Customer Replacement',
        'adjustment_in' => 'Manual Increase',
        'adjustment_out' => 'Manual Decrease',
        'damage' => 'Damage / Loss',
        'stock_count' => 'Lot Correction',
    ];
}

function stock_movement_filter_labels(): array
{
    return [
        'opening' => 'Opening Stock',
        'purchase' => 'Purchase',
        'sale' => 'Sale',
        'return_in' => 'Sales Return',
        'return_out' => 'Purchase Return',
        'warranty_supplier_in' => 'Supplier Replacement',
        'warranty_customer_out' => 'Customer Replacement',
        'damage' => 'Damage / Loss',
        'stock_count' => 'Lot Correction',
    ];
}

function stock_movement_status_class(string $type): string
{
    return match ($type) {
        'purchase', 'opening', 'return_in', 'adjustment_in', 'warranty_supplier_in' => 'status-active',
        'damage', 'sale', 'return_out', 'adjustment_out', 'warranty_customer_out' => 'status-pending',
        'stock_count' => 'status-warranty',
        default => 'status-inactive',
    };
}

function stock_movement_reference(array $movement): array
{
    $referenceType = (string) ($movement['reference_type'] ?? '');
    $referenceId = (int) ($movement['reference_id'] ?? 0);

    if ($referenceType === 'sale' && $referenceId > 0) {
        $invoiceNo = trim((string) ($movement['sale_invoice_no'] ?? ''));

        if ($invoiceNo !== '') {
            return [
                'label' => $invoiceNo,
                'url' => app_url('?page=sale-view&id=' . $referenceId),
                'aria_label' => 'View invoice',
            ];
        }
    }

    if ($referenceType === 'purchase' && $referenceId > 0) {
        $invoiceNo = trim((string) ($movement['purchase_invoice_no'] ?? ''));

        if ($invoiceNo !== '') {
            return [
                'label' => $invoiceNo,
                'url' => app_url('?page=purchase-view&id=' . $referenceId),
                'aria_label' => 'View purchase invoice',
            ];
        }

        return [
            'label' => 'Purchase #' . $referenceId,
            'url' => app_url('?page=purchase-view&id=' . $referenceId),
            'aria_label' => 'View purchase',
        ];
    }

    return [
        'label' => '',
        'url' => '',
        'aria_label' => '',
    ];
}

function stock_page_query(int $pageNumber): string
{
    $query = $_GET;
    $query['page'] = 'stock';
    $query['p'] = max(1, $pageNumber);

    return http_build_query($query);
}

function stock_pagination_pages(int $pageNumber, int $totalPages): array
{
    if ($totalPages <= 7) {
        return range(1, $totalPages);
    }

    $pages = [1];
    $start = max(2, $pageNumber - 1);
    $end = min($totalPages - 1, $pageNumber + 1);

    if ($pageNumber <= 3) {
        $start = 2;
        $end = 4;
    } elseif ($pageNumber >= $totalPages - 2) {
        $start = $totalPages - 3;
        $end = $totalPages - 1;
    }

    if ($start > 2) {
        $pages[] = 'ellipsis';
    }

    for ($page = $start; $page <= $end; $page++) {
        $pages[] = $page;
    }

    if ($end < $totalPages - 1) {
        $pages[] = 'ellipsis';
    }

    $pages[] = $totalPages;

    return $pages;
}
