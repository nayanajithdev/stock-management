<?php
/** @var array $currentUser */
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$primaryStats = [
    ['label' => 'Today Sales', 'value' => format_money(0), 'meta' => '0 invoice(s)', 'icon' => 'badge-dollar-sign'],
    ['label' => 'Cash In Today', 'value' => format_money(0), 'meta' => 'Sales and collections', 'icon' => 'wallet'],
    ['label' => 'Customer Due', 'value' => format_money(0), 'meta' => 'Open receivables', 'icon' => 'receipt-text'],
    ['label' => 'Supplier Due', 'value' => format_money(0), 'meta' => 'Open payables', 'icon' => 'hand-coins'],
];
$financeStats = [
    ['label' => 'Month Revenue', 'value' => format_money(0), 'meta' => '0 invoice(s)', 'icon' => 'chart-no-axes-combined'],
    ['label' => 'Gross Profit', 'value' => format_money(0), 'meta' => '0.00% margin', 'icon' => 'trending-up'],
    ['label' => 'Month Expenses', 'value' => format_money(0), 'meta' => 'Active expenses', 'icon' => 'receipt'],
    ['label' => 'Est. Net Profit', 'value' => format_money(0), 'meta' => 'After expenses/refunds', 'icon' => 'banknote'],
];
$monthlyTrend = [];
$metrics = [
    'today_sales' => 0.0,
    'today_orders' => 0,
    'today_paid' => 0.0,
    'today_collections' => 0.0,
    'today_expenses' => 0.0,
    'today_supplier_paid' => 0.0,
    'month_revenue' => 0.0,
    'month_orders' => 0,
    'month_profit' => 0.0,
    'month_expenses' => 0.0,
    'month_refunds' => 0.0,
    'month_net_profit' => 0.0,
    'receivable' => 0.0,
    'payable' => 0.0,
    'stock_value' => 0.0,
    'low_stock' => 0,
    'open_warranty' => 0,
    'warranty_expiring' => 0,
];

