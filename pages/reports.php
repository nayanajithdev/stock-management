<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var ?array $currentUser */

$reportTab = (string) ($_GET['report_tab'] ?? 'daily-sales');
$validReportTabs = ['daily-sales', 'monthly-sales'];

if (! in_array($reportTab, $validReportTabs, true)) {
    $reportTab = 'daily-sales';
}

$canViewProductCost = $dbReady && $pdo instanceof PDO && auth_can_view_product_cost($pdo, $currentUser ?? null);
$defaultDate = date('Y-m-d');
$defaultMonthStart = date('Y-m-01');
$dailySalesSearch = trim((string) ($_GET['daily_q'] ?? ''));
$dailyDate = report_valid_date((string) ($_GET['daily_date'] ?? $defaultDate), $defaultDate);
$monthlySalesSearch = trim((string) ($_GET['monthly_q'] ?? ''));
$monthlyStartDate = report_valid_date((string) ($_GET['monthly_start_date'] ?? $defaultMonthStart), $defaultMonthStart);
$monthlyEndDate = report_valid_date((string) ($_GET['monthly_end_date'] ?? $defaultDate), $defaultDate);

if ($monthlyStartDate > $monthlyEndDate) {
    [$monthlyStartDate, $monthlyEndDate] = [$monthlyEndDate, $monthlyStartDate];
}

$activeSalesSearch = $reportTab === 'monthly-sales' ? $monthlySalesSearch : $dailySalesSearch;
$activeStartDate = $reportTab === 'monthly-sales' ? $monthlyStartDate : $dailyDate;
$activeEndDate = $reportTab === 'monthly-sales' ? $monthlyEndDate : $dailyDate;
$activeStartDateTime = $activeStartDate . ' 00:00:00';
$activeEndDateTime = $activeEndDate . ' 23:59:59';
$validSalesSorts = ['qty', 'sell_price', 'profit'];
$salesSort = (string) ($_GET['report_sort'] ?? '');

if (! in_array($salesSort, $validSalesSorts, true)) {
    $salesSort = '';
}

