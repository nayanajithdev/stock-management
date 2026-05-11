<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$returnSearch = trim((string) ($_GET['q'] ?? ''));
$selectedSaleItemId = (int) ($_GET['sale_item'] ?? 0);
$returnableItems = [];
$returns = [];
$summary = [
    'month_returns' => 0,
    'month_refunds' => 0.0,
    'month_units' => 0,
    'restocked_units' => 0,
];

if ($dbReady && $pdo !== null) {
    $summaryStatement = $pdo->query(
        'SELECT COUNT(DISTINCT sr.id) AS month_returns,
                COALESCE(SUM(sr.refund_amount), 0) AS month_refunds
         FROM sales_returns sr
         WHERE sr.return_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    );
    $summaryRow = $summaryStatement->fetch() ?: [];
    $summary['month_returns'] = (int) ($summaryRow['month_returns'] ?? 0);
    $summary['month_refunds'] = (float) ($summaryRow['month_refunds'] ?? 0);
    $summary['month_units'] = (int) $pdo->query(
        'SELECT COALESCE(SUM(sri.quantity), 0)
         FROM sales_return_items sri
         INNER JOIN sales_returns sr ON sr.id = sri.return_id
         WHERE sr.return_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    )->fetchColumn();
    $summary['restocked_units'] = (int) $pdo->query(
        'SELECT COALESCE(SUM(sri.quantity), 0)
         FROM sales_return_items sri
         INNER JOIN sales_returns sr ON sr.id = sri.return_id
         WHERE sri.restock = 1
           AND sr.return_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")'
    )->fetchColumn();

    $returnableStatement = $pdo->query(
        'SELECT si.id AS sale_item_id,
                si.sale_id,
                si.product_id,
                si.quantity AS sold_quantity,
                si.unit_price,
                si.unit_cost,
                s.invoice_no,
                s.sale_date,
                c.name AS customer_name,
                c.phone AS customer_phone,
                p.sku,
                p.name AS product_name,
                p.model,
                COALESCE(SUM(sri.quantity), 0) AS returned_quantity,
                si.quantity - COALESCE(SUM(sri.quantity), 0) AS available_quantity
         FROM sale_items si
         INNER JOIN sales s ON s.id = si.sale_id
         LEFT JOIN customers c ON c.id = s.customer_id
         INNER JOIN products p ON p.id = si.product_id
         LEFT JOIN sales_return_items sri ON sri.sale_item_id = si.id
         GROUP BY si.id
         HAVING available_quantity > 0
         ORDER BY s.sale_date DESC, si.id DESC'
    );
    $returnableItems = $returnableStatement->fetchAll();

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
        $returnSql .= ' WHERE sr.return_no LIKE :search OR s.invoice_no LIKE :search OR c.name LIKE :search OR c.phone LIKE :search';
        $returnParams['search'] = '%' . $returnSearch . '%';
    }

    $returnSql .= ' GROUP BY sr.id ORDER BY sr.return_date DESC, sr.id DESC LIMIT 50';
    $returnStatement = $pdo->prepare($returnSql);
    $returnStatement->execute($returnParams);
    $returns = $returnStatement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Customer returns</p>
        <h1>Returns</h1>
    </div>
    <a class="top-action" href="#sales-return-form">
        <i data-lucide="rotate-ccw"></i>
        New Return
    </a>
</div>

