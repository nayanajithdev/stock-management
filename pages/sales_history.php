<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$saleSearch = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['sale_status'] ?? 'all');
$startDate = sale_history_valid_date((string) ($_GET['start_date'] ?? ''));
$endDate = sale_history_valid_date((string) ($_GET['end_date'] ?? ''));
$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($pageNumber - 1) * $perPage;
$sales = [];
$totalSales = 0;
$totalPages = 1;
$saleStatusOptions = [
    'all' => 'All invoices',
    'open' => 'Open balance',
    'closed' => 'Closed',
    'paid' => 'Paid',
    'partial' => 'Partial',
    'credit' => 'Credit',
];

if (! isset($saleStatusOptions[$statusFilter])) {
    $statusFilter = 'all';
}

if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

if ($dbReady && $pdo !== null) {
    $returnJoin = sale_history_return_join_sql();
    $balanceSql = sale_history_balance_sql();
    $where = [];
    $saleParams = [];

    if ($saleSearch !== '') {
        $where[] = '(s.invoice_no LIKE :search OR c.name LIKE :search OR c.phone LIKE :search OR c.email LIKE :search)';
        $saleParams['search'] = '%' . $saleSearch . '%';
    }

    if ($startDate !== '') {
        $where[] = 's.sale_date >= :start_date';
        $saleParams['start_date'] = $startDate . ' 00:00:00';
    }

    if ($endDate !== '') {
        $where[] = 's.sale_date <= :end_date';
        $saleParams['end_date'] = $endDate . ' 23:59:59';
    }

    if ($statusFilter === 'open') {
        $where[] = $balanceSql . ' > 0';
    } elseif ($statusFilter === 'closed') {
        $where[] = $balanceSql . ' <= 0';
    } elseif (in_array($statusFilter, ['paid', 'partial', 'credit'], true)) {
        $where[] = 's.status = :sale_status';
        $saleParams['sale_status'] = $statusFilter;
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

    $countStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         ' . $returnJoin .
         $whereSql
    );
    sale_history_bind_params($countStatement, $saleParams);
    $countStatement->execute();
    $totalSales = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalSales / $perPage));

    if ($pageNumber > $totalPages) {
        $pageNumber = $totalPages;
        $offset = ($pageNumber - 1) * $perPage;
    }

    $saleSql = 'SELECT s.*,
                       c.name AS customer_name,
                       c.phone AS customer_phone,
                       COUNT(si.id) AS item_count,
                       COALESCE(SUM(si.quantity), 0) AS total_units,
                       ' . $balanceSql . ' AS balance
                FROM sales s
                LEFT JOIN customers c ON c.id = s.customer_id
                LEFT JOIN sale_items si ON si.sale_id = s.id
                ' . $returnJoin;

    $saleSql .= $whereSql . ' GROUP BY s.id ORDER BY s.sale_date DESC, s.id DESC LIMIT :limit OFFSET :offset';
    $saleStatement = $pdo->prepare($saleSql);
    sale_history_bind_params($saleStatement, $saleParams);
    $saleStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $saleStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $saleStatement->execute();
    $sales = $saleStatement->fetchAll();
}

$hasFilters = $saleSearch !== '' || $statusFilter !== 'all' || $startDate !== '' || $endDate !== '';
?>

<div class="page-heading">
    <div>
        <h1>Recent invoices</h1>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=sales')); ?>">
        <i data-lucide="arrow-left"></i>
        Sales
    </a>
</div>

