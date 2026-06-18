<?php
/** @var array $currentUser */
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$canViewProductCost = $dbReady && $pdo instanceof PDO && auth_can_view_product_cost($pdo, $currentUser ?? null);
$primaryStats = [
    ['label' => 'Today Sales', 'value' => format_money(0), 'meta' => '0 invoice(s)', 'icon' => 'badge-dollar-sign'],
    ['label' => 'Cash In Today', 'value' => format_money(0), 'meta' => 'Sales and collections', 'icon' => 'wallet'],
    ['label' => 'Customer Due', 'value' => format_money(0), 'meta' => 'Open receivables', 'icon' => 'receipt-text'],
];

if ($canViewProductCost) {
    $primaryStats[] = ['label' => 'Supplier Due', 'value' => format_money(0), 'meta' => 'Open payables', 'icon' => 'hand-coins'];
}
$currentYear = (int) date('Y');
$trendMode = (string) ($_GET['trend'] ?? '') === 'weekly' ? 'weekly' : 'monthly';
$selectedWeekStart = dashboard_week_start((string) ($_GET['week'] ?? ''));
$selectedWeekEnd = $selectedWeekStart->modify('+7 days');
$selectedWeekInput = $selectedWeekStart->format('o-\WW');
$selectedWeekRange = dashboard_week_range_label($selectedWeekStart, $selectedWeekEnd->modify('-1 day'));
$monthlyTrend = dashboard_empty_month_trend();
$weeklyTrend = dashboard_empty_week_trend($selectedWeekStart);
$metrics = [
    'today_sales' => 0.0,
    'today_orders' => 0,
    'today_paid' => 0.0,
    'today_collections' => 0.0,
    'today_customer_refunds' => 0.0,
    'today_expenses' => 0.0,
    'today_supplier_paid' => 0.0,
    'today_supplier_refunds' => 0.0,
    'month_revenue' => 0.0,
    'month_orders' => 0,
    'month_profit' => 0.0,
    'month_expenses' => 0.0,
    'month_refunds' => 0.0,
    'month_return_value' => 0.0,
    'month_return_cost_recovered' => 0.0,
    'month_supplier_refunds' => 0.0,
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
                COALESCE(SUM(total), 0) AS total
         FROM sales
         WHERE DATE(sale_date) = CURRENT_DATE'
    );
    $todayInitialPaid = (float) $pdo->query(
        'SELECT COALESCE(SUM(GREATEST(s.paid - COALESCE(cp.total_collected, 0), 0)), 0)
         FROM sales s
         LEFT JOIN (
            SELECT sale_id, COALESCE(SUM(amount), 0) AS total_collected
            FROM customer_payments
            GROUP BY sale_id
         ) cp ON cp.sale_id = s.id
         WHERE DATE(s.sale_date) = CURRENT_DATE'
    )->fetchColumn();
    $monthSalesRow = dashboard_fetch_one($pdo,
        'SELECT COUNT(*) AS orders,
                COALESCE(SUM(total), 0) AS total
         FROM sales
         WHERE sale_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    );

    $metrics['today_orders'] = (int) ($todaySalesRow['orders'] ?? 0);
    $metrics['today_sales'] = (float) ($todaySalesRow['total'] ?? 0);
    $metrics['today_paid'] = $todayInitialPaid;
    $metrics['today_collections'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE DATE(payment_date) = CURRENT_DATE')->fetchColumn();
    $metrics['today_customer_refunds'] = (float) $pdo->query('SELECT COALESCE(SUM(refund_amount), 0) FROM sales_returns WHERE DATE(return_date) = CURRENT_DATE')->fetchColumn();
    $metrics['today_expenses'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = "active" AND expense_date = CURRENT_DATE')->fetchColumn();
    $metrics['month_orders'] = (int) ($monthSalesRow['orders'] ?? 0);
    $metrics['month_revenue'] = (float) ($monthSalesRow['total'] ?? 0);
    $metrics['month_expenses'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = "active" AND expense_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")')->fetchColumn();
    $metrics['month_refunds'] = (float) $pdo->query('SELECT COALESCE(SUM(refund_amount), 0) FROM sales_returns WHERE return_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")')->fetchColumn();
    $metrics['month_return_value'] = (float) $pdo->query(
        'SELECT COALESCE(SUM(sri.total), 0)
         FROM sales_return_items sri
         INNER JOIN sales_returns sr ON sr.id = sri.return_id
         WHERE sr.return_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    )->fetchColumn();
    $metrics['receivable'] = dashboard_receivable_total($pdo);
    $metrics['low_stock'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active" AND reorder_level > 0 AND current_stock <= reorder_level')->fetchColumn();
    $metrics['open_warranty'] = (int) $pdo->query('SELECT COUNT(*) FROM warranty_claims WHERE status IN ("received", "sent_to_supplier", "ready_for_pickup")')->fetchColumn();
    $metrics['warranty_expiring'] = dashboard_warranty_expiring_lots($pdo);

    if ($canViewProductCost) {
        $monthProfitRow = dashboard_fetch_one($pdo,
            'SELECT COALESCE(SUM(s.subtotal - s.discount - COALESCE(cost.total_cost, 0)), 0) AS profit
             FROM sales s
             LEFT JOIN (
                SELECT sale_id, COALESCE(SUM(quantity * unit_cost), 0) AS total_cost
                FROM sale_items
                GROUP BY sale_id
             ) cost ON cost.sale_id = s.id
             WHERE s.sale_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
        );

        $metrics['today_supplier_paid'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE DATE(payment_date) = CURRENT_DATE')->fetchColumn();
        $metrics['today_supplier_refunds'] = (float) $pdo->query('SELECT COALESCE(SUM(supplier_refund_amount), 0) FROM warranty_claims WHERE supplier_refund_date = CURRENT_DATE')->fetchColumn();
        $metrics['month_profit'] = (float) ($monthProfitRow['profit'] ?? 0);
        $metrics['month_return_cost_recovered'] = (float) $pdo->query(
            'SELECT COALESCE(SUM(sri.quantity * sri.unit_cost), 0)
             FROM sales_return_items sri
             INNER JOIN sales_returns sr ON sr.id = sri.return_id
             WHERE sri.restock = 1
               AND sr.return_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
        )->fetchColumn();
        $metrics['month_supplier_refunds'] = (float) $pdo->query('SELECT COALESCE(SUM(supplier_refund_amount), 0) FROM warranty_claims WHERE supplier_refund_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")')->fetchColumn();
        $metrics['month_net_profit'] = $metrics['month_profit'] - $metrics['month_expenses'] - $metrics['month_return_value'] + $metrics['month_return_cost_recovered'] + $metrics['month_supplier_refunds'];
        $metrics['payable'] = (float) $pdo->query('SELECT COALESCE(SUM(total - paid), 0) FROM purchases WHERE total > paid')->fetchColumn();
        $metrics['stock_value'] = app_stock_value_total($pdo);
    }

    $cashInToday = $metrics['today_paid'] + $metrics['today_collections'] + ($canViewProductCost ? $metrics['today_supplier_refunds'] : 0.0);
    $primaryStats = [
        [
            'label' => 'Today Sales',
            'value' => format_money($metrics['today_sales']),
            'meta' => $metrics['today_orders'] . ' invoice(s)',
            'icon' => 'badge-dollar-sign',
        ],
        [
            'label' => 'Cash In Today',
            'value' => format_money($cashInToday),
            'meta' => $canViewProductCost ? 'Sales, collections, supplier refunds' : 'Sales and collections',
            'icon' => 'wallet',
        ],
        [
            'label' => 'Customer Due',
            'value' => format_money($metrics['receivable']),
            'meta' => 'Open receivables',
            'icon' => 'receipt-text',
        ],
    ];

    if ($canViewProductCost) {
        $primaryStats[] = [
            'label' => 'Supplier Due',
            'value' => format_money($metrics['payable']),
            'meta' => 'Open payables',
            'icon' => 'hand-coins',
        ];
    }
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
        $monthlyTrend[$month - 1]['revenue'] = $trendRows[$month] ?? 0.0;
    }

    $weeklyRows = [];
    $weeklyStatement = $pdo->prepare(
        'SELECT DATE(sale_date) AS sale_day,
                COALESCE(SUM(total), 0) AS revenue
         FROM sales
         WHERE sale_date >= :week_start
           AND sale_date < :week_end
         GROUP BY DATE(sale_date)'
    );
    $weeklyStatement->execute([
        ':week_start' => $selectedWeekStart->format('Y-m-d 00:00:00'),
        ':week_end' => $selectedWeekEnd->format('Y-m-d 00:00:00'),
    ]);

    foreach ($weeklyStatement->fetchAll() as $row) {
        $weeklyRows[(string) $row['sale_day']] = (float) $row['revenue'];
    }

    foreach ($weeklyTrend as $index => $day) {
        $weeklyTrend[$index]['revenue'] = $weeklyRows[$day['date']] ?? 0.0;
    }

}

$maxRevenue = 0.0;
$trendData = $trendMode === 'weekly' ? $weeklyTrend : $monthlyTrend;
$trendTotal = 0.0;

foreach ($trendData as $point) {
    $maxRevenue = max($maxRevenue, (float) $point['revenue']);
    $trendTotal += (float) $point['revenue'];
}

$trendTitle = $trendMode === 'weekly' ? 'Weekly revenue' : $currentYear . ' revenue';
$trendBadge = $trendMode === 'weekly'
    ? format_money($trendTotal) . ' selected week'
    : ($canViewProductCost
        ? format_money($metrics['month_net_profit']) . ' est. net this month'
        : format_money($metrics['month_revenue']) . ' revenue this month');
$cashOutToday = $metrics['today_expenses'] + $metrics['today_customer_refunds'] + ($canViewProductCost ? $metrics['today_supplier_paid'] : 0.0);
?>

<div class="page-heading">
    <div>
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

<section class="dashboard-grid">
    <article class="panel sales-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Sales Trend</p>
                <h2><?php echo e($trendTitle); ?></h2>
                <?php if ($trendMode === 'weekly'): ?>
                    <p class="trend-range"><?php echo e($selectedWeekRange); ?></p>
                <?php endif; ?>
            </div>
            <div class="trend-toolbar">
                <span class="dashboard-pill"><?php echo e($trendBadge); ?></span>
                <nav class="segmented trend-segmented" aria-label="Revenue view">
                    <a class="<?php echo $trendMode === 'monthly' ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=dashboard&trend=monthly')); ?>">Monthly</a>
                    <a class="<?php echo $trendMode === 'weekly' ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=dashboard&trend=weekly&week=' . rawurlencode($selectedWeekInput))); ?>">Weekly</a>
                </nav>
                <?php if ($trendMode === 'weekly'): ?>
                    <form class="trend-week-form" method="get">
                        <input type="hidden" name="page" value="dashboard">
                        <input type="hidden" name="trend" value="weekly">
                        <input class="trend-week-input" type="week" name="week" value="<?php echo e($selectedWeekInput); ?>" aria-label="Select revenue week" onchange="this.form.submit()">
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="dashboard-bars <?php echo $trendMode === 'weekly' ? 'weekly-bars' : ''; ?>" aria-label="<?php echo $trendMode === 'weekly' ? 'Weekly sales chart' : 'Monthly sales chart'; ?>">
            <?php foreach ($trendData as $point): ?>
                <?php
                $height = $maxRevenue > 0 ? max(8, ((float) $point['revenue'] / $maxRevenue) * 100) : 0;
                $isCurrentPoint = $trendMode === 'weekly'
                    ? (string) ($point['date'] ?? '') === date('Y-m-d')
                    : (int) date('n') === (int) ($point['month'] ?? 0);
                ?>
                <div class="dashboard-bar-item <?php echo $isCurrentPoint ? 'active' : ''; ?>">
                    <div class="dashboard-bar-track">
                        <span style="height: <?php echo e(number_format($height, 2, '.', '')); ?>%"></span>
                    </div>
                    <strong><?php echo e($point['label']); ?></strong>
                    <small><?php echo e(format_money((float) $point['revenue'])); ?></small>
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
            <a href="<?php echo e(app_url('?page=products&product_status=active&stock_filter=needs_reorder')); ?>">
                <i data-lucide="triangle-alert"></i>
                <span>Low stock</span>
                <strong><?php echo (int) $metrics['low_stock']; ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=credit-sales')); ?>">
                <i data-lucide="receipt-text"></i>
                <span>Customer due</span>
                <strong><?php echo e(format_money($metrics['receivable'])); ?></strong>
            </a>
            <?php if ($canViewProductCost): ?>
                <a href="<?php echo e(app_url('?page=supplier-credit')); ?>">
                    <i data-lucide="hand-coins"></i>
                    <span>Supplier due</span>
                    <strong><?php echo e(format_money($metrics['payable'])); ?></strong>
                </a>
            <?php endif; ?>
            <a href="<?php echo e(app_url('?page=expenses')); ?>">
                <i data-lucide="receipt"></i>
                <span>Cash Out Today</span>
                <strong><?php echo e(format_money($cashOutToday)); ?></strong>
            </a>
            <a href="<?php echo e(app_url('?page=warranty-returns')); ?>">
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

function dashboard_empty_month_trend(): array
{
    $trend = [];

    for ($month = 1; $month <= 12; $month++) {
        $trend[] = [
            'month' => $month,
            'label' => date('M', mktime(0, 0, 0, $month, 1)),
            'revenue' => 0.0,
        ];
    }

    return $trend;
}

function dashboard_week_start(string $weekValue): DateTimeImmutable
{
    if (preg_match('/^(\d{4})-W(\d{2})$/', $weekValue, $matches) === 1) {
        $week = (int) $matches[2];

        if ($week >= 1 && $week <= 53) {
            return (new DateTimeImmutable('now'))->setISODate((int) $matches[1], $week)->setTime(0, 0);
        }
    }

    return (new DateTimeImmutable('monday this week'))->setTime(0, 0);
}

function dashboard_empty_week_trend(DateTimeImmutable $weekStart): array
{
    $trend = [];

    for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
        $day = $weekStart->modify('+' . $dayIndex . ' days');
        $trend[] = [
            'date' => $day->format('Y-m-d'),
            'label' => $day->format('D'),
            'revenue' => 0.0,
        ];
    }

    return $trend;
}

function dashboard_week_range_label(DateTimeImmutable $weekStart, DateTimeImmutable $weekEnd): string
{
    if ($weekStart->format('Y') === $weekEnd->format('Y')) {
        return $weekStart->format('M j') . ' - ' . $weekEnd->format('M j, Y');
    }

    return $weekStart->format('M j, Y') . ' - ' . $weekEnd->format('M j, Y');
}

function dashboard_warranty_expiring_lots(PDO $pdo): int
{
    $stockOutRows = $pdo->query(
        'SELECT product_id, COALESCE(SUM(ABS(quantity_change)), 0) AS stock_out
         FROM stock_movements
         WHERE quantity_change < 0
           AND (reference_type IS NULL OR reference_type <> "stock_lot")
         GROUP BY product_id'
    )->fetchAll();
    $stockOutByProduct = [];

    foreach ($stockOutRows as $row) {
        $stockOutByProduct[(int) $row['product_id']] = (int) $row['stock_out'];
    }

    $lotRows = $pdo->query(
        'SELECT sm.id,
                sm.product_id,
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
           AND sm.movement_type IN ("opening", "purchase", "return_in", "adjustment_in", "warranty_supplier_in")
         ORDER BY sm.product_id ASC, COALESCE(pu.purchase_date, DATE(sm.created_at)) ASC, sm.id ASC'
    )->fetchAll();

    $adjustmentRows = $pdo->query(
        'SELECT product_id, reference_id, COALESCE(SUM(quantity_change), 0) AS quantity_change
         FROM stock_movements
         WHERE reference_type = "stock_lot"
           AND reference_id IS NOT NULL
         GROUP BY product_id, reference_id'
    )->fetchAll();
    $lotAdjustments = [];

    foreach ($adjustmentRows as $row) {
        $lotAdjustments[(int) $row['product_id']][(int) $row['reference_id']] = (int) $row['quantity_change'];
    }

    $today = date('Y-m-d');
    $warningCutoff = date('Y-m-d', strtotime('+30 days'));
    $expiringLots = 0;

    foreach ($lotRows as $lot) {
        $productId = (int) $lot['product_id'];
        $lotId = (int) $lot['id'];
        $lotQuantity = max(0, (int) $lot['quantity_change'] + (int) ($lotAdjustments[$productId][$lotId] ?? 0));
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

function dashboard_receivable_total(PDO $pdo): float
{
    return (float) $pdo->query(
        'SELECT COALESCE(SUM(balance), 0)
         FROM (
            SELECT GREATEST(s.total - s.paid - COALESCE(ret.returned_total, 0) + COALESCE(ret.refund_total, 0), 0) AS balance
            FROM sales s
            LEFT JOIN (
                SELECT sr.sale_id,
                       COALESCE(SUM(ri.returned_total), 0) AS returned_total,
                       COALESCE(SUM(sr.refund_amount), 0) AS refund_total
                FROM sales_returns sr
                LEFT JOIN (
                    SELECT return_id, COALESCE(SUM(total), 0) AS returned_total
                    FROM sales_return_items
                    GROUP BY return_id
                ) ri ON ri.return_id = sr.id
                GROUP BY sr.sale_id
            ) ret ON ret.sale_id = s.id
         ) receivables
         WHERE balance > 0'
    )->fetchColumn();
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