$salesSortDir = (string) ($_GET['report_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$summary = [
    'revenue' => 0.0,
    'sold_cost' => 0.0,
    'gross_profit' => 0.0,
    'invoices' => 0,
    'units_sold' => 0,
    'expenses' => 0.0,
    'return_value' => 0.0,
    'return_cost_recovered' => 0.0,
    'supplier_refunds' => 0.0,
    'net_profit' => 0.0,
    'open_warranty' => 0,
];
$salesItems = [];

if ($dbReady && $pdo !== null) {
    if ($canViewProductCost) {
        if (in_array($reportTab, ['daily-sales', 'monthly-sales'], true)) {
            $lineRevenueSql = 'GREATEST(
                0,
                si.total - CASE
                    WHEN s.subtotal > 0 THEN LEAST(si.total, s.discount * (si.total / s.subtotal))
                    ELSE 0
                END
            )';
            $salesSummarySql = 'SELECT COUNT(DISTINCT s.id) AS invoices,
                                       COALESCE(SUM(si.quantity), 0) AS units_sold,
                                       COALESCE(SUM(' . $lineRevenueSql . '), 0) AS revenue,
                                       COALESCE(SUM(si.quantity * si.unit_cost), 0) AS sold_cost,
                                       COALESCE(SUM(' . $lineRevenueSql . ' - (si.quantity * si.unit_cost)), 0) AS gross_profit
                                FROM sale_items si
                                INNER JOIN sales s ON s.id = si.sale_id
                                INNER JOIN products p ON p.id = si.product_id
                WHERE s.sale_date BETWEEN :day_start AND :day_end';
            $salesSummaryParams = [
                'day_start' => $activeStartDateTime,
                'day_end' => $activeEndDateTime,
            ];
            $salesSql = 'SELECT s.sale_date,
                                     p.sku,
                                     p.name AS product_name,
                                     p.model,
                                     si.quantity,
                                     si.unit_price,
                                     si.unit_cost,
                                     si.discount,
                                     ' . $lineRevenueSql . ' AS line_total,
                                     (si.quantity * si.unit_cost) AS total_cost,
                                     (' . $lineRevenueSql . ' - (si.quantity * si.unit_cost)) AS profit
                              FROM sale_items si
                              INNER JOIN sales s ON s.id = si.sale_id
                              INNER JOIN products p ON p.id = si.product_id
                              WHERE s.sale_date BETWEEN :day_start AND :day_end';
            $salesParams = [
                'day_start' => $activeStartDateTime,
                'day_end' => $activeEndDateTime,
            ];

            if ($activeSalesSearch !== '') {
                $salesSummarySql .= ' AND (
                    s.invoice_no LIKE :search
                    OR p.sku LIKE :search
                    OR p.name LIKE :search
                    OR p.model LIKE :search
                )';
                $salesSummaryParams['search'] = '%' . $activeSalesSearch . '%';
                $salesSql .= ' AND (
                    s.invoice_no LIKE :search
                    OR p.sku LIKE :search
                    OR p.name LIKE :search
                    OR p.model LIKE :search
                )';
                $salesParams['search'] = '%' . $activeSalesSearch . '%';
            }

            $salesSortSql = [
                'qty' => 'si.quantity',
                'sell_price' => 'si.unit_price',
                'profit' => '(' . $lineRevenueSql . ' - (si.quantity * si.unit_cost))',
            ];

            $salesSummaryStatement = $pdo->prepare($salesSummarySql);
            $salesSummaryStatement->execute($salesSummaryParams);
            $salesSummaryRow = $salesSummaryStatement->fetch() ?: [];

            $expenseSummary = $pdo->prepare(
                'SELECT COALESCE(SUM(amount), 0)
                 FROM expenses
                 WHERE status = "active"
                   AND expense_date BETWEEN :start_date AND :end_date'
            );
            $expenseSummary->execute([
                'start_date' => $activeStartDate,
                'end_date' => $activeEndDate,
            ]);

            $returnValueSummary = $pdo->prepare(
                'SELECT COALESCE(SUM(sri.total), 0)
                 FROM sales_return_items sri
                 INNER JOIN sales_returns sr ON sr.id = sri.return_id
                 WHERE sr.return_date BETWEEN :day_start AND :day_end'
            );
            $returnValueSummary->execute([
                'day_start' => $activeStartDateTime,
                'day_end' => $activeEndDateTime,
            ]);

            $returnCostSummary = $pdo->prepare(
                'SELECT COALESCE(SUM(sri.quantity * sri.unit_cost), 0)
                 FROM sales_return_items sri
                 INNER JOIN sales_returns sr ON sr.id = sri.return_id
                 WHERE sri.restock = 1
                   AND sr.return_date BETWEEN :day_start AND :day_end'
            );
            $returnCostSummary->execute([
                'day_start' => $activeStartDateTime,
                'day_end' => $activeEndDateTime,
            ]);

            $supplierRefundSummary = $pdo->prepare(
                'SELECT COALESCE(SUM(supplier_refund_amount), 0)
                 FROM warranty_claims
                 WHERE supplier_refund_date BETWEEN :start_date AND :end_date'
            );
            $supplierRefundSummary->execute([
                'start_date' => $activeStartDate,
                'end_date' => $activeEndDate,
            ]);

            $summary['revenue'] = (float) ($salesSummaryRow['revenue'] ?? 0);
            $summary['sold_cost'] = (float) ($salesSummaryRow['sold_cost'] ?? 0);
            $summary['invoices'] = (int) ($salesSummaryRow['invoices'] ?? 0);
            $summary['units_sold'] = (int) ($salesSummaryRow['units_sold'] ?? 0);
            $summary['gross_profit'] = (float) ($salesSummaryRow['gross_profit'] ?? 0);
            $summary['expenses'] = (float) $expenseSummary->fetchColumn();
            $summary['return_value'] = (float) $returnValueSummary->fetchColumn();
            $summary['return_cost_recovered'] = (float) $returnCostSummary->fetchColumn();
            $summary['supplier_refunds'] = (float) $supplierRefundSummary->fetchColumn();
            $summary['net_profit'] = $summary['gross_profit']
                - $summary['expenses']
                - $summary['return_value']
                + $summary['return_cost_recovered']
                + $summary['supplier_refunds'];

            if ($salesSort !== '') {
                $salesSql .= ' ORDER BY ' . $salesSortSql[$salesSort] . ' ' . strtoupper($salesSortDir) . ', s.sale_date DESC, s.id DESC, si.id DESC';
            } else {
                $salesSql .= ' ORDER BY s.sale_date DESC, s.id DESC, si.id DESC';
            }

            $salesStatement = $pdo->prepare($salesSql);
            $salesStatement->execute($salesParams);
            $salesItems = $salesStatement->fetchAll();
        }
    } else {
        $summary['open_warranty'] = (int) $pdo->query('SELECT COUNT(*) FROM warranty_claims WHERE status IN ("received", "sent_to_supplier", "ready_for_pickup")')->fetchColumn();
    }
}
?>