<section class="panel table-panel">
    <div class="panel-header">
        <div>
            <p class="panel-label">Invoices</p>
            <h2>Sales history</h2>
        </div>
        <span class="dashboard-pill"><?php echo (int) $totalSales; ?> invoice(s)</span>
    </div>

    <?php if (! $dbReady): ?>
        <p class="empty-state">Import <code>database/schema.sql</code> before viewing sales history.</p>
    <?php else: ?>
        <form class="sales-history-filter" method="get" action="<?php echo e(app_url('')); ?>">
            <input type="hidden" name="page" value="sales-history">
            <label class="field">
                <span>Search</span>
                <input type="search" name="q" value="<?php echo e($saleSearch); ?>" placeholder="Invoice, customer, phone">
            </label>
            <label class="field">
                <span>Status</span>
                <select name="sale_status">
                    <?php foreach ($saleStatusOptions as $statusKey => $statusLabel): ?>
                        <option value="<?php echo e($statusKey); ?>" <?php echo $statusFilter === $statusKey ? 'selected' : ''; ?>>
                            <?php echo e($statusLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>From</span>
                <input type="date" name="start_date" value="<?php echo e($startDate); ?>">
            </label>
            <label class="field">
                <span>To</span>
                <input type="date" name="end_date" value="<?php echo e($endDate); ?>">
            </label>
            <button class="top-action" type="submit">
                <i data-lucide="search"></i>
                Filter
            </button>
            <?php if ($hasFilters): ?>
                <a class="ghost-button" href="<?php echo e(app_url('?page=sales-history')); ?>">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($hasFilters): ?>
            <p class="search-note">
                Showing filtered sales.
                <?php if ($saleSearch !== ''): ?>
                    Search: <strong><?php echo e($saleSearch); ?></strong>.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Units</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sales === []): ?>
                        <tr>
                            <td colspan="10"><?php echo $hasFilters ? 'No sales found for these filters.' : 'No sales recorded yet.'; ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($sales as $sale): ?>
                        <?php $balance = (float) $sale['balance']; ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $sale['sale_date']))); ?></td>
                            <td>
                                <a class="table-title" href="<?php echo e(app_url('?page=sale-view&id=' . (int) $sale['id'])); ?>"><?php echo e($sale['invoice_no']); ?></a>
                            </td>
                            <td>
                                <strong class="table-title"><?php echo e($sale['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                                <span class="table-subtitle"><?php echo e($sale['customer_phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo (int) $sale['item_count']; ?></td>
                            <td><?php echo (int) $sale['total_units']; ?></td>
                            <td><?php echo e(format_money($sale['total'])); ?></td>
                            <td><?php echo e(format_money($sale['paid'])); ?></td>
                            <td class="<?php echo $balance > 0 ? 'text-danger' : ''; ?>"><?php echo e(format_money($balance)); ?></td>
                            <td><span class="status <?php echo e(sale_history_status_class((string) $sale['status'], $balance)); ?>"><?php echo e($balance > 0 ? ucfirst((string) $sale['status']) : 'Closed'); ?></span></td>
                            <td>
                                <a class="icon-button" href="<?php echo e(app_url('?page=sale-view&id=' . (int) $sale['id'])); ?>" aria-label="View invoice">
                                    <i data-lucide="file-text"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination-row">
                <?php $previousQuery = sale_history_page_query($pageNumber - 1); ?>
                <?php $nextQuery = sale_history_page_query($pageNumber + 1); ?>
                <a class="ghost-button <?php echo $pageNumber <= 1 ? 'disabled' : ''; ?>" href="<?php echo e($pageNumber <= 1 ? '#' : app_url('?' . $previousQuery)); ?>">Previous</a>
                <span>Page <?php echo (int) $pageNumber; ?> of <?php echo (int) $totalPages; ?></span>
                <a class="ghost-button <?php echo $pageNumber >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo e($pageNumber >= $totalPages ? '#' : app_url('?' . $nextQuery)); ?>">Next</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php
function sale_history_valid_date(string $date): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
}

function sale_history_bind_params(PDOStatement $statement, array $params): void
{
    foreach ($params as $key => $value) {
        $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
}

function sale_history_page_query(int $pageNumber): string
{
    $query = $_GET;
    $query['page'] = 'sales-history';
    $query['p'] = max(1, $pageNumber);

    return http_build_query($query);
}

function sale_history_return_join_sql(): string
{
    return 'LEFT JOIN (
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
            ) ret ON ret.sale_id = s.id';
}

function sale_history_balance_sql(): string
{
    return 'GREATEST(s.total - s.paid - COALESCE(ret.returned_total, 0) + COALESCE(ret.refund_total, 0), 0)';
}

function sale_history_status_class(string $status, float $balance): string
{
    if ($balance <= 0.0) {
        return 'status-active';
    }

    return match ($status) {
        'paid' => 'status-active',
        'partial' => 'status-warranty',
        'credit' => 'status-pending',
        default => 'status-inactive',
    };
}
