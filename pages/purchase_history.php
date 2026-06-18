<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var ?array $currentUser */

$purchaseSearch = trim((string) ($_GET['q'] ?? ''));
$purchases = [];
$canViewProductCost = $dbReady && $pdo instanceof PDO && auth_can_view_product_cost($pdo, $currentUser ?? null);
$purchaseHistoryColspan = $canViewProductCost ? 10 : 5;

if ($dbReady && $pdo !== null) {
    $purchaseSql = 'SELECT p.*,
                           s.name AS supplier_name,
                           COUNT(pi.id) AS item_count,
                           COALESCE(SUM(pi.quantity), 0) AS total_units
                    FROM purchases p
                    LEFT JOIN suppliers s ON s.id = p.supplier_id
                    LEFT JOIN purchase_items pi ON pi.purchase_id = p.id';
    $purchaseParams = [];

    if ($purchaseSearch !== '') {
        $purchaseSql .= ' WHERE p.invoice_no LIKE :search OR s.name LIKE :search';
        $purchaseParams['search'] = '%' . $purchaseSearch . '%';
    }

    $purchaseSql .= ' GROUP BY p.id ORDER BY p.purchase_date DESC, p.id DESC LIMIT 50';
    $purchaseStatement = $pdo->prepare($purchaseSql);
    $purchaseStatement->execute($purchaseParams);
    $purchases = $purchaseStatement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <h1>Stock received</h1>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=purchases')); ?>">
        <i data-lucide="arrow-left"></i>
        Purchases
    </a>
</div>

<section class="panel table-panel">
    <div class="panel-header">
        <div>
            <p class="panel-label">Purchase History</p>
            <h2>Recent stock received</h2>
        </div>

        <form class="filter-row" method="get" action="<?php echo e(app_url('')); ?>">
            <input type="hidden" name="page" value="purchase-history">
            <input type="search" name="q" value="<?php echo e($purchaseSearch); ?>" placeholder="Invoice or supplier">
            <button class="icon-button" type="submit" aria-label="Search">
                <i data-lucide="search"></i>
            </button>
        </form>
    </div>

    <?php if (! $dbReady): ?>
        <p class="empty-state">Import <code>database/schema.sql</code> before viewing stock history.</p>
    <?php else: ?>
        <?php if ($purchaseSearch !== ''): ?>
            <p class="search-note">Showing purchases matching <strong><?php echo e($purchaseSearch); ?></strong>.</p>
        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Supplier</th>
                        <th>Items</th>
                        <th>Units</th>
                        <?php if ($canViewProductCost): ?>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Action</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($purchases === []): ?>
                        <tr>
                            <td colspan="<?php echo $purchaseHistoryColspan; ?>">No purchases recorded yet.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($purchases as $purchase): ?>
                        <?php $balance = (float) $purchase['total'] - (float) $purchase['paid']; ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d', strtotime((string) $purchase['purchase_date']))); ?></td>
                            <td><?php echo e($purchase['invoice_no'] ?: 'No invoice'); ?></td>
                            <td><?php echo e($purchase['supplier_name'] ?: 'No supplier'); ?></td>
                            <td><?php echo (int) $purchase['item_count']; ?></td>
                            <td><?php echo (int) $purchase['total_units']; ?></td>
                            <?php if ($canViewProductCost): ?>
                                <td><?php echo e(format_money($purchase['total'])); ?></td>
                                <td><?php echo e(format_money($purchase['paid'])); ?></td>
                                <td class="<?php echo $balance > 0 ? 'text-danger' : ''; ?>"><?php echo e(format_money($balance)); ?></td>
                                <td><span class="status <?php echo e(purchase_history_payment_status_class((string) $purchase['status'], $balance)); ?>"><?php echo e($balance > 0 ? ucfirst((string) $purchase['status']) : 'Closed'); ?></span></td>
                                <td>
                                    <?php if ($balance > 0): ?>
                                        <a class="icon-button" href="<?php echo e(app_url('?page=supplier-credit&collect=' . (int) $purchase['id'] . '#supplier-payment-form')); ?>" aria-label="Pay supplier">
                                            <i data-lucide="hand-coins"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="muted-link">Closed</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
function purchase_history_payment_status_class(string $status, float $balance): string
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
