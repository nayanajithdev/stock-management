<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$returnSearch = trim((string) ($_GET['q'] ?? ''));
$returns = [];

if ($dbReady && $pdo !== null) {
    $returnSql = 'SELECT sr.*,
                         s.invoice_no,
                         c.name AS customer_name,
                         c.phone AS customer_phone,
                         COUNT(sri.id) AS item_count,
                         COALESCE(SUM(sri.quantity), 0) AS total_units,
                         COALESCE(SUM(CASE WHEN sri.restock = 1 THEN sri.quantity ELSE 0 END), 0) AS restocked_units
                  FROM sales_returns sr
                  INNER JOIN sales s ON s.id = sr.sale_id
                  LEFT JOIN customers c ON c.id = sr.customer_id
                  LEFT JOIN sales_return_items sri ON sri.return_id = sr.id';
    $returnParams = [];

    if ($returnSearch !== '') {
        $search = '%' . $returnSearch . '%';
        $returnSql .= ' WHERE sr.return_no LIKE :return_search
                           OR s.invoice_no LIKE :invoice_search
                           OR c.name LIKE :customer_search
                           OR c.phone LIKE :phone_search';
        $returnParams = [
            'return_search' => $search,
            'invoice_search' => $search,
            'customer_search' => $search,
            'phone_search' => $search,
        ];
    }

    $returnSql .= ' GROUP BY sr.id
                    ORDER BY sr.return_date DESC, sr.id DESC
                    LIMIT 100';
    $returnStatement = $pdo->prepare($returnSql);
    $returnStatement->execute($returnParams);
    $returns = $returnStatement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <h1>Recent Returns</h1>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=warranty-returns')); ?>">
        <i data-lucide="arrow-left"></i>
        Warranty / Returns
    </a>
</div>

<section class="panel table-panel">
    <div class="panel-header">
        <div>
            <h2>Return history</h2>
            <p class="modal-subtitle">Completed customer returns and refund records.</p>
        </div>

        <form class="filter-row movement-filter" method="get" action="<?php echo e(app_url('')); ?>">
            <input type="hidden" name="page" value="recent-returns">
            <input type="search" name="q" value="<?php echo e($returnSearch); ?>" placeholder="Return, invoice, or customer">
            <button class="icon-button" type="submit" aria-label="Search returns">
                <i data-lucide="search"></i>
            </button>
        </form>
    </div>

    <?php if (! $dbReady): ?>
        <p class="empty-state">Import <code>database/schema.sql</code> before viewing return records.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Return</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Units</th>
                        <th>Restocked</th>
                        <th>Refund</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($returns === []): ?>
                        <tr><td colspan="8">No returns found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($returns as $return): ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $return['return_date']))); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($return['return_no']); ?></strong>
                                <span class="table-subtitle"><?php echo (string) $return['status'] === 'exchange' ? 'Exchange' : 'Return / refund'; ?></span>
                            </td>
                            <td><?php echo e($return['invoice_no']); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($return['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                                <span class="table-subtitle"><?php echo e($return['customer_phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo (int) $return['total_units']; ?></td>
                            <td><?php echo (int) $return['restocked_units']; ?></td>
                            <td><?php echo e(format_money($return['refund_amount'])); ?></td>
                            <td>
                                <a class="icon-button" href="<?php echo e(app_url('?page=sale-view&id=' . (int) $return['sale_id'])); ?>" aria-label="View invoice">
                                    <i data-lucide="eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
