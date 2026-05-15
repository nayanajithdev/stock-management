<?php
/** @var array $currentUser */
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$stats = [
    ['label' => 'Month Revenue', 'value' => format_money(0), 'meta' => 'No sales this month', 'icon' => 'badge-dollar-sign'],
    ['label' => 'Gross Profit', 'value' => format_money(0), 'meta' => '0.00% margin', 'icon' => 'trending-up'],
    ['label' => 'Receivable', 'value' => format_money(0), 'meta' => 'Open customer balance', 'icon' => 'receipt-text'],
    ['label' => 'Stock Value', 'value' => format_money(0), 'meta' => 'Cost value on hand', 'icon' => 'boxes'],
];
$monthlyTrend = [];
$lowStockItems = [];
$recentInvoices = [];
$topProducts = [];
$creditRows = [];
$returnRows = [];
$warrantyRows = [];
$summary = [
    'month_revenue' => 0.0,
    'month_profit' => 0.0,
    'month_orders' => 0,
    'units_sold' => 0,
    'receivable' => 0.0,
    'stock_value' => 0.0,
    'low_stock' => 0,
    'open_warranty' => 0,
    'month_refunds' => 0.0,
];

if ($dbReady && $pdo !== null) {
    $summaryStatement = $pdo->query(
        'SELECT COUNT(*) AS month_orders,
                COALESCE(SUM(total), 0) AS month_revenue
         FROM sales
         WHERE sale_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    );
    $summaryRow = $summaryStatement->fetch() ?: [];
    $profitStatement = $pdo->query(
        'SELECT COALESCE(SUM(si.quantity), 0) AS units_sold,
                COALESCE(SUM(si.total - (si.quantity * si.unit_cost)), 0) AS month_profit
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         WHERE s.sale_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    );
    $profitRow = $profitStatement->fetch() ?: [];
    $summary['month_revenue'] = (float) ($summaryRow['month_revenue'] ?? 0);
    $summary['month_profit'] = (float) ($profitRow['month_profit'] ?? 0);
    $summary['month_orders'] = (int) ($summaryRow['month_orders'] ?? 0);
    $summary['units_sold'] = (int) ($profitRow['units_sold'] ?? 0);
    $summary['receivable'] = (float) $pdo->query('SELECT COALESCE(SUM(total - paid), 0) FROM sales WHERE total > paid')->fetchColumn();
    $summary['stock_value'] = (float) $pdo->query('SELECT COALESCE(SUM(current_stock * cost_price), 0) FROM products WHERE status = "active"')->fetchColumn();
    $summary['low_stock'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active" AND reorder_level > 0 AND current_stock <= reorder_level')->fetchColumn();
    $summary['open_warranty'] = (int) $pdo->query('SELECT COUNT(*) FROM warranty_claims WHERE status IN ("received", "sent_to_supplier", "ready_for_pickup")')->fetchColumn();
    $summary['month_refunds'] = (float) $pdo->query('SELECT COALESCE(SUM(refund_amount), 0) FROM sales_returns WHERE return_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")')->fetchColumn();

    $stats = [
        [
            'label' => 'Month Revenue',
            'value' => format_money($summary['month_revenue']),
            'meta' => $summary['month_orders'] . ' invoice(s), ' . $summary['units_sold'] . ' unit(s)',
            'icon' => 'badge-dollar-sign',
        ],
        [
            'label' => 'Gross Profit',
            'value' => format_money($summary['month_profit']),
            'meta' => dashboard_margin_label($summary['month_profit'], $summary['month_revenue']),
            'icon' => 'trending-up',
        ],
        [
            'label' => 'Receivable',
            'value' => format_money($summary['receivable']),
            'meta' => 'Open customer balance',
            'icon' => 'receipt-text',
        ],
        [
            'label' => 'Stock Value',
            'value' => format_money($summary['stock_value']),
            'meta' => $summary['low_stock'] . ' low-stock item(s)',
            'icon' => 'boxes',
        ],
    ];

    $trendStatement = $pdo->query(
        'SELECT MONTH(sale_date) AS sale_month,
                COALESCE(SUM(total), 0) AS revenue
         FROM sales
         WHERE YEAR(sale_date) = YEAR(CURRENT_DATE)
         GROUP BY MONTH(sale_date)
         ORDER BY sale_month ASC'
    );
    $trendRows = [];

    foreach ($trendStatement->fetchAll() as $row) {
        $trendRows[(int) $row['sale_month']] = [
            'revenue' => (float) $row['revenue'],
            'profit' => 0.0,
        ];
    }

    $trendProfitStatement = $pdo->query(
        'SELECT MONTH(s.sale_date) AS sale_month,
                COALESCE(SUM(si.total - (si.quantity * si.unit_cost)), 0) AS profit
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         WHERE YEAR(s.sale_date) = YEAR(CURRENT_DATE)
         GROUP BY MONTH(s.sale_date)
         ORDER BY sale_month ASC'
    );

    foreach ($trendProfitStatement->fetchAll() as $row) {
        $month = (int) $row['sale_month'];

        if (! isset($trendRows[$month])) {
            $trendRows[$month] = ['revenue' => 0.0, 'profit' => 0.0];
        }

        $trendRows[$month]['profit'] = (float) $row['profit'];
    }

    for ($month = 1; $month <= 12; $month++) {
        $monthlyTrend[] = [
            'month' => $month,
            'label' => date('M', mktime(0, 0, 0, $month, 1)),
            'revenue' => $trendRows[$month]['revenue'] ?? 0.0,
            'profit' => $trendRows[$month]['profit'] ?? 0.0,
        ];
    }

    $lowStockItems = $pdo->query(
        'SELECT sku, name, current_stock, reorder_level
         FROM products
         WHERE status = "active"
           AND reorder_level > 0
           AND current_stock <= reorder_level
         ORDER BY current_stock ASC, name ASC
         LIMIT 6'
    )->fetchAll();

    $recentInvoices = $pdo->query(
        'SELECT s.id,
                s.invoice_no,
                s.sale_date,
                s.total,
                s.paid,
                s.status,
                c.name AS customer_name,
                c.phone AS customer_phone
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         ORDER BY s.sale_date DESC, s.id DESC
         LIMIT 8'
    )->fetchAll();

    $topProducts = $pdo->query(
        'SELECT p.sku,
                p.name,
                p.current_stock,
                COALESCE(SUM(si.quantity), 0) AS units_sold,
                COALESCE(SUM(si.total), 0) AS revenue
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         INNER JOIN products p ON p.id = si.product_id
         WHERE s.sale_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")
         GROUP BY p.id
         ORDER BY revenue DESC, units_sold DESC
         LIMIT 6'
    )->fetchAll();

    $creditRows = $pdo->query(
        'SELECT s.id,
                s.invoice_no,
                s.total,
                s.paid,
                s.sale_date,
                c.name AS customer_name,
                c.phone AS customer_phone
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE s.total > s.paid
         ORDER BY (s.total - s.paid) DESC, s.sale_date ASC
         LIMIT 6'
    )->fetchAll();

    $returnRows = $pdo->query(
        'SELECT sr.return_no,
                sr.return_date,
                sr.refund_amount,
                s.invoice_no,
                c.name AS customer_name
         FROM sales_returns sr
         INNER JOIN sales s ON s.id = sr.sale_id
         LEFT JOIN customers c ON c.id = sr.customer_id
         ORDER BY sr.return_date DESC, sr.id DESC
         LIMIT 6'
    )->fetchAll();

    $warrantyRows = $pdo->query(
        'SELECT wc.claim_no,
                wc.status,
                wc.received_date,
                p.sku,
                p.name AS product_name,
                c.name AS customer_name
         FROM warranty_claims wc
         INNER JOIN products p ON p.id = wc.product_id
         LEFT JOIN customers c ON c.id = wc.customer_id
         WHERE wc.status IN ("received", "sent_to_supplier", "ready_for_pickup")
         ORDER BY wc.received_date ASC, wc.id ASC
         LIMIT 6'
    )->fetchAll();
}

$maxRevenue = 0.0;

foreach ($monthlyTrend as $month) {
    $maxRevenue = max($maxRevenue, (float) $month['revenue']);
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Stock management system</p>
        <h1>Welcome back, <?php echo e($currentUser['name']); ?></h1>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=reports')); ?>">
        <i data-lucide="chart-no-axes-combined"></i>
        Reports
    </a>
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
                <h2><?php echo date('Y'); ?> revenue</h2>
            </div>
            <span class="dashboard-pill"><?php echo e(format_money($summary['month_refunds'])); ?> refunds this month</span>
        </div>

        <div class="dashboard-bars" aria-label="Monthly sales chart">
            <?php foreach ($monthlyTrend as $month): ?>
                <?php
                $height = $maxRevenue > 0 ? max(8, ((float) $month['revenue'] / $maxRevenue) * 100) : 0;
                $isCurrentMonth = (int) date('n') === (int) $month['month'];
                ?>
                <div class="dashboard-bar-item <?php echo $isCurrentMonth ? 'active' : ''; ?>">
                    <div class="dashboard-bar-track">
                        <span style="height: <?php echo e(number_format($height, 2, '.', '')); ?>%"></span>
                    </div>
                    <strong><?php echo e($month['label']); ?></strong>
                    <small><?php echo e(format_money($month['revenue'])); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </article>

    <aside class="panel alert-panel">
        <div class="panel-header compact">
            <div>
                <p class="panel-label">Action Alerts</p>
                <h2>Needs attention</h2>
            </div>
        </div>

        <div class="dashboard-alerts">
            <a href="<?php echo e(app_url('?page=products')); ?>">
                <i data-lucide="triangle-alert"></i>
                <span>Low stock</span>
                <strong><?php echo (int) $summary['low_stock']; ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=credit-sales')); ?>">
                <i data-lucide="receipt-text"></i>
                <span>Receivable</span>
                <strong><?php echo e(format_money($summary['receivable'])); ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=warranty')); ?>">
                <i data-lucide="shield-check"></i>
                <span>Open warranty</span>
                <strong><?php echo (int) $summary['open_warranty']; ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=returns')); ?>">
                <i data-lucide="rotate-ccw"></i>
                <span>Refunds this month</span>
                <strong><?php echo e(format_money($summary['month_refunds'])); ?></strong>
            </a>
        </div>
    </aside>
</section>

<section class="dashboard-two-grid">
    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Recent Sales</p>
                <h2>Latest invoices</h2>
            </div>
            <a class="muted-link" href="<?php echo e(app_url('?page=sales')); ?>">Sales</a>
        </div>

        <div class="table-wrap compact-table">
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Balance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recentInvoices === []): ?>
                        <tr><td colspan="5">No sales recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentInvoices as $sale): ?>
                        <?php $balance = (float) $sale['total'] - (float) $sale['paid']; ?>
                        <tr>
                            <td>
                                <a class="table-title" href="<?php echo e(app_url('?page=sale-view&id=' . (int) $sale['id'])); ?>"><?php echo e($sale['invoice_no']); ?></a>
                                <span class="table-subtitle"><?php echo e(date('Y-m-d H:i', strtotime((string) $sale['sale_date']))); ?></span>
                            </td>
                            <td><?php echo e($sale['customer_name'] ?: 'Walk-in Customer'); ?></td>
                            <td><?php echo e(format_money($sale['total'])); ?></td>
                            <td class="<?php echo $balance > 0 ? 'text-danger' : 'text-good'; ?>"><?php echo e(format_money($balance)); ?></td>
                            <td><span class="status <?php echo e(dashboard_sale_status_class((string) $sale['status'], $balance)); ?>"><?php echo e($balance > 0 ? ucfirst((string) $sale['status']) : 'Closed'); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Top Products</p>
                <h2>This month</h2>
            </div>
            <a class="muted-link" href="<?php echo e(app_url('?page=reports')); ?>">Reports</a>
        </div>

        <div class="table-wrap compact-table">
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Units</th>
                        <th>Revenue</th>
                        <th>Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($topProducts === []): ?>
                        <tr><td colspan="4">No product sales this month.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($topProducts as $product): ?>
                        <tr>
                            <td>
                                <strong class="table-title"><?php echo e($product['sku']); ?></strong>
                                <span class="table-subtitle"><?php echo e($product['name']); ?></span>
                            </td>
                            <td><?php echo (int) $product['units_sold']; ?></td>
                            <td><?php echo e(format_money($product['revenue'])); ?></td>
                            <td><?php echo (int) $product['current_stock']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="dashboard-four-grid">
    <article class="panel">
        <div class="panel-header compact">
            <div>
                <p class="panel-label">Low Stock</p>
                <h2>Reorder soon</h2>
            </div>
            <a class="icon-button" href="<?php echo e(app_url('?page=products')); ?>" aria-label="Open products">
                <i data-lucide="arrow-up-right"></i>
            </a>
        </div>
        <div class="stock-list">
            <?php if ($lowStockItems === []): ?>
                <p class="empty-state">No low-stock items.</p>
            <?php endif; ?>
            <?php foreach ($lowStockItems as $item): ?>
                <div class="stock-item">
                    <div>
                        <strong><?php echo e($item['sku'] . ' - ' . $item['name']); ?></strong>
                        <span><?php echo (int) $item['current_stock']; ?> left / reorder <?php echo (int) $item['reorder_level']; ?></span>
                    </div>
                    <meter min="0" max="<?php echo max(1, (int) $item['reorder_level']); ?>" value="<?php echo max(0, (int) $item['current_stock']); ?>"></meter>
                </div>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="panel">
        <div class="panel-header compact">
            <div>
                <p class="panel-label">Credit Due</p>
                <h2>Follow up</h2>
            </div>
            <a class="icon-button" href="<?php echo e(app_url('?page=credit-sales')); ?>" aria-label="Open credit sales">
                <i data-lucide="arrow-up-right"></i>
            </a>
        </div>
        <div class="dashboard-list">
            <?php if ($creditRows === []): ?>
                <p class="empty-state">No open balances.</p>
            <?php endif; ?>
            <?php foreach ($creditRows as $row): ?>
                <?php $balance = (float) $row['total'] - (float) $row['paid']; ?>
                <a href="<?php echo e(app_url('?page=sale-view&id=' . (int) $row['id'])); ?>">
                    <span><?php echo e($row['invoice_no'] . ' / ' . ($row['customer_name'] ?: 'Walk-in')); ?></span>
                    <strong><?php echo e(format_money($balance)); ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="panel">
        <div class="panel-header compact">
            <div>
                <p class="panel-label">Returns</p>
                <h2>Recent refunds</h2>
            </div>
            <a class="icon-button" href="<?php echo e(app_url('?page=returns')); ?>" aria-label="Open returns">
                <i data-lucide="arrow-up-right"></i>
            </a>
        </div>
        <div class="dashboard-list">
            <?php if ($returnRows === []): ?>
                <p class="empty-state">No returns recorded.</p>
            <?php endif; ?>
            <?php foreach ($returnRows as $row): ?>
                <a href="<?php echo e(app_url('?page=returns&q=' . rawurlencode((string) $row['return_no']))); ?>">
                    <span><?php echo e($row['return_no'] . ' / ' . ($row['customer_name'] ?: 'Walk-in')); ?></span>
                    <strong><?php echo e(format_money($row['refund_amount'])); ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="panel">
        <div class="panel-header compact">
            <div>
                <p class="panel-label">Warranty</p>
                <h2>Open claims</h2>
            </div>
            <a class="icon-button" href="<?php echo e(app_url('?page=warranty')); ?>" aria-label="Open warranty">
                <i data-lucide="arrow-up-right"></i>
            </a>
        </div>
        <div class="dashboard-list">
            <?php if ($warrantyRows === []): ?>
                <p class="empty-state">No open warranty claims.</p>
            <?php endif; ?>
            <?php foreach ($warrantyRows as $row): ?>
                <a href="<?php echo e(app_url('?page=warranty&q=' . rawurlencode((string) $row['claim_no']))); ?>">
                    <span><?php echo e($row['claim_no'] . ' / ' . $row['sku']); ?></span>
                    <strong><?php echo e(dashboard_warranty_status_label((string) $row['status'])); ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<?php
function dashboard_margin_label(float $profit, float $revenue): string
{
    if ($revenue <= 0) {
        return '0.00% margin';
    }

    return number_format(($profit / $revenue) * 100, 2) . '% margin';
}

function dashboard_sale_status_class(string $status, float $balance): string
{
    if ($balance <= 0) {
        return 'status-active';
    }

    return match ($status) {
        'partial' => 'status-warranty',
        'credit' => 'status-pending',
        default => 'status-inactive',
    };
}

function dashboard_warranty_status_label(string $status): string
{
    return match ($status) {
        'sent_to_supplier' => 'Supplier',
        'ready_for_pickup' => 'Ready',
        default => 'Received',
    };
}