<div class="page-heading">
    <div>
        <h1>Reports</h1>
    </div>
</div>

<div class="report-tabs" role="tablist" aria-label="Report sections">
    <a
        class="<?php echo $reportTab === 'daily-sales' ? 'active' : ''; ?>"
        href="<?php echo e(report_tab_url('daily-sales', $dailyDate, $dailySalesSearch, $monthlyStartDate, $monthlyEndDate, $monthlySalesSearch)); ?>"
        role="tab"
        aria-selected="<?php echo $reportTab === 'daily-sales' ? 'true' : 'false'; ?>"
    >
        Daily Sales
    </a>
    <a
        class="<?php echo $reportTab === 'monthly-sales' ? 'active' : ''; ?>"
        href="<?php echo e(report_tab_url('monthly-sales', $dailyDate, $dailySalesSearch, $monthlyStartDate, $monthlyEndDate, $monthlySalesSearch)); ?>"
        role="tab"
        aria-selected="<?php echo $reportTab === 'monthly-sales' ? 'true' : 'false'; ?>"
    >
        Monthly Sales
    </a>
</div>

<?php if ($reportTab === 'daily-sales'): ?>
    <section class="panel report-daily-filter-panel">
        <form class="report-filter-form report-daily-filter-form" method="get" action="<?php echo e(app_url('')); ?>">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="report_tab" value="daily-sales">
            <label class="field">
                <span>Date</span>
                <input type="date" name="daily_date" value="<?php echo e($dailyDate); ?>">
            </label>
            <label class="field">
                <span>Search</span>
                <input type="search" name="daily_q" value="<?php echo e($dailySalesSearch); ?>" placeholder="Product, invoice">
            </label>
            <button class="top-action" type="submit">
                <i data-lucide="filter"></i>
                Apply
            </button>
        </form>
    </section>
<?php elseif ($reportTab === 'monthly-sales'): ?>
    <section class="panel report-daily-filter-panel">
        <form class="report-filter-form" method="get" action="<?php echo e(app_url('')); ?>">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="report_tab" value="monthly-sales">
            <label class="field">
                <span>Start Date</span>
                <input type="date" name="monthly_start_date" value="<?php echo e($monthlyStartDate); ?>">
            </label>
            <label class="field">
                <span>End Date</span>
                <input type="date" name="monthly_end_date" value="<?php echo e($monthlyEndDate); ?>">
            </label>
            <label class="field">
                <span>Search</span>
                <input type="search" name="monthly_q" value="<?php echo e($monthlySalesSearch); ?>" placeholder="Product, invoice">
            </label>
            <button class="top-action" type="submit">
                <i data-lucide="filter"></i>
                Apply
            </button>
        </form>
    </section>
<?php endif; ?>

