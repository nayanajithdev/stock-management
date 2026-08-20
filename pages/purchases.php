<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var ?array $currentUser */

$hasProducts = false;
$canViewProductCost = $dbReady && $pdo instanceof PDO && auth_can_view_product_cost($pdo, $currentUser ?? null);
$purchaseOldInput = purchases_form_pull_old_input($dbReady && $pdo instanceof PDO ? $pdo : null);
$purchaseRows = $purchaseOldInput['rows'] ?? [[]];
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
        <?php if ($canViewProductCost): ?>
            <a class="top-action" href="<?php echo e(app_url('?page=supplier-credit')); ?>">
                <i data-lucide="hand-coins"></i>
                Supplier Credit
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="stats-grid compact-stats" aria-label="Purchase summary">
    <?php if ($canViewProductCost): ?>
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
    <?php endif; ?>
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
        <?php elseif (! $canViewProductCost): ?>
            <p class="empty-state">Product Cost permission is required to receive supplier stock because purchases set stock lot costs and supplier balances.</p>
        <?php elseif (! $hasProducts): ?>
            <p class="empty-state">Add products first, then return here to receive stock.</p>
        <?php else: ?>
            <form class="purchase-form" method="post" action="<?php echo e(app_url('actions/purchase_save.php')); ?>" data-purchase-form data-product-search-url="<?php echo e(app_url('actions/product_search.php')); ?>" data-supplier-search-url="<?php echo e(app_url('actions/supplier_search.php')); ?>">
                <?php echo csrf_field(); ?>

                <div class="purchase-meta">
                    <label class="field product-picker supplier-picker" data-supplier-picker>
                        <span>Supplier</span>
                        <input type="hidden" name="supplier_id" value="<?php echo e($purchaseOldInput['supplier_id'] ?? ''); ?>" data-purchase-supplier>
                        <input type="search" name="supplier_search" value="<?php echo e($purchaseOldInput['supplier_search'] ?? ''); ?>" placeholder="No supplier or search supplier" autocomplete="off" data-supplier-search>
                        <div class="product-suggestions" data-supplier-suggestions hidden></div>
                    </label>

                    <label class="field">
                        <span>Supplier Invoice</span>
                        <input type="text" name="invoice_no" value="<?php echo e($purchaseOldInput['invoice_no'] ?? ''); ?>" placeholder="INV-2026-001">
                    </label>

                    <label class="field">
                        <span>Purchase Date</span>
                        <input type="date" name="purchase_date" value="<?php echo e($purchaseOldInput['purchase_date'] ?? date('Y-m-d')); ?>" required>
                    </label>
                </div>

                <div class="purchase-items">
                    <div class="purchase-row purchase-head">
                        <span>Product</span>
                        <span>Warranty</span>
                        <span>Qty</span>
                        <span>Unit Cost</span>
                        <span>Line Total</span>
                        <span>Sell Price</span>
                        <span></span>
                    </div>

                    <div data-purchase-rows>
                        <?php foreach ($purchaseRows as $purchaseRow): ?>
                            <?php render_purchase_row($purchaseRow); ?>
                        <?php endforeach; ?>
                    </div>

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
                            <input type="number" name="discount" value="<?php echo e($purchaseOldInput['discount'] ?? '0.00'); ?>" min="0" step="0.01" data-purchase-discount>
                        </label>
                        <label>
                            <span>Total</span>
                            <input type="text" value="0.00" data-purchase-total readonly>
                        </label>
                        <label>
                            <span>Paid</span>
                            <input type="number" name="paid" value="<?php echo e($purchaseOldInput['paid'] ?? '0.00'); ?>" min="0" step="0.01" data-purchase-paid>
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
function purchases_form_pull_old_input(?PDO $pdo): array
{
    $oldInput = $_SESSION['purchase_form_old'] ?? null;
    unset($_SESSION['purchase_form_old']);

    if (! is_array($oldInput)) {
        return [];
    }

    return purchases_form_normalize_old_input($oldInput, $pdo);
}

function purchases_form_normalize_old_input(array $oldInput, ?PDO $pdo): array
{
    $supplierId = trim((string) ($oldInput['supplier_id'] ?? ''));
    $supplierSearch = trim((string) ($oldInput['supplier_search'] ?? ''));

    if ($supplierId !== '' && $pdo instanceof PDO) {
        $supplierStatement = $pdo->prepare('SELECT name FROM suppliers WHERE id = :id LIMIT 1');
        $supplierStatement->execute(['id' => (int) $supplierId]);
        $supplier = $supplierStatement->fetch();

        if (is_array($supplier) && $supplierSearch === '') {
            $supplierSearch = (string) $supplier['name'];
        }
    }

    $rows = purchases_form_normalize_old_rows($oldInput, $pdo);

    return [
        'supplier_id' => $supplierId,
        'supplier_search' => $supplierSearch,
        'invoice_no' => trim((string) ($oldInput['invoice_no'] ?? '')),
        'purchase_date' => purchases_form_date_value((string) ($oldInput['purchase_date'] ?? '')),
        'discount' => purchases_form_money_value($oldInput['discount'] ?? '0.00'),
        'paid' => purchases_form_money_value($oldInput['paid'] ?? '0.00'),
        'rows' => $rows === [] ? [[]] : $rows,
    ];
}

