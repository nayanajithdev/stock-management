<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$hasProducts = false;
$summary = [
    'month_total' => 0.0,
    'month_paid' => 0.0,
    'month_balance' => 0.0,
    'stock_in_units' => 0,
];

if ($dbReady && $pdo !== null) {
    $hasProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active"')->fetchColumn() > 0;

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

}
?>

<div class="page-heading">
    <div>
        <h1>Purchases</h1>
    </div>
    <div class="heading-actions">
        <a class="top-action" href="<?php echo e(app_url('?page=purchase-history')); ?>">
            <i data-lucide="history"></i>
            View Stock History
        </a>
        <a class="top-action" href="<?php echo e(app_url('?page=supplier-credit')); ?>">
            <i data-lucide="hand-coins"></i>
            Supplier Credit
        </a>
    </div>
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
            <span>Balance</span>
            <strong><?php echo e(format_money($summary['month_balance'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="receipt-text"></i></div>
        <small>Still payable</small>
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
        <?php elseif (! $hasProducts): ?>
            <p class="empty-state">Add products first, then return here to receive stock.</p>
        <?php else: ?>
            <form class="purchase-form" method="post" action="<?php echo e(app_url('actions/purchase_save.php')); ?>" data-purchase-form data-product-search-url="<?php echo e(app_url('actions/product_search.php')); ?>" data-supplier-search-url="<?php echo e(app_url('actions/supplier_search.php')); ?>">
                <?php echo csrf_field(); ?>

                <div class="purchase-meta">
                    <label class="field product-picker supplier-picker" data-supplier-picker>
                        <span>Supplier</span>
                        <input type="hidden" name="supplier_id" data-purchase-supplier>
                        <input type="search" placeholder="No supplier or search supplier" autocomplete="off" data-supplier-search>
                        <div class="product-suggestions" data-supplier-suggestions hidden></div>
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
                        <span>Warranty</span>
                        <span>Qty</span>
                        <span>Unit Cost</span>
                        <span>Line Total</span>
                        <span></span>
                    </div>

                    <div data-purchase-rows>
                        <?php render_purchase_row(); ?>
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
                <?php render_purchase_row(); ?>
            </template>
        <?php endif; ?>
    </article>

</section>

<?php
function render_purchase_row(): void
{
    ?>
    <div class="purchase-row" data-purchase-row>
        <div class="field compact-field product-picker" data-product-picker>
            <span>Product</span>
            <input type="hidden" name="product_id[]" data-purchase-product>
            <input type="search" placeholder="Search product, SKU, barcode" autocomplete="off" data-product-search>
            <div class="product-suggestions" data-product-suggestions hidden></div>
        </div>
        <label class="field compact-field">
            <span>Warranty Months</span>
            <input type="number" name="warranty_months[]" value="0" min="0" step="1" data-purchase-warranty required>
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
