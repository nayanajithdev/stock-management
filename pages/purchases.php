<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$suppliers = [];
$products = [];
$purchases = [];
$purchaseSearch = trim((string) ($_GET['q'] ?? ''));
$summary = [
    'month_total' => 0.0,
    'month_paid' => 0.0,
    'month_balance' => 0.0,
    'stock_in_units' => 0,
];

if ($dbReady && $pdo !== null) {
    $suppliers = app_fetch_options($pdo, 'suppliers');

    $products = $pdo->query(
        'SELECT id, sku, name, model, current_stock, cost_price
         FROM products
         WHERE status = "active"
         ORDER BY name ASC'
    )->fetchAll();

    $summaryStatement = $pdo->query(
        'SELECT
            COALESCE(SUM(total), 0) AS month_total,
            COALESCE(SUM(paid), 0) AS month_paid,
            COALESCE(SUM(total - paid), 0) AS month_balance
         FROM purchases
         WHERE purchase_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")
           AND purchase_date <= CURRENT_DATE'
    );
    $summaryRow = $summaryStatement->fetch() ?: [];
    $summary['month_total'] = (float) ($summaryRow['month_total'] ?? 0);
    $summary['month_paid'] = (float) ($summaryRow['month_paid'] ?? 0);
    $summary['month_balance'] = (float) ($summaryRow['month_balance'] ?? 0);
    $summary['stock_in_units'] = (int) $pdo->query(
        'SELECT COALESCE(SUM(quantity), 0)
         FROM purchase_items pi
         INNER JOIN purchases p ON p.id = pi.purchase_id
         WHERE p.purchase_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01")
           AND p.purchase_date <= CURRENT_DATE'
    )->fetchColumn();

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

    $purchaseSql .= ' GROUP BY p.id ORDER BY p.purchase_date DESC, p.id DESC LIMIT 20';
    $purchaseStatement = $pdo->prepare($purchaseSql);
    $purchaseStatement->execute($purchaseParams);
    $purchases = $purchaseStatement->fetchAll();
}

$productOptions = '';

