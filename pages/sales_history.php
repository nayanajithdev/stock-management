<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$saleSearch = trim((string) ($_GET['q'] ?? ''));
$sales = [];

if ($dbReady && $pdo !== null) {
    $saleSql = 'SELECT s.*,
                       c.name AS customer_name,
                       c.phone AS customer_phone,
                       COUNT(si.id) AS item_count,
                       COALESCE(SUM(si.quantity), 0) AS total_units
                FROM sales s
                LEFT JOIN customers c ON c.id = s.customer_id
                LEFT JOIN sale_items si ON si.sale_id = s.id';
    $saleParams = [];

    if ($saleSearch !== '') {
        $saleSql .= ' WHERE s.invoice_no LIKE :search OR c.name LIKE :search OR c.phone LIKE :search';
        $saleParams['search'] = '%' . $saleSearch . '%';
    }

    $saleSql .= ' GROUP BY s.id ORDER BY s.sale_date DESC, s.id DESC LIMIT 50';
    $saleStatement = $pdo->prepare($saleSql);
    $saleStatement->execute($saleParams);
    $sales = $saleStatement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Sales History</p>
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

        <form class="filter-row" method="get" action="<?php echo e(app_url('')); ?>">
            <input type="hidden" name="page" value="sales-history">
            <input type="search" name="q" value="<?php echo e($saleSearch); ?>" placeholder="Invoice, customer, phone">
            <button class="icon-button" type="submit" aria-label="Search">
                <i data-lucide="search"></i>
            </button>
        </form>
    </div>

    <?php if (! $dbReady): ?>
        <p class="empty-state">Import <code>database/schema.sql</code> before viewing sales history.</p>
    <?php else: ?>
        <?php if ($saleSearch !== ''): ?>
            <p class="search-note">Showing sales matching <strong><?php echo e($saleSearch); ?></strong>.</p>
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
                            <td colspan="10">No sales recorded yet.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($sales as $sale): ?>
                        <?php $balance = (float) $sale['total'] - (float) $sale['paid']; ?>
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
                            <td><span class="status <?php echo e(sale_history_status_class((string) $sale['status'])); ?>"><?php echo e(ucfirst((string) $sale['status'])); ?></span></td>
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
    <?php endif; ?>
</section>

<?php
function sale_history_status_class(string $status): string
{
    return match ($status) {
        'paid' => 'status-active',
        'partial' => 'status-warranty',
        'credit' => 'status-pending',
        default => 'status-inactive',
    };
}