if ($dbReady && $pdo !== null) {
    $todaySalesRow = dashboard_fetch_one($pdo,
        'SELECT COUNT(*) AS orders,
                COALESCE(SUM(total), 0) AS total,
                COALESCE(SUM(paid), 0) AS paid
         FROM sales
         WHERE DATE(sale_date) = CURRENT_DATE'
    );
    $monthSalesRow = dashboard_fetch_one($pdo,
        'SELECT COUNT(*) AS orders,
                COALESCE(SUM(total), 0) AS total
         FROM sales
         WHERE sale_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    );
    $monthProfitRow = dashboard_fetch_one($pdo,
        'SELECT COALESCE(SUM(si.total - (si.quantity * si.unit_cost)), 0) AS profit
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         WHERE s.sale_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    );

    $metrics['today_orders'] = (int) ($todaySalesRow['orders'] ?? 0);
    $metrics['today_sales'] = (float) ($todaySalesRow['total'] ?? 0);
    $metrics['today_paid'] = (float) ($todaySalesRow['paid'] ?? 0);
    $metrics['today_collections'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE DATE(payment_date) = CURRENT_DATE')->fetchColumn();
    $metrics['today_expenses'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = "active" AND expense_date = CURRENT_DATE')->fetchColumn();
    $metrics['today_supplier_paid'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE DATE(payment_date) = CURRENT_DATE')->fetchColumn();
    $metrics['month_orders'] = (int) ($monthSalesRow['orders'] ?? 0);
    $metrics['month_revenue'] = (float) ($monthSalesRow['total'] ?? 0);
    $metrics['month_profit'] = (float) ($monthProfitRow['profit'] ?? 0);
    $metrics['month_expenses'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = "active" AND expense_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")')->fetchColumn();
    $metrics['month_refunds'] = (float) $pdo->query('SELECT COALESCE(SUM(refund_amount), 0) FROM sales_returns WHERE return_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")')->fetchColumn();
    $metrics['month_net_profit'] = $metrics['month_profit'] - $metrics['month_expenses'] - $metrics['month_refunds'];
    $metrics['receivable'] = (float) $pdo->query('SELECT COALESCE(SUM(total - paid), 0) FROM sales WHERE total > paid')->fetchColumn();
    $metrics['payable'] = (float) $pdo->query('SELECT COALESCE(SUM(total - paid), 0) FROM purchases WHERE total > paid')->fetchColumn();
    $metrics['stock_value'] = (float) $pdo->query('SELECT COALESCE(SUM(current_stock * cost_price), 0) FROM products WHERE status = "active"')->fetchColumn();
    $metrics['low_stock'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active" AND reorder_level > 0 AND current_stock <= reorder_level')->fetchColumn();
    $metrics['open_warranty'] = (int) $pdo->query('SELECT COUNT(*) FROM warranty_claims WHERE status IN ("received", "sent_to_supplier", "ready_for_pickup")')->fetchColumn();
    $metrics['warranty_expiring'] = dashboard_warranty_expiring_lots($pdo);

    $primaryStats = [
        [
            'label' => 'Today Sales',
            'value' => format_money($metrics['today_sales']),
            'meta' => $metrics['today_orders'] . ' invoice(s)',
            'icon' => 'badge-dollar-sign',
        ],
        [
            'label' => 'Cash In Today',
            'value' => format_money($metrics['today_paid'] + $metrics['today_collections']),
            'meta' => 'Sales and credit collections',
            'icon' => 'wallet',
        ],
        [
            'label' => 'Customer Due',
            'value' => format_money($metrics['receivable']),
            'meta' => 'Open receivables',
            'icon' => 'receipt-text',
        ],
        [
            'label' => 'Supplier Due',
            'value' => format_money($metrics['payable']),
            'meta' => 'Open payables',
            'icon' => 'hand-coins',
        ],
    ];
    $financeStats = [
        [
            'label' => 'Month Revenue',
            'value' => format_money($metrics['month_revenue']),
            'meta' => $metrics['month_orders'] . ' invoice(s)',
            'icon' => 'chart-no-axes-combined',
        ],
        [
            'label' => 'Gross Profit',
            'value' => format_money($metrics['month_profit']),
            'meta' => dashboard_margin_label($metrics['month_profit'], $metrics['month_revenue']),
            'icon' => 'trending-up',
        ],
        [
            'label' => 'Month Expenses',
            'value' => format_money($metrics['month_expenses']),
            'meta' => format_money($metrics['month_refunds']) . ' refunds',
            'icon' => 'receipt',
        ],
        [
            'label' => 'Est. Net Profit',
            'value' => format_money($metrics['month_net_profit']),
            'meta' => 'After expenses/refunds',
            'icon' => 'banknote',
        ],
    ];

    $trendRows = [];
    $trendStatement = $pdo->query(
        'SELECT MONTH(sale_date) AS sale_month,
                COALESCE(SUM(total), 0) AS revenue
         FROM sales
         WHERE YEAR(sale_date) = YEAR(CURRENT_DATE)
         GROUP BY MONTH(sale_date)'
    );

    foreach ($trendStatement->fetchAll() as $row) {
        $trendRows[(int) $row['sale_month']] = (float) $row['revenue'];
    }

    for ($month = 1; $month <= 12; $month++) {
        $monthlyTrend[] = [
            'month' => $month,
            'label' => date('M', mktime(0, 0, 0, $month, 1)),
            'revenue' => $trendRows[$month] ?? 0.0,
        ];
    }

}

$maxRevenue = 0.0;

foreach ($monthlyTrend as $month) {
    $maxRevenue = max($maxRevenue, (float) $month['revenue']);
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Shop overview</p>
        <h1>Dashboard</h1>
    </div>
    <div class="heading-actions">
        <a class="top-action" href="<?php echo e(app_url('?page=reports')); ?>">
            <i data-lucide="chart-no-axes-combined"></i>
            Reports
        </a>
    </div>
</div>

<section class="stats-grid" aria-label="Daily operations">
    <?php foreach ($primaryStats as $stat): ?>
        <?php dashboard_stat_card($stat); ?>
    <?php endforeach; ?>
</section>

<section class="stats-grid compact-stats" aria-label="Monthly finance">
    <?php foreach ($financeStats as $stat): ?>
        <?php dashboard_stat_card($stat); ?>
    <?php endforeach; ?>
</section>

<section class="dashboard-grid">
    <article class="panel sales-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Sales Trend</p>
                <h2><?php echo date('Y'); ?> revenue</h2>
            </div>
            <span class="dashboard-pill"><?php echo e(format_money($metrics['month_net_profit'])); ?> est. net this month</span>
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
                <p class="panel-label">Action Center</p>
                <h2>Needs attention</h2>
            </div>
        </div>

        <div class="dashboard-alerts">
            <a href="<?php echo e(app_url('?page=products')); ?>">
                <i data-lucide="triangle-alert"></i>
                <span>Low stock</span>
                <strong><?php echo (int) $metrics['low_stock']; ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=credit-sales')); ?>">
                <i data-lucide="receipt-text"></i>
                <span>Customer due</span>
                <strong><?php echo e(format_money($metrics['receivable'])); ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=supplier-credit')); ?>">
                <i data-lucide="hand-coins"></i>
                <span>Supplier due</span>
                <strong><?php echo e(format_money($metrics['payable'])); ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=expenses')); ?>">
                <i data-lucide="receipt"></i>
                <span>Today paid out</span>
                <strong><?php echo e(format_money($metrics['today_expenses'] + $metrics['today_supplier_paid'])); ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=warranty')); ?>">
                <i data-lucide="shield-check"></i>
                <span>Open warranty</span>
                <strong><?php echo (int) $metrics['open_warranty']; ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=products')); ?>">
                <i data-lucide="shield-alert"></i>
                <span>Supplier warranty ending</span>
                <strong><?php echo (int) $metrics['warranty_expiring']; ?></strong>
            </a>
        </div>
    </aside>
</section>

<?php
function dashboard_fetch_one(PDO $pdo, string $sql): array
{
    $row = $pdo->query($sql)->fetch();

    return is_array($row) ? $row : [];
}

function dashboard_warranty_expiring_lots(PDO $pdo): int
{
    $stockOutRows = $pdo->query(
        'SELECT product_id, COALESCE(SUM(ABS(quantity_change)), 0) AS stock_out
         FROM stock_movements
         WHERE quantity_change < 0
         GROUP BY product_id'
    )->fetchAll();
    $stockOutByProduct = [];

    foreach ($stockOutRows as $row) {
        $stockOutByProduct[(int) $row['product_id']] = (int) $row['stock_out'];
    }

    $lotRows = $pdo->query(
        'SELECT sm.product_id,
                sm.quantity_change,
                sm.warranty_months,
                COALESCE(pu.purchase_date, DATE(sm.created_at)) AS warranty_start,
                DATE_ADD(COALESCE(pu.purchase_date, DATE(sm.created_at)), INTERVAL sm.warranty_months MONTH) AS warranty_ends_at
         FROM stock_movements sm
         INNER JOIN products p ON p.id = sm.product_id
         LEFT JOIN purchases pu ON sm.reference_type = "purchase" AND pu.id = sm.reference_id
         WHERE p.status = "active"
           AND p.current_stock > 0
           AND p.item_tracking = 1
           AND sm.warranty_months > 0
           AND sm.quantity_change > 0
           AND sm.movement_type IN ("opening", "purchase", "return_in", "adjustment_in")
         ORDER BY sm.product_id ASC, COALESCE(pu.purchase_date, DATE(sm.created_at)) ASC, sm.id ASC'
    )->fetchAll();

    $today = date('Y-m-d');
    $warningCutoff = date('Y-m-d', strtotime('+30 days'));
    $expiringLots = 0;

    foreach ($lotRows as $lot) {
        $productId = (int) $lot['product_id'];
        $lotQuantity = (int) $lot['quantity_change'];
        $remainingStockOut = $stockOutByProduct[$productId] ?? 0;
        $deducted = min($lotQuantity, $remainingStockOut);
        $stockOutByProduct[$productId] = max(0, $remainingStockOut - $deducted);

        if (($lotQuantity - $deducted) <= 0) {
            continue;
        }

        $warrantyEndsAt = (string) ($lot['warranty_ends_at'] ?? '');
        if ($warrantyEndsAt >= $today && $warrantyEndsAt <= $warningCutoff) {
            $expiringLots++;
        }
    }

    return $expiringLots;
}

function dashboard_stat_card(array $stat): void
{
    ?>
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
    <?php
}

function dashboard_margin_label(float $profit, float $revenue): string
{
    if ($revenue <= 0) {
        return '0.00% margin';
    }

    return number_format(($profit / $revenue) * 100, 2) . '% margin';
}
