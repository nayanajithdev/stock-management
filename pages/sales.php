<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$hasSaleProducts = false;
$saleOldInput = sales_form_pull_old_input($dbReady && $pdo instanceof PDO ? $pdo : null);
$saleRows = $saleOldInput['rows'] ?? [[]];

if ($dbReady && $pdo !== null) {
    $hasSaleProducts = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM products
         WHERE status = "active"
           AND current_stock > 0'
    )->fetchColumn() > 0;
}
?>

<div class="page-heading">
    <div>
        <h1>Sales</h1>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=sales-history')); ?>">
        <i data-lucide="file-text"></i>
        Sales History
    </a>
</div>

<section class="sales-layout">
    <article class="panel" id="sales-pos-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Checkout</p>
                <h2>Create invoice</h2>
            </div>
            <a class="muted-link" href="<?php echo e(app_url('?page=products')); ?>">Manage products</a>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before selling.</p>
        <?php elseif (! $hasSaleProducts): ?>
            <p class="empty-state">Add products and stock before creating invoices.</p>
        <?php else: ?>
            <form class="sale-form" method="post" action="<?php echo e(app_url('actions/sale_save.php')); ?>" data-sale-form data-sale-product-search-url="<?php echo e(app_url('actions/sale_product_search.php')); ?>" data-sale-customer-search-url="<?php echo e(app_url('actions/customer_search.php')); ?>" <?php echo $saleOldInput !== [] ? 'data-sale-preserve-paid="1"' : ''; ?>>
                <?php echo csrf_field(); ?>

                <div class="sale-meta">
                    <div class="field product-picker" data-sale-customer-picker>
                        <span>Customer</span>
                        <input type="hidden" name="customer_id" value="<?php echo e($saleOldInput['customer_id'] ?? ''); ?>" data-sale-customer>
                        <input type="search" name="customer_name" value="<?php echo e($saleOldInput['customer_name'] ?? ''); ?>" placeholder="Search customer, phone, email or type new customer" autocomplete="off" data-sale-customer-search>
                        <div class="product-suggestions" data-sale-customer-suggestions hidden></div>
                    </div>
                    <label class="field">
                        <span>Phone</span>
                        <input type="text" name="customer_phone" value="<?php echo e($saleOldInput['customer_phone'] ?? ''); ?>" placeholder="Optional" data-sale-customer-phone>
                    </label>
                    <label class="field">
                        <span>Sale Date</span>
                        <input type="datetime-local" name="sale_date" value="<?php echo e($saleOldInput['sale_date'] ?? date('Y-m-d\TH:i')); ?>" required>
                    </label>
                </div>

                <div class="sale-items">
                    <div class="sale-row sale-head">
                        <span>Product</span>
                        <span>Warranty</span>
                        <span>Stock</span>
                        <span>Qty</span>
                        <span>Price</span>
                        <span>Disc.</span>
                        <span>Total</span>
                        <span></span>
                    </div>

                    <div data-sale-rows>
                        <?php foreach ($saleRows as $saleRow): ?>
                            <?php render_sale_row($saleRow); ?>
                        <?php endforeach; ?>
                    </div>

                    <button class="ghost-button" type="button" data-add-sale-row>
                        <i data-lucide="plus"></i>
                        Add Item
                    </button>
                </div>

                <div class="sale-footer">
                    <div class="purchase-note">
                        <i data-lucide="info"></i>
                        <span>Saving an invoice reduces stock and writes sale movement records for each item.</span>
                    </div>

                    <div class="purchase-totals">
                        <label>
                            <span>Subtotal</span>
                            <input type="text" value="0.00" data-sale-subtotal readonly>
                        </label>
                        <label>
                            <span>Invoice Discount</span>
                            <input type="number" name="discount" value="<?php echo e($saleOldInput['discount'] ?? '0.00'); ?>" min="0" step="0.01" data-sale-discount>
                        </label>
                        <label>
                            <span>Tax</span>
                            <input type="number" name="tax" value="<?php echo e($saleOldInput['tax'] ?? '0.00'); ?>" min="0" step="0.01" data-sale-tax>
                        </label>
                        <label>
                            <span>Total</span>
                            <input type="text" value="0.00" data-sale-total readonly>
                        </label>
                        <label>
                            <span>Payment</span>
                            <select name="payment_method">
                                <?php $paymentMethod = (string) ($saleOldInput['payment_method'] ?? 'cash'); ?>
                                <option value="cash" <?php echo $paymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="card" <?php echo $paymentMethod === 'card' ? 'selected' : ''; ?>>Card</option>
                                <option value="bank" <?php echo $paymentMethod === 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="credit" <?php echo $paymentMethod === 'credit' ? 'selected' : ''; ?>>Credit</option>
                            </select>
                        </label>
                        <label>
                            <span>Paid</span>
                            <input type="number" name="paid" value="<?php echo e($saleOldInput['paid'] ?? '0.00'); ?>" min="0" step="0.01" data-sale-paid>
                        </label>
                        <label>
                            <span>Balance</span>
                            <input type="text" value="0.00" data-sale-balance readonly>
                        </label>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="top-action" type="submit" name="after_save" value="print">
                        <i data-lucide="printer"></i>
                        Save and Print
                    </button>
                    <button class="top-action" type="submit" name="after_save" value="stay">
                        <i data-lucide="save"></i>
                        Save Invoice
                    </button>
                </div>
            </form>

            <template data-sale-row-template>
                <?php render_sale_row(); ?>
            </template>
        <?php endif; ?>
    </article>