<section class="stats-grid compact-stats" aria-label="Returns summary">
    <article class="stat-card">
        <div>
            <span>This Month Returns</span>
            <strong><?php echo (int) $summary['month_returns']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="rotate-ccw"></i></div>
        <small>Completed return records</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Refunds</span>
            <strong><?php echo e(format_money($summary['month_refunds'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="wallet"></i></div>
        <small>This month</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Returned Units</span>
            <strong><?php echo (int) $summary['month_units']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="package-open"></i></div>
        <small>All conditions</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Restocked Units</span>
            <strong><?php echo (int) $summary['restocked_units']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="boxes"></i></div>
        <small>Returned to inventory</small>
    </article>
</section>

<section class="return-layout">
    <article class="panel" id="sales-return-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Return Entry</p>
                <h2>Process sales return</h2>
            </div>
            <a class="muted-link" href="<?php echo e(app_url('?page=sales')); ?>">Sales history</a>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before saving returns.</p>
        <?php elseif ($returnableItems === []): ?>
            <p class="empty-state">No sold items are available to return yet.</p>
        <?php else: ?>
            <form class="return-form" method="post" action="<?php echo e(app_url('actions/sales_return_save.php')); ?>" data-return-form>
                <?php echo csrf_field(); ?>

                <label class="field span-2">
                    <span>Sold Item</span>
                    <select name="sale_item_id" data-return-item required>
                        <option value="">Choose invoice item</option>
                        <?php foreach ($returnableItems as $item): ?>
                            <?php
                            $label = $item['invoice_no'] . ' / ' . ($item['customer_name'] ?: 'Walk-in Customer') . ' / ' . $item['sku'] . ' - ' . $item['product_name'] . ' / Available ' . (int) $item['available_quantity'];
                            ?>
                            <option
                                value="<?php echo (int) $item['sale_item_id']; ?>"
                                data-available="<?php echo (int) $item['available_quantity']; ?>"
                                data-price="<?php echo e($item['unit_price']); ?>"
                                <?php echo $selectedSaleItemId === (int) $item['sale_item_id'] ? 'selected' : ''; ?>
                            >
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="collection-balance">
                    <span>Available to Return</span>
                    <strong data-return-available>Choose item</strong>
                </div>

                <label class="field">
                    <span>Quantity</span>
                    <input type="number" name="quantity" value="1" min="1" step="1" data-return-quantity required>
                </label>

                <label class="field">
                    <span>Return Date</span>
                    <input type="datetime-local" name="return_date" value="<?php echo e(date('Y-m-d\TH:i')); ?>" required>
                </label>

                <label class="field">
                    <span>Condition</span>
                    <select name="condition_status">
                        <option value="resellable">Resellable</option>
                        <option value="opened">Opened / Checked</option>
                        <option value="damaged">Damaged</option>
                        <option value="warranty">Warranty Claim</option>
                    </select>
                </label>

                <label class="field">
                    <span>Refund Method</span>
                    <select name="refund_method">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="store_credit">Store Credit</option>
                        <option value="none">No Refund</option>
                    </select>
                </label>

                <label class="field">
                    <span>Refund Amount</span>
                    <input type="number" name="refund_amount" value="0.00" min="0" step="0.01" data-return-refund required>
                </label>

                <label class="toggle-row">
                    <input type="checkbox" name="restock" value="1" checked>
                    <span>Return item to available stock</span>
                </label>

                <label class="field span-2">
                    <span>Reason / Notes</span>
                    <textarea name="notes" rows="3" placeholder="Required return reason" required></textarea>
                </label>

                <div class="collection-preview span-2">
                    <i data-lucide="activity"></i>
                    <span data-return-preview>Select an item to preview the return.</span>
                </div>

                <div class="form-actions span-2">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Return
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Return History</p>
                <h2>Completed returns</h2>
            </div>

            <form class="filter-row" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="returns">
                <input type="search" name="q" value="<?php echo e($returnSearch); ?>" placeholder="Return, invoice, customer">
                <button class="icon-button" type="submit" aria-label="Search returns">
                    <i data-lucide="search"></i>
                </button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Return</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Units</th>
                        <th>Restocked</th>
                        <th>Refund</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($returns === []): ?>
                        <tr>
                            <td colspan="9">No returns recorded yet.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($returns as $return): ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $return['return_date']))); ?></td>
                            <td><?php echo e($return['return_no']); ?></td>
                            <td><?php echo e($return['invoice_no']); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($return['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                                <span class="table-subtitle"><?php echo e($return['customer_phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo (int) $return['item_count']; ?></td>
                            <td><?php echo (int) $return['total_units']; ?></td>
                            <td><?php echo (int) $return['restocked_units']; ?></td>
                            <td><?php echo e(format_money($return['refund_amount'])); ?></td>
                            <td><span class="status status-active"><?php echo e(ucfirst((string) $return['status'])); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
