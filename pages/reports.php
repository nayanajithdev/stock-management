<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$reportSearch = trim((string) ($_GET['q'] ?? ''));
$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-d');
$startDate = report_valid_date((string) ($_GET['start_date'] ?? $defaultStart), $defaultStart);
$endDate = report_valid_date((string) ($_GET['end_date'] ?? $defaultEnd), $defaultEnd);

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$startDateTime = $startDate . ' 00:00:00';
$endDateTime = $endDate . ' 23:59:59';
$summary = [
    'revenue' => 0.0,
    'gross_profit' => 0.0,
    'invoices' => 0,
    'units_sold' => 0,
    'stock_value' => 0.0,
    'receivable' => 0.0,
    'refunds' => 0.0,
    'return_value' => 0.0,
    'return_cost_recovered' => 0.0,
    'supplier_refunds' => 0.0,
    'expenses' => 0.0,
    'net_profit' => 0.0,
    'open_warranty' => 0,
];
$dailySales = [];
$topProducts = [];
$lowStockItems = [];
$creditCustomers = [];
$returnRows = [];
$warrantyRows = [];
$expenseRows = [];

if ($dbReady && $pdo !== null) {
    $salesSummary = $pdo->prepare(
        'SELECT COUNT(*) AS invoices,
                COALESCE(SUM(total), 0) AS revenue
         FROM sales
         WHERE sale_date BETWEEN :start_date AND :end_date'
    );
    $salesSummary->execute([
        'start_date' => $startDateTime,
        'end_date' => $endDateTime,
    ]);
    $salesRow = $salesSummary->fetch() ?: [];

    $profitSummary = $pdo->prepare(
        'SELECT COALESCE(SUM(cost.units_sold), 0) AS units_sold,
                COALESCE(SUM(s.subtotal - s.discount - COALESCE(cost.total_cost, 0)), 0) AS gross_profit
         FROM sales s
         LEFT JOIN (
            SELECT sale_id,
                   COALESCE(SUM(quantity), 0) AS units_sold,
                   COALESCE(SUM(quantity * unit_cost), 0) AS total_cost
            FROM sale_items
            GROUP BY sale_id
         ) cost ON cost.sale_id = s.id
         WHERE s.sale_date BETWEEN :start_date AND :end_date'
    );
    $profitSummary->execute([
        'start_date' => $startDateTime,
        'end_date' => $endDateTime,
    ]);
    $profitRow = $profitSummary->fetch() ?: [];

    $returnSummary = $pdo->prepare(
        'SELECT COALESCE(SUM(refund_amount), 0)
         FROM sales_returns
         WHERE return_date BETWEEN :start_date AND :end_date'
    );
    $returnSummary->execute([
        'start_date' => $startDateTime,
        'end_date' => $endDateTime,
    ]);
    $returnValueSummary = $pdo->prepare(
        'SELECT COALESCE(SUM(sri.total), 0)
         FROM sales_return_items sri
         INNER JOIN sales_returns sr ON sr.id = sri.return_id
         WHERE sr.return_date BETWEEN :start_date AND :end_date'
    );
    $returnValueSummary->execute([
        'start_date' => $startDateTime,
        'end_date' => $endDateTime,
    ]);
    $returnCostSummary = $pdo->prepare(
        'SELECT COALESCE(SUM(sri.quantity * sri.unit_cost), 0)
         FROM sales_return_items sri
         INNER JOIN sales_returns sr ON sr.id = sri.return_id
         WHERE sri.restock = 1
           AND sr.return_date BETWEEN :start_date AND :end_date'
    );
    $returnCostSummary->execute([
        'start_date' => $startDateTime,
        'end_date' => $endDateTime,
    ]);
    $expenseSummary = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM expenses
         WHERE status = "active"
           AND expense_date BETWEEN :start_date AND :end_date'
    );
    $expenseSummary->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);
    $supplierRefundSummary = $pdo->prepare(
        'SELECT COALESCE(SUM(supplier_refund_amount), 0)
         FROM warranty_claims
         WHERE supplier_refund_date BETWEEN :start_date AND :end_date'
    );
    $supplierRefundSummary->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    $summary['revenue'] = (float) ($salesRow['revenue'] ?? 0);
    $summary['gross_profit'] = (float) ($profitRow['gross_profit'] ?? 0);
    $summary['invoices'] = (int) ($salesRow['invoices'] ?? 0);
    $summary['units_sold'] = (int) ($profitRow['units_sold'] ?? 0);
    $summary['stock_value'] = app_stock_value_total($pdo);
    $summary['receivable'] = report_receivable_total($pdo);
    $summary['refunds'] = (float) $returnSummary->fetchColumn();
    $summary['return_value'] = (float) $returnValueSummary->fetchColumn();
    $summary['return_cost_recovered'] = (float) $returnCostSummary->fetchColumn();
    $summary['supplier_refunds'] = (float) $supplierRefundSummary->fetchColumn();
    $summary['expenses'] = (float) $expenseSummary->fetchColumn();
    $summary['net_profit'] = $summary['gross_profit'] - $summary['expenses'] - $summary['return_value'] + $summary['return_cost_recovered'] + $summary['supplier_refunds'];
    $summary['open_warranty'] = (int) $pdo->query('SELECT COUNT(*) FROM warranty_claims WHERE status IN ("received", "sent_to_supplier", "ready_for_pickup")')->fetchColumn();

    $dailyStatement = $pdo->prepare(
        'SELECT DATE(sale_date) AS sale_day,
                COUNT(*) AS invoices,
                COALESCE(SUM(total), 0) AS revenue
         FROM sales
         WHERE sale_date BETWEEN :start_date AND :end_date
         GROUP BY DATE(sale_date)
         ORDER BY sale_day ASC'
    );
    $dailyStatement->execute([
        'start_date' => $startDateTime,
        'end_date' => $endDateTime,
    ]);
    $dailySales = $dailyStatement->fetchAll();

    $productSql = 'SELECT p.sku,
                          p.name,
                          p.current_stock,
                          COALESCE(SUM(si.quantity), 0) AS units_sold,
                          COALESCE(SUM(si.total - CASE WHEN s.subtotal > 0 THEN s.discount * (si.total / s.subtotal) ELSE 0 END), 0) AS revenue,
                          COALESCE(SUM(si.quantity * si.unit_cost), 0) AS cost,
                          COALESCE(SUM((si.total - CASE WHEN s.subtotal > 0 THEN s.discount * (si.total / s.subtotal) ELSE 0 END) - (si.quantity * si.unit_cost)), 0) AS gross_profit
                   FROM sale_items si
                   INNER JOIN sales s ON s.id = si.sale_id
                   INNER JOIN products p ON p.id = si.product_id
                   WHERE s.sale_date BETWEEN :start_date AND :end_date';
    $productParams = [
        'start_date' => $startDateTime,
        'end_date' => $endDateTime,
    ];

    if ($reportSearch !== '') {
        $productSql .= ' AND (p.sku LIKE :product_search OR p.name LIKE :product_search)';
        $productParams['product_search'] = '%' . $reportSearch . '%';
    }

    $productSql .= ' GROUP BY p.id ORDER BY revenue DESC, units_sold DESC LIMIT 12';
    $productStatement = $pdo->prepare($productSql);
    $productStatement->execute($productParams);
    $topProducts = $productStatement->fetchAll();

    $lowStockSql = 'SELECT sku,
                           name,
                           id,
                           current_stock,
                           reorder_level,
                           cost_price
                    FROM products
                    WHERE status = "active"
                      AND reorder_level > 0
                      AND current_stock <= reorder_level';
    $lowStockParams = [];

    if ($reportSearch !== '') {
        $lowStockSql .= ' AND (sku LIKE :stock_search OR name LIKE :stock_search)';
        $lowStockParams['stock_search'] = '%' . $reportSearch . '%';
    }

    $lowStockSql .= ' ORDER BY current_stock ASC, name ASC LIMIT 12';
    $lowStockStatement = $pdo->prepare($lowStockSql);
    $lowStockStatement->execute($lowStockParams);
    $lowStockItems = $lowStockStatement->fetchAll();
    $lowStockValues = $lowStockItems === []
        ? []
        : app_stock_values_by_product($pdo, array_map(static fn (array $item): int => (int) $item['id'], $lowStockItems));

    foreach ($lowStockItems as $index => $item) {
        $productId = (int) $item['id'];
        $lowStockItems[$index]['stock_value'] = (float) ($lowStockValues[$productId]['value'] ?? ((int) $item['current_stock'] * (float) $item['cost_price']));
    }

    $creditSql = 'SELECT COALESCE(c.name, "Walk-in Customer") AS customer_name,
                         c.phone,
                         COUNT(s.id) AS open_invoices,
                         COALESCE(SUM(GREATEST(s.total - s.paid - COALESCE(ret.returned_total, 0) + COALESCE(ret.refund_total, 0), 0)), 0) AS balance
                  FROM sales s
                  LEFT JOIN customers c ON c.id = s.customer_id
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
                  WHERE GREATEST(s.total - s.paid - COALESCE(ret.returned_total, 0) + COALESCE(ret.refund_total, 0), 0) > 0';
    $creditParams = [];

    if ($reportSearch !== '') {
        $creditSql .= ' AND (c.name LIKE :credit_search OR c.phone LIKE :credit_search OR s.invoice_no LIKE :credit_search)';
        $creditParams['credit_search'] = '%' . $reportSearch . '%';
    }

    $creditSql .= ' GROUP BY s.customer_id, c.name, c.phone ORDER BY balance DESC LIMIT 12';
    $creditStatement = $pdo->prepare($creditSql);
    $creditStatement->execute($creditParams);
    $creditCustomers = $creditStatement->fetchAll();

    $returnsSql = 'SELECT sr.return_no,
                          sr.return_date,
                          sr.refund_amount,
                          sr.status,
                          s.invoice_no,
                          c.name AS customer_name
                   FROM sales_returns sr
                   INNER JOIN sales s ON s.id = sr.sale_id
                   LEFT JOIN customers c ON c.id = sr.customer_id
                   WHERE sr.return_date BETWEEN :start_date AND :end_date';
    $returnsParams = [
        'start_date' => $startDateTime,
        'end_date' => $endDateTime,
    ];

    if ($reportSearch !== '') {
        $returnsSql .= ' AND (sr.return_no LIKE :return_search OR s.invoice_no LIKE :return_search OR c.name LIKE :return_search)';
        $returnsParams['return_search'] = '%' . $reportSearch . '%';
    }

    $returnsSql .= ' ORDER BY sr.return_date DESC, sr.id DESC LIMIT 12';
    $returnsStatement = $pdo->prepare($returnsSql);
    $returnsStatement->execute($returnsParams);
    $returnRows = $returnsStatement->fetchAll();

    $warrantySql = 'SELECT wc.claim_no,
                           wc.received_date,
                           wc.resolved_date,
                           wc.supplier_refund_amount,
                           wc.supplier_refund_date,
                           wc.status,
                           p.sku,
                           p.name AS product_name,
                           c.name AS customer_name
                    FROM warranty_claims wc
                    INNER JOIN products p ON p.id = wc.product_id
                    LEFT JOIN customers c ON c.id = wc.customer_id
                    WHERE wc.received_date BETWEEN :start_date AND :end_date';
    $warrantyParams = [
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];

    if ($reportSearch !== '') {
        $warrantySql .= ' AND (wc.claim_no LIKE :warranty_search OR p.sku LIKE :warranty_search OR p.name LIKE :warranty_search OR c.name LIKE :warranty_search)';
        $warrantyParams['warranty_search'] = '%' . $reportSearch . '%';
    }

    $warrantySql .= ' ORDER BY wc.received_date DESC, wc.id DESC LIMIT 12';
    $warrantyStatement = $pdo->prepare($warrantySql);
    $warrantyStatement->execute($warrantyParams);
    $warrantyRows = $warrantyStatement->fetchAll();

    $expenseSql = 'SELECT category,
                          COUNT(*) AS expense_count,
                          COALESCE(SUM(amount), 0) AS total_amount
                   FROM expenses
                   WHERE status = "active"
                     AND expense_date BETWEEN :start_date AND :end_date';
    $expenseParams = [
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];

    if ($reportSearch !== '') {
        $expenseSql .= ' AND (category LIKE :expense_search OR vendor LIKE :expense_search OR reference_no LIKE :expense_search OR notes LIKE :expense_search)';
        $expenseParams['expense_search'] = '%' . $reportSearch . '%';
    }

    $expenseSql .= ' GROUP BY category ORDER BY total_amount DESC LIMIT 12';
    $expenseStatement = $pdo->prepare($expenseSql);
    $expenseStatement->execute($expenseParams);
    $expenseRows = $expenseStatement->fetchAll();
}

