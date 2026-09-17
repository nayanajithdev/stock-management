<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var ?array $currentUser */

$purchaseSearch = trim((string) ($_GET['q'] ?? ''));
$purchasePageSize = 50;
$purchasePageNumber = max(1, (int) ($_GET['p'] ?? 1));
$purchaseTotal = 0;
$purchaseTotalPages = 1;
$purchases = [];
$canViewProductCost = $dbReady && $pdo instanceof PDO && auth_can_view_product_cost($pdo, $currentUser ?? null);
$purchaseHistoryColspan = $canViewProductCost ? 10 : 6;

if ($dbReady && $pdo !== null) {
    $purchaseParams = [];
    $purchaseWhere = '';

    if ($purchaseSearch !== '') {
        $purchaseWhere = ' WHERE p.invoice_no LIKE :search OR s.name LIKE :search';
        $purchaseParams['search'] = '%' . $purchaseSearch . '%';
    }

    $purchaseCountSql = 'SELECT COUNT(DISTINCT p.id)
                         FROM purchases p
                         LEFT JOIN suppliers s ON s.id = p.supplier_id' . $purchaseWhere;
    $purchaseCountStatement = $pdo->prepare($purchaseCountSql);
    $purchaseCountStatement->execute($purchaseParams);
    $purchaseTotal = (int) $purchaseCountStatement->fetchColumn();
    $purchaseTotalPages = max(1, (int) ceil($purchaseTotal / $purchasePageSize));
    $purchasePageNumber = min($purchasePageNumber, $purchaseTotalPages);
    $purchaseOffset = ($purchasePageNumber - 1) * $purchasePageSize;

    $purchaseSql = 'SELECT p.*,
                           s.name AS supplier_name,
                           COUNT(pi.id) AS item_count,
                           COALESCE(SUM(pi.quantity), 0) AS total_units
                    FROM purchases p
                    LEFT JOIN suppliers s ON s.id = p.supplier_id
                    LEFT JOIN purchase_items pi ON pi.purchase_id = p.id';

    $purchaseSql .= $purchaseWhere . ' GROUP BY p.id ORDER BY p.purchase_date DESC, p.id DESC LIMIT :limit OFFSET :offset';
    $purchaseStatement = $pdo->prepare($purchaseSql);
    foreach ($purchaseParams as $key => $value) {
        $purchaseStatement->bindValue(':' . $key, $value);
    }
    $purchaseStatement->bindValue(':limit', $purchasePageSize, PDO::PARAM_INT);
    $purchaseStatement->bindValue(':offset', $purchaseOffset, PDO::PARAM_INT);
    $purchaseStatement->execute();
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

        <form class="filter-row purchase-history-filter" method="get" action="<?php echo e(app_url('')); ?>">
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
                        <?php endif; ?>
                        <th>Action</th>
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
                            <?php endif; ?>
                            <td>
                                <div class="table-actions">
                                    <a class="icon-button" href="<?php echo e(app_url('?page=purchase-view&id=' . (int) $purchase['id'])); ?>" aria-label="View purchase invoice">
                                        <i data-lucide="eye"></i>
                                    </a>
                                    <?php if ($canViewProductCost && $balance > 0): ?>
                                        <a class="icon-button" href="<?php echo e(app_url('?page=supplier-credit&collect=' . (int) $purchase['id'] . '#supplier-payment-form')); ?>" aria-label="Pay supplier">
                                            <i data-lucide="hand-coins"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php render_purchase_history_pagination($purchasePageNumber, $purchaseTotalPages, $purchaseSearch); ?>
    <?php endif; ?>
</section>

<?php
function render_purchase_history_pagination(int $pageNumber, int $totalPages, string $purchaseSearch): void
{
    if ($totalPages <= 1) {
        return;
    }

    $previousQuery = purchase_history_page_query($pageNumber - 1, $purchaseSearch);
    $nextQuery = purchase_history_page_query($pageNumber + 1, $purchaseSearch);
    ?>
    <div class="pagination-row product-pagination" aria-label="Purchase history pages">
        <?php if ($pageNumber <= 1): ?>
            <span class="product-page-button disabled">Previous</span>
        <?php else: ?>
            <a class="product-page-button" href="<?php echo e(app_url('?' . $previousQuery)); ?>">Previous</a>
        <?php endif; ?>

        <?php foreach (purchase_history_pagination_pages($pageNumber, $totalPages) as $paginationPage): ?>
            <?php if ($paginationPage === 'ellipsis'): ?>
                <span class="product-page-ellipsis">...</span>
            <?php elseif ((int) $paginationPage === $pageNumber): ?>
                <span class="product-page-button active" aria-current="page"><?php echo (int) $paginationPage; ?></span>
            <?php else: ?>
                <a class="product-page-button" href="<?php echo e(app_url('?' . purchase_history_page_query((int) $paginationPage, $purchaseSearch))); ?>"><?php echo (int) $paginationPage; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($pageNumber >= $totalPages): ?>
            <span class="product-page-button disabled">Next</span>
        <?php else: ?>
            <a class="product-page-button" href="<?php echo e(app_url('?' . $nextQuery)); ?>">Next</a>
        <?php endif; ?>
    </div>
    <?php
}

function purchase_history_page_query(int $pageNumber, string $purchaseSearch): string
{
    $query = [
        'page' => 'purchase-history',
        'p' => max(1, $pageNumber),
    ];

    if ($purchaseSearch !== '') {
        $query['q'] = $purchaseSearch;
    }

    return http_build_query($query);
}

function purchase_history_pagination_pages(int $pageNumber, int $totalPages): array
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