foreach ($products as $product) {
    $label = $product['sku'] . ' - ' . $product['name'];
    if ((string) ($product['model'] ?? '') !== '') {
        $label .= ' (' . $product['model'] . ')';
    }

    $productOptions .= '<option value="' . (int) $product['id'] . '" data-cost="' . e($product['cost_price']) . '">'
        . e($label)
        . '</option>';
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Stock receiving</p>
        <h1>Purchases</h1>
    </div>
    <a class="top-action" href="#purchase-form">
        <i data-lucide="truck"></i>
        Receive Stock
    </a>
    <a class="top-action" href="<?php echo e(app_url('?page=supplier-credit')); ?>">
        <i data-lucide="hand-coins"></i>
        Supplier Credit
    </a>
</div>

<section class="stats-grid compact-stats" aria-label="Purchase summary">
    <article class="stat-card">
        <div>
            <span>This Month Purchases</span>
            <strong><?php echo e(format_money($summary['month_total'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="shopping-cart"></i></div>
        <small>Total stock-in value</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Paid</span>
            <strong><?php echo e(format_money($summary['month_paid'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="wallet-cards"></i></div>
        <small>Supplier payments</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Balance</span>
            <strong><?php echo e(format_money($summary['month_balance'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="receipt-text"></i></div>
        <small>Still payable</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Units Received</span>
            <strong><?php echo (int) $summary['stock_in_units']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="boxes"></i></div>
        <small>This month</small>
    </article>
</section>

<section class="purchase-layout">
    <article class="panel" id="purchase-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Purchase Entry</p>
                <h2>Receive supplier stock</h2>
            </div>
            <a class="muted-link" href="<?php echo e(app_url('?page=inventory-setup&section=suppliers')); ?>">Manage suppliers</a>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before receiving stock.</p>
        <?php elseif ($products === []): ?>
            <p class="empty-state">Add products first, then return here to receive stock.</p>
        <?php else: ?>
            <form class="purchase-form" method="post" action="<?php echo e(app_url('actions/purchase_save.php')); ?>" data-purchase-form>
                <?php echo csrf_field(); ?>

                <div class="purchase-meta">
                    <label class="field">
                        <span>Supplier</span>
                        <select name="supplier_id">
                            <option value="">No supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo (int) $supplier['id']; ?>"><?php echo e($supplier['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="field">
                        <span>Supplier Invoice</span>
                        <input type="text" name="invoice_no" placeholder="INV-2026-001">
                    </label>

                    <label class="field">
                        <span>Purchase Date</span>
                        <input type="date" name="purchase_date" value="<?php echo e(date('Y-m-d')); ?>" required>
                    </label>
                </div>

                <div class="purchase-items">
                    <div class="purchase-row purchase-head">
                        <span>Product</span>
                        <span>Qty</span>
                        <span>Unit Cost</span>
                        <span>Line Total</span>
                        <span></span>
                    </div>

                    <div data-purchase-rows>
                        <?php render_purchase_row($productOptions); ?>
                    </div>

                    <button class="ghost-button" type="button" data-add-purchase-row>
                        <i data-lucide="plus"></i>
                        Add Item
                    </button>
                </div>

                <div class="purchase-footer">
                    <div class="purchase-note">
                        <i data-lucide="info"></i>
                        <span>Saving this purchase increases stock and writes stock movement records for every item.</span>
                    </div>

                    <div class="purchase-totals">
                        <label>
                            <span>Subtotal</span>
                            <input type="text" value="0.00" data-purchase-subtotal readonly>
                        </label>
                        <label>
                            <span>Discount</span>
                            <input type="number" name="discount" value="0.00" min="0" step="0.01" data-purchase-discount>
                        </label>
                        <label>
                            <span>Total</span>
                            <input type="text" value="0.00" data-purchase-total readonly>
                        </label>
                        <label>
                            <span>Paid</span>
                            <input type="number" name="paid" value="0.00" min="0" step="0.01" data-purchase-paid>
                        </label>
                        <label>
                            <span>Balance</span>
                            <input type="text" value="0.00" data-purchase-balance readonly>
                        </label>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Purchase
                    </button>
                </div>
            </form>

            <template data-purchase-row-template>
                <?php render_purchase_row($productOptions); ?>
            </template>
        <?php endif; ?>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Purchase History</p>
                <h2>Recent stock received</h2>
            </div>
            <?php if ($purchaseSearch !== ''): ?>
                <a class="muted-link" href="<?php echo e(app_url('?page=purchases')); ?>">Clear search</a>
            <?php endif; ?>
        </div>

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
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($purchases === []): ?>
                        <tr>
                            <td colspan="10">No purchases recorded yet.</td>
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
                            <td><?php echo e(format_money($purchase['total'])); ?></td>
                            <td><?php echo e(format_money($purchase['paid'])); ?></td>
                            <td class="<?php echo $balance > 0 ? 'text-danger' : ''; ?>"><?php echo e(format_money($balance)); ?></td>
                            <td><span class="status <?php echo e(purchase_payment_status_class((string) $purchase['status'], $balance)); ?>"><?php echo e($balance > 0 ? ucfirst((string) $purchase['status']) : 'Closed'); ?></span></td>
                            <td>
                                <?php if ($balance > 0): ?>
                                    <a class="icon-button" href="<?php echo e(app_url('?page=supplier-credit&collect=' . (int) $purchase['id'] . '#supplier-payment-form')); ?>" aria-label="Pay supplier">
                                        <i data-lucide="hand-coins"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="muted-link">Closed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php
function render_purchase_row(string $productOptions): void
{
    ?>
    <div class="purchase-row" data-purchase-row>
        <label class="field compact-field">
            <span>Product</span>
            <select name="product_id[]" data-purchase-product required>
                <option value="">Choose product</option>
                <?php echo $productOptions; ?>
            </select>
        </label>
        <label class="field compact-field">
            <span>Qty</span>
            <input type="number" name="quantity[]" value="1" min="1" step="1" data-purchase-quantity required>
        </label>
        <label class="field compact-field">
            <span>Unit Cost</span>
            <input type="number" name="unit_cost[]" value="0.00" min="0" step="0.01" data-purchase-cost required>
        </label>
        <label class="field compact-field">
            <span>Line Total</span>
            <input type="text" value="0.00" data-purchase-line-total readonly>
        </label>
        <button class="icon-button danger-button" type="button" data-remove-purchase-row aria-label="Remove item">
            <i data-lucide="trash-2"></i>
        </button>
    </div>
    <?php
}

function purchase_payment_status_class(string $status, float $balance): string
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