</section>

<?php
function sales_form_pull_old_input(?PDO $pdo): array
{
    $oldInput = $_SESSION['sale_form_old'] ?? null;
    unset($_SESSION['sale_form_old']);

    if (! is_array($oldInput)) {
        return [];
    }

    return sales_form_normalize_old_input($oldInput, $pdo);
}

function sales_form_normalize_old_input(array $oldInput, ?PDO $pdo): array
{
    $customerId = trim((string) ($oldInput['customer_id'] ?? ''));
    $customerName = trim((string) ($oldInput['customer_name'] ?? ''));
    $customerPhone = trim((string) ($oldInput['customer_phone'] ?? ''));

    if ($customerId !== '' && $pdo instanceof PDO) {
        $customerStatement = $pdo->prepare('SELECT name, phone FROM customers WHERE id = :id LIMIT 1');
        $customerStatement->execute(['id' => (int) $customerId]);
        $customer = $customerStatement->fetch();

        if (is_array($customer)) {
            $customerName = $customerName !== '' ? $customerName : (string) $customer['name'];
            $customerPhone = $customerPhone !== '' ? $customerPhone : (string) ($customer['phone'] ?? '');
        }
    }

    $rows = sales_form_normalize_old_rows($oldInput, $pdo);

    return [
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'sale_date' => sales_form_datetime_value((string) ($oldInput['sale_date'] ?? '')),
        'payment_method' => sales_form_payment_method((string) ($oldInput['payment_method'] ?? 'cash')),
        'discount' => sales_form_money_value($oldInput['discount'] ?? '0.00'),
        'tax' => sales_form_money_value($oldInput['tax'] ?? '0.00'),
        'paid' => sales_form_money_value($oldInput['paid'] ?? '0.00'),
        'rows' => $rows === [] ? [[]] : $rows,
    ];
}

function sales_form_normalize_old_rows(array $oldInput, ?PDO $pdo): array
{
    $productIds = is_array($oldInput['product_id'] ?? null) ? $oldInput['product_id'] : [];
    $productSearches = is_array($oldInput['product_search'] ?? null) ? $oldInput['product_search'] : [];
    $quantities = is_array($oldInput['quantity'] ?? null) ? $oldInput['quantity'] : [];
    $unitPrices = is_array($oldInput['unit_price'] ?? null) ? $oldInput['unit_price'] : [];
    $warrantyMonths = is_array($oldInput['warranty_months'] ?? null) ? $oldInput['warranty_months'] : [];
    $lineDiscounts = is_array($oldInput['line_discount'] ?? null) ? $oldInput['line_discount'] : [];
    $productDetails = sales_form_product_details($productIds, $pdo);
    $rowCount = max(count($productIds), count($productSearches), count($quantities), count($unitPrices), count($warrantyMonths), count($lineDiscounts), 1);
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
            'stock' => is_array($product) ? (string) $product['stock'] : '0',
            'price' => sales_form_money_value($unitPrices[$index] ?? (is_array($product) ? $product['price'] : '0.00')),
            'cost' => is_array($product) ? sales_form_money_value($product['cost']) : '0.00',
            'warranty_months' => max(0, (int) ($warrantyMonths[$index] ?? 0)),
            'quantity' => max(1, (int) ($quantities[$index] ?? 1)),
            'line_discount' => sales_form_money_value($lineDiscounts[$index] ?? '0.00'),
        ];

        if ($row['product_id'] === '' && $row['product_search'] === '' && $row['price'] === '0.00' && $row['line_discount'] === '0.00') {
            if ($index > 0) {
                continue;
            }
        }

        $rows[] = $row;
    }

    return $rows;
}