$maxDailyRevenue = 0.0;

foreach ($dailySales as $day) {
    $maxDailyRevenue = max($maxDailyRevenue, (float) $day['revenue']);
}
?>

<div class="page-heading">
    <div>
        <h1>Reports</h1>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=sales')); ?>">
        <i data-lucide="scan-barcode"></i>
        New Invoice
    </a>
</div>

<section class="panel" id="report-filters">
    <form class="report-filter-form" method="get" action="<?php echo e(app_url('')); ?>">
        <input type="hidden" name="page" value="reports">
        <label class="field">
            <span>Start Date</span>
            <input type="date" name="start_date" value="<?php echo e($startDate); ?>">
        </label>
        <label class="field">
            <span>End Date</span>
            <input type="date" name="end_date" value="<?php echo e($endDate); ?>">
        </label>
        <label class="field">
            <span>Search</span>
            <input type="search" name="q" value="<?php echo e($reportSearch); ?>" placeholder="Product, invoice, customer">
        </label>
        <button class="top-action" type="submit">
            <i data-lucide="filter"></i>
            Apply
        </button>
    </form>
</section>

<section class="stats-grid compact-stats" aria-label="Report summary">
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
            <span>Gross Profit</span>
            <strong><?php echo e(format_money($summary['gross_profit'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="trending-up"></i></div>
        <small><?php echo report_margin_label($summary['gross_profit'], $summary['revenue']); ?></small>
    </article>
    <article class="stat-card">
        <div>
            <span>Expenses</span>
            <strong><?php echo e(format_money($summary['expenses'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="receipt"></i></div>
        <small>Operating costs</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Net Profit</span>
            <strong><?php echo e(format_money($summary['net_profit'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="chart-line"></i></div>
        <small>After expenses, returns, supplier refunds</small>
    </article>
</section>

<section class="report-layout">
    <article class="panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Sales Trend</p>
                <h2>Daily revenue</h2>
            </div>
        </div>

        <div class="report-bars" aria-label="Daily revenue report">
            <?php if ($dailySales === []): ?>
                <p class="empty-state">No sales found for this date range.</p>
            <?php endif; ?>

            <?php foreach ($dailySales as $day): ?>
                <?php $width = $maxDailyRevenue > 0 ? ((float) $day['revenue'] / $maxDailyRevenue) * 100 : 0; ?>
                <div class="report-bar-row">
                    <span><?php echo e(date('M d', strtotime((string) $day['sale_day']))); ?></span>
                    <div class="report-bar"><i style="width: <?php echo e(number_format($width, 2, '.', '')); ?>%"></i></div>
                    <strong><?php echo e(format_money($day['revenue'])); ?></strong>
                    <small><?php echo (int) $day['invoices']; ?> inv.</small>
                </div>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Product Profit</p>
                <h2>Top selling items</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Units</th>
                        <th>Revenue</th>
                        <th>Cost</th>
                        <th>Profit</th>
                        <th>Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($topProducts === []): ?>
                        <tr>
                            <td colspan="7">No product sales found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($topProducts as $product): ?>
                        <tr>
                            <td><?php echo e($product['sku']); ?></td>
                            <td><?php echo e($product['name']); ?></td>
                            <td><?php echo (int) $product['units_sold']; ?></td>
                            <td><?php echo e(format_money($product['revenue'])); ?></td>
                            <td><?php echo e(format_money($product['cost'])); ?></td>
                            <td class="<?php echo (float) $product['gross_profit'] >= 0 ? 'text-good' : 'text-danger'; ?>"><?php echo e(format_money($product['gross_profit'])); ?></td>
                            <td><?php echo (int) $product['current_stock']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <div class="report-grid-two">
        <article class="panel table-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Expenses</p>
                    <h2>Category totals</h2>
                </div>
                <a class="muted-link" href="<?php echo e(app_url('?page=expenses')); ?>">Expenses</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Entries</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($expenseRows === []): ?>
                            <tr>
                                <td colspan="3">No expenses found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($expenseRows as $expense): ?>
                            <tr>
                                <td><?php echo e($expense['category']); ?></td>
                                <td><?php echo (int) $expense['expense_count']; ?></td>
                                <td class="text-danger"><?php echo e(format_money($expense['total_amount'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel table-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Inventory Risk</p>
                    <h2>Low stock</h2>
                </div>
                <a class="muted-link" href="<?php echo e(app_url('?page=products')); ?>">Products</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product</th>
                            <th>Stock</th>
                            <th>Reorder</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($lowStockItems === []): ?>
                            <tr>
                                <td colspan="5">No low-stock items found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($lowStockItems as $item): ?>
                            <tr>
                                <td><?php echo e($item['sku']); ?></td>
                                <td><?php echo e($item['name']); ?></td>
                                <td class="text-danger"><?php echo (int) $item['current_stock']; ?></td>
                                <td><?php echo (int) $item['reorder_level']; ?></td>
                                <td><?php echo e(format_money($item['stock_value'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel table-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Receivables</p>
                    <h2>Customer balances</h2>
                </div>
                <a class="muted-link" href="<?php echo e(app_url('?page=credit-sales')); ?>">Credit sales</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Invoices</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($creditCustomers === []): ?>
                            <tr>
                                <td colspan="4">No outstanding balances found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($creditCustomers as $customer): ?>
                            <tr>
                                <td><?php echo e($customer['customer_name']); ?></td>
                                <td><?php echo e($customer['phone'] ?? ''); ?></td>
                                <td><?php echo (int) $customer['open_invoices']; ?></td>
                                <td class="text-danger"><?php echo e(format_money($customer['balance'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </div>

    <div class="report-grid-two">
        <article class="panel table-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Returns</p>
                    <h2>Refund activity</h2>
                </div>
                <a class="muted-link" href="<?php echo e(app_url('?page=warranty-returns')); ?>">Warranty / Returns</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Return</th>
                            <th>Invoice</th>
                            <th>Customer</th>
                            <th>Refund</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($returnRows === []): ?>
                            <tr>
                                <td colspan="6">No returns found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($returnRows as $return): ?>
                            <tr>
                                <td><?php echo e(date('Y-m-d', strtotime((string) $return['return_date']))); ?></td>
                                <td><?php echo e($return['return_no']); ?></td>
                                <td><?php echo e($return['invoice_no']); ?></td>
                                <td><?php echo e($return['customer_name'] ?: 'Walk-in Customer'); ?></td>
                                <td><?php echo e(format_money($return['refund_amount'])); ?></td>
                                <td><span class="status status-active"><?php echo e(ucfirst((string) $return['status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel table-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Warranty</p>
                    <h2>RMA activity</h2>
                </div>
                <a class="muted-link" href="<?php echo e(app_url('?page=warranty-returns')); ?>">Warranty / Returns</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Received</th>
                            <th>Claim</th>
                            <th>Product</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Supplier Refund</th>
                            <th>Resolved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($warrantyRows === []): ?>
                            <tr>
                                <td colspan="7">No warranty claims found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($warrantyRows as $claim): ?>
                            <tr>
                                <td><?php echo e($claim['received_date']); ?></td>
                                <td><?php echo e($claim['claim_no']); ?></td>
                                <td>
                                    <strong class="table-title"><?php echo e($claim['sku']); ?></strong>
                                    <span class="table-subtitle"><?php echo e($claim['product_name']); ?></span>
                                </td>
                                <td><?php echo e($claim['customer_name'] ?: 'Walk-in Customer'); ?></td>
                                <td><span class="status <?php echo e(report_warranty_status_class((string) $claim['status'])); ?>"><?php echo e(report_warranty_status_label((string) $claim['status'])); ?></span></td>
                                <td>
                                    <?php echo e(format_money($claim['supplier_refund_amount'] ?? 0)); ?>
                                    <?php if (! empty($claim['supplier_refund_date'])): ?>
                                        <span class="table-subtitle"><?php echo e($claim['supplier_refund_date']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($claim['resolved_date'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </div>
</section>

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

function report_receivable_total(PDO $pdo): float
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

function report_warranty_status_label(string $status): string
{
    return match ($status) {
        'sent_to_supplier' => 'Supplier',
        'ready_for_pickup' => 'Ready',
        'resolved' => 'Resolved',
        'rejected' => 'Rejected',
        default => 'Received',
    };
}

function report_warranty_status_class(string $status): string
{
    return match ($status) {
        'resolved' => 'status-active',
        'sent_to_supplier' => 'status-warranty',
        'ready_for_pickup' => 'status-ready',
        'rejected' => 'status-pending',
        default => 'status-inactive',
    };
}