<?php if (in_array($reportTab, ['daily-sales', 'monthly-sales'], true)): ?>
    <section class="stats-grid compact-stats report-tab-stats" aria-label="Sales summary">
        <article class="stat-card">
            <div>
                <span>Revenue</span>
                <strong><?php echo e(format_money($summary['revenue'])); ?></strong>
            </div>
            <div class="stat-icon"><i data-lucide="circle-dollar-sign"></i></div>
            <small><?php echo (int) $summary['invoices']; ?> invoice(s)</small>
        </article>

        <article class="stat-card">
            <div>
                <span><?php echo $canViewProductCost ? 'Gross Profit' : 'Units Sold'; ?></span>
                <strong><?php echo $canViewProductCost ? e(format_money($summary['gross_profit'])) : (int) $summary['units_sold']; ?></strong>
            </div>
            <div class="stat-icon"><i data-lucide="<?php echo $canViewProductCost ? 'trending-up' : 'boxes'; ?>"></i></div>
            <small><?php echo $canViewProductCost ? e(report_margin_label($summary['gross_profit'], $summary['revenue'])) : 'Items sold in range'; ?></small>
        </article>

        <article class="stat-card">
            <div>
                <span><?php echo $canViewProductCost ? 'Net Profit' : 'Open Warranty'; ?></span>
                <strong><?php echo $canViewProductCost ? e(format_money($summary['net_profit'])) : (int) $summary['open_warranty']; ?></strong>
            </div>
            <div class="stat-icon"><i data-lucide="<?php echo $canViewProductCost ? 'chart-line' : 'shield-check'; ?>"></i></div>
            <small><?php echo $canViewProductCost ? 'After expenses, returns, supplier refunds' : 'Active cases'; ?></small>
        </article>

        <article class="stat-card">
            <div>
                <span><?php echo $reportTab === 'daily-sales' ? 'Today Sold Cost' : 'Sold Cost'; ?></span>
                <strong><?php echo e(format_money($summary['sold_cost'])); ?></strong>
            </div>
            <div class="stat-icon"><i data-lucide="package-check"></i></div>
            <small>Item cost from selected sales</small>
        </article>

        <article class="stat-card">
            <div>
                <span>Expenses</span>
                <strong><?php echo e(format_money($summary['expenses'])); ?></strong>
            </div>
            <div class="stat-icon"><i data-lucide="receipt"></i></div>
            <small>Operating costs</small>
        </article>
    </section>

    <section class="panel table-panel report-tab-panel">
        <div class="panel-header">
            <div>
                <h2><?php echo $reportTab === 'monthly-sales' ? 'Selling items for selected date range' : 'Selling items for selected date'; ?></h2>
            </div>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before viewing sales reports.</p>
        <?php elseif (! $canViewProductCost): ?>
            <p class="empty-state">Product Cost permission is required to view item cost and profit.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Item</th>
                            <th><?php echo report_sort_header('Qty', 'qty', $salesSort, $salesSortDir); ?></th>
                            <th><?php echo report_sort_header('Sell Price', 'sell_price', $salesSort, $salesSortDir); ?></th>
                            <th>Item Cost</th>
                            <th>Line Total</th>
                            <th>Total Cost</th>
                            <th><?php echo report_sort_header('Profit', 'profit', $salesSort, $salesSortDir); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($salesItems === []): ?>
                            <tr>
                                <td colspan="8">No selling items found for the selected date<?php echo $reportTab === 'monthly-sales' ? ' range' : ''; ?>.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($salesItems as $item): ?>
                            <?php $profit = (float) $item['profit']; ?>
                            <tr>
                                <td><?php echo e(date('Y-m-d H:i', strtotime((string) $item['sale_date']))); ?></td>
                                <td>
                                    <strong class="table-title"><?php echo e($item['sku'] . ' - ' . $item['product_name']); ?></strong>
                                    <?php if (! empty($item['model'])): ?>
                                        <span class="table-subtitle"><?php echo e($item['model']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $item['quantity']; ?></td>
                                <td><?php echo e(format_money($item['unit_price'])); ?></td>
                                <td><?php echo e(format_money($item['unit_cost'])); ?></td>
                                <td><?php echo e(format_money($item['line_total'])); ?></td>
                                <td><?php echo e(format_money($item['total_cost'])); ?></td>
                                <td class="<?php echo $profit >= 0 ? 'text-good' : 'text-danger'; ?>"><?php echo e(format_money($profit)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php
function report_valid_date(string $value, string $fallback): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
}

function report_margin_label(float $profit, float $revenue): string
{
    if ($revenue <= 0) {
        return '0.00% margin';
    }

    return number_format(($profit / $revenue) * 100, 2) . '% margin';
}

function report_sort_header(string $label, string $column, string $currentSort, string $currentDir): string
{
    $isActive = $currentSort === $column;
    $nextDir = $isActive && $currentDir === 'desc' ? 'asc' : 'desc';
    $icon = $isActive && $currentDir === 'asc' ? 'chevron-up' : 'chevron-down';
    $classes = 'report-sort-link' . ($isActive ? ' active' : '');
    $query = $_GET;
    $query['page'] = 'reports';
    $query['report_sort'] = $column;
    $query['report_dir'] = $nextDir;

    return '<a class="' . e($classes) . '" href="' . e(app_url('?' . http_build_query($query))) . '">' .
        e($label) .
        '<i data-lucide="' . e($icon) . '"></i>' .
        '</a>';
}

function report_tab_url(string $tab, string $dailyDate, string $dailySearch, string $monthlyStartDate, string $monthlyEndDate, string $monthlySearch): string
{
    $query = [
        'page' => 'reports',
        'report_tab' => $tab,
    ];

    if ($tab === 'daily-sales') {
        $query['daily_date'] = $dailyDate;

        if ($dailySearch !== '') {
            $query['daily_q'] = $dailySearch;
        }
    }

    if ($tab === 'monthly-sales') {
        $query['monthly_start_date'] = $monthlyStartDate;
        $query['monthly_end_date'] = $monthlyEndDate;

        if ($monthlySearch !== '') {
            $query['monthly_q'] = $monthlySearch;
        }
    }

    return app_url('?' . http_build_query($query));
}