function sales_form_product_details(array $productIds, ?PDO $pdo): array
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
        'SELECT id, sku, name, model, current_stock, cost_price, selling_price
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
            'stock' => (int) $product['current_stock'],
            'price' => (float) $product['selling_price'],
            'cost' => (float) $product['cost_price'],
        ];
    }

    return $products;
}

function sales_form_datetime_value(string $value): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1 ? $value : date('Y-m-d\TH:i');
}

function sales_form_payment_method(string $value): string
{
    return in_array($value, ['cash', 'card', 'bank', 'credit'], true) ? $value : 'cash';
}

function sales_form_money_value(mixed $value): string
{
    $value = str_replace(',', '', trim((string) $value));
    $amount = is_numeric($value) ? max(0.0, (float) $value) : 0.0;

    return number_format($amount, 2, '.', '');
}

function render_sale_row(array $row = []): void
{
    $productId = (string) ($row['product_id'] ?? '');
    $productSearch = (string) ($row['product_search'] ?? '');
    $stock = max(0, (int) ($row['stock'] ?? 0));
    $price = sales_form_money_value($row['price'] ?? '0.00');
    $cost = sales_form_money_value($row['cost'] ?? '0.00');
    $warrantyMonths = max(0, (int) ($row['warranty_months'] ?? 0));
    $quantity = max(1, (int) ($row['quantity'] ?? 1));
    $lineDiscount = sales_form_money_value($row['line_discount'] ?? '0.00');
    ?>
    <div class="sale-row" data-sale-row>
        <div class="field compact-field product-picker" data-sale-product-picker>
            <span>Product</span>
            <input type="hidden" name="product_id[]" value="<?php echo e($productId); ?>" data-stock="<?php echo e($stock); ?>" data-price="<?php echo e($price); ?>" data-cost="<?php echo e($cost); ?>" data-sale-product required>
            <input type="search" name="product_search[]" value="<?php echo e($productSearch); ?>" placeholder="Search product, SKU, barcode or @category" autocomplete="off" data-sale-product-search>
            <div class="product-suggestions" data-sale-product-suggestions hidden></div>
        </div>
        <label class="field compact-field">
            <span>Warranty</span>
            <input type="number" name="warranty_months[]" value="<?php echo e($warrantyMonths); ?>" min="0" step="1" data-sale-warranty>
        </label>
        <div class="stock-pill" data-sale-stock><?php echo e($stock); ?></div>
        <label class="field compact-field">
            <span>Qty</span>
            <input type="number" name="quantity[]" value="<?php echo e($quantity); ?>" min="1" step="1" <?php echo $stock > 0 ? 'max="' . e($stock) . '"' : ''; ?> data-sale-quantity required>
        </label>
        <label class="field compact-field">
            <span>Price</span>
            <input type="number" name="unit_price[]" value="<?php echo e($price); ?>" min="0" step="0.01" data-sale-price required>
        </label>
        <label class="field compact-field">
            <span>Discount</span>
            <input type="number" name="line_discount[]" value="<?php echo e($lineDiscount); ?>" min="0" step="0.01" data-sale-line-discount>
        </label>
        <label class="field compact-field">
            <span>Total</span>
            <input type="text" value="0.00" data-sale-line-total readonly>
        </label>
        <button class="icon-button danger-button" type="button" data-remove-sale-row aria-label="Remove item">
            <i data-lucide="trash-2"></i>
        </button>
    </div>
    <?php
}