function purchases_form_normalize_old_rows(array $oldInput, ?PDO $pdo): array
{
    $productIds = is_array($oldInput['product_id'] ?? null) ? $oldInput['product_id'] : [];
    $productSearches = is_array($oldInput['product_search'] ?? null) ? $oldInput['product_search'] : [];
    $warrantyMonths = is_array($oldInput['warranty_months'] ?? null) ? $oldInput['warranty_months'] : [];
    $quantities = is_array($oldInput['quantity'] ?? null) ? $oldInput['quantity'] : [];
    $unitCosts = is_array($oldInput['unit_cost'] ?? null) ? $oldInput['unit_cost'] : [];
    $sellingPrices = is_array($oldInput['selling_price'] ?? null) ? $oldInput['selling_price'] : [];
    $productDetails = purchases_form_product_details($productIds, $pdo);
    $rowCount = max(count($productIds), count($productSearches), count($warrantyMonths), count($quantities), count($unitCosts), count($sellingPrices), 1);
    $rows = [];

    for ($index = 0; $index < $rowCount; $index++) {
        $productId = max(0, (int) ($productIds[$index] ?? 0));
        $product = $productDetails[$productId] ?? null;
        $productSearch = trim((string) ($productSearches[$index] ?? ''));

        if (is_array($product)) {
            $productSearch = $product['label'];
        }

        $row = [
            'product_id' => $productId > 0 ? (string) $productId : '',
            'product_search' => $productSearch,
            'warranty_months' => max(0, (int) ($warrantyMonths[$index] ?? (is_array($product) ? $product['warranty_months'] : 0))),
            'quantity' => max(1, (int) ($quantities[$index] ?? 1)),
            'unit_cost' => purchases_form_money_value($unitCosts[$index] ?? (is_array($product) ? $product['cost'] : '0.00')),
            'selling_price' => purchases_form_money_value($sellingPrices[$index] ?? (is_array($product) ? $product['price'] : '0.00')),
        ];

        if ($row['product_id'] === '' && $row['product_search'] === '' && $row['unit_cost'] === '0.00' && $row['selling_price'] === '0.00') {
            if ($index > 0) {
                continue;
            }
        }

        $rows[] = $row;
    }

    return $rows;
}

function purchases_form_product_details(array $productIds, ?PDO $pdo): array
{
    if (! $pdo instanceof PDO) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));

    if ($ids === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare(
        'SELECT id, sku, name, model, cost_price, selling_price, warranty_months
         FROM products
         WHERE id IN (' . $placeholders . ')'
    );
    $statement->execute($ids);
    $products = [];

    foreach ($statement->fetchAll() as $product) {
        $model = trim((string) ($product['model'] ?? ''));
        $label = (string) $product['sku'] . ' - ' . (string) $product['name'];

        if ($model !== '') {
            $label .= ' (' . $model . ')';
        }

        $products[(int) $product['id']] = [
            'label' => $label,
            'cost' => (float) $product['cost_price'],
            'price' => (float) $product['selling_price'],
            'warranty_months' => (int) $product['warranty_months'],
        ];
    }

    return $products;
}

function purchases_form_date_value(string $value): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : date('Y-m-d');
}

function purchases_form_money_value(mixed $value): string
{
    $value = str_replace(',', '', trim((string) $value));
    $amount = is_numeric($value) ? max(0.0, (float) $value) : 0.0;

    return number_format($amount, 2, '.', '');
}

function render_purchase_row(array $row = []): void
{
    $productId = (string) ($row['product_id'] ?? '');
    $productSearch = (string) ($row['product_search'] ?? '');
    $warrantyMonths = max(0, (int) ($row['warranty_months'] ?? 0));
    $quantity = max(1, (int) ($row['quantity'] ?? 1));
    $unitCost = purchases_form_money_value($row['unit_cost'] ?? '0.00');
    $sellingPrice = purchases_form_money_value($row['selling_price'] ?? '0.00');
    ?>
    <div class="purchase-row" data-purchase-row>
        <div class="field compact-field product-picker" data-product-picker>
            <span>Product</span>
            <input type="hidden" name="product_id[]" value="<?php echo e($productId); ?>" data-purchase-product>
            <input type="search" name="product_search[]" value="<?php echo e($productSearch); ?>" placeholder="Search product, SKU, barcode or @category" autocomplete="off" data-product-search>
            <div class="product-suggestions" data-product-suggestions hidden></div>
        </div>
        <label class="field compact-field">
            <span>Warranty Months</span>
            <input type="number" name="warranty_months[]" value="<?php echo e($warrantyMonths); ?>" min="0" step="1" data-purchase-warranty required>
        </label>
        <label class="field compact-field">
            <span>Qty</span>
            <input type="number" name="quantity[]" value="<?php echo e($quantity); ?>" min="1" step="1" data-purchase-quantity required>
        </label>
        <label class="field compact-field">
            <span>Unit Cost</span>
            <input type="number" name="unit_cost[]" value="<?php echo e($unitCost); ?>" min="0" step="0.01" data-purchase-cost required>
        </label>
        <label class="field compact-field">
            <span>Line Total</span>
            <input type="text" value="0.00" data-purchase-line-total readonly>
        </label>
        <label class="field compact-field">
            <span>Sell Price</span>
            <input type="number" name="selling_price[]" value="<?php echo e($sellingPrice); ?>" min="0" step="0.01" data-purchase-selling-price required>
        </label>
        <button class="icon-button danger-button" type="button" data-remove-purchase-row aria-label="Remove item">
            <i data-lucide="trash-2"></i>
        </button>
    </div>
    <?php
}
