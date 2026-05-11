<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$products = [];
$customers = [];
$sales = [];
$saleSearch = trim((string) ($_GET['q'] ?? ''));
$summary = [
    'today_sales' => 0.0,
    'today_paid' => 0.0,
    'today_balance' => 0.0,
    'today_orders' => 0,
];

if ($dbReady && $pdo !== null) {
    $products = $pdo->query(
        'SELECT id, sku, barcode, name, model, current_stock, cost_price, selling_price, warranty_months
         FROM products
         WHERE status = "active"
         ORDER BY name ASC'
    )->fetchAll();

    $customers = $pdo->query(
        'SELECT id, name, phone
         FROM customers
         WHERE is_active = 1
         ORDER BY name ASC
         LIMIT 200'
    )->fetchAll();

    $summaryStatement = $pdo->query(
        'SELECT
            COALESCE(SUM(total), 0) AS today_sales,
            COALESCE(SUM(paid), 0) AS today_paid,
            COALESCE(SUM(total - paid), 0) AS today_balance,
            COUNT(*) AS today_orders
         FROM sales
         WHERE DATE(sale_date) = CURRENT_DATE'
    );
    $summaryRow = $summaryStatement->fetch() ?: [];
    $summary['today_sales'] = (float) ($summaryRow['today_sales'] ?? 0);
    $summary['today_paid'] = (float) ($summaryRow['today_paid'] ?? 0);
    $summary['today_balance'] = (float) ($summaryRow['today_balance'] ?? 0);
    $summary['today_orders'] = (int) ($summaryRow['today_orders'] ?? 0);

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

    $saleSql .= ' GROUP BY s.id ORDER BY s.sale_date DESC, s.id DESC LIMIT 30';
    $saleStatement = $pdo->prepare($saleSql);
    $saleStatement->execute($saleParams);
    $sales = $saleStatement->fetchAll();
}

$productOptions = '';

foreach ($products as $product) {
    $label = $product['sku'] . ' - ' . $product['name'];
    if ((string) ($product['model'] ?? '') !== '') {
        $label .= ' (' . $product['model'] . ')';
    }

    $productOptions .= '<option value="' . (int) $product['id'] . '"'
        . ' data-price="' . e($product['selling_price']) . '"'
        . ' data-cost="' . e($product['cost_price']) . '"'
        . ' data-stock="' . (int) $product['current_stock'] . '"'
        . ' data-barcode="' . e($product['barcode'] ?? '') . '"'
        . '>'
        . e($label . ' / Stock: ' . (int) $product['current_stock'])
        . '</option>';
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Point of sale</p>
        <h1>Sales POS</h1>
    </div>
    <a class="top-action" href="#sales-pos-form">
        <i data-lucide="scan-barcode"></i>
        New Invoice
    </a>
</div>

<section class="stats-grid compact-stats" aria-label="Sales summary">
    <article class="stat-card">
        <div>
            <span>Today Sales</span>
            <strong><?php echo e(format_money($summary['today_sales'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="badge-dollar-sign"></i></div>
        <small>Total invoiced today</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Today Paid</span>
            <strong><?php echo e(format_money($summary['today_paid'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="wallet-cards"></i></div>
        <small>Cash received</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Receivable</span>
            <strong><?php echo e(format_money($summary['today_balance'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="receipt-text"></i></div>
        <small>Pending today</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Orders</span>
            <strong><?php echo (int) $summary['today_orders']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="shopping-bag"></i></div>
        <small>Invoices today</small>
    </article>
</section>

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
        <?php elseif ($products === []): ?>
            <p class="empty-state">Add products and stock before creating invoices.</p>
        <?php else: ?>
            <form class="sale-form" method="post" action="<?php echo e(app_url('actions/sale_save.php')); ?>" data-sale-form>
                <?php echo csrf_field(); ?>

                <div class="sale-meta">
                    <label class="field">
                        <span>Existing Customer</span>
                        <select name="customer_id">
                            <option value="">Walk-in / New customer</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo (int) $customer['id']; ?>"><?php echo e($customer['name'] . (($customer['phone'] ?? '') !== '' ? ' / ' . $customer['phone'] : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>New Customer Name</span>
                        <input type="text" name="customer_name" placeholder="Optional">
                    </label>
                    <label class="field">
                        <span>Phone</span>
                        <input type="text" name="customer_phone" placeholder="Optional">
                    </label>
                    <label class="field">
                        <span>Sale Date</span>
                        <input type="datetime-local" name="sale_date" value="<?php echo e(date('Y-m-d\TH:i')); ?>" required>
                    </label>
                </div>

                <div class="barcode-row">
                    <label class="field">
                        <span>Barcode / SKU Quick Add</span>
                        <input type="text" placeholder="Scan barcode or type SKU, then press Enter" data-sale-barcode>
                    </label>
                </div>

                <div class="sale-items">
                    <div class="sale-row sale-head">
                        <span>Product</span>
                        <span>Stock</span>
                        <span>Qty</span>
                        <span>Price</span>
                        <span>Disc.</span>
                        <span>Total</span>
                        <span></span>
                    </div>

                    <div data-sale-rows>
                        <?php render_sale_row($productOptions); ?>
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
                            <input type="number" name="discount" value="0.00" min="0" step="0.01" data-sale-discount>
                        </label>
                        <label>
                            <span>Tax</span>
                            <input type="number" name="tax" value="0.00" min="0" step="0.01" data-sale-tax>
                        </label>
                        <label>
                            <span>Total</span>
                            <input type="text" value="0.00" data-sale-total readonly>
                        </label>
                        <label>
                            <span>Payment</span>
                            <select name="payment_method">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="credit">Credit</option>
                            </select>
                        </label>
                        <label>
                            <span>Paid</span>
                            <input type="number" name="paid" value="0.00" min="0" step="0.01" data-sale-paid>
                        </label>
                        <label>
                            <span>Balance</span>
                            <input type="text" value="0.00" data-sale-balance readonly>
                        </label>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Invoice
                    </button>
                </div>
            </form>

            <template data-sale-row-template>
                <?php render_sale_row($productOptions); ?>
            </template>
        <?php endif; ?>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Sales History</p>
                <h2>Recent invoices</h2>
            </div>
            <?php if ($saleSearch !== ''): ?>
                <a class="muted-link" href="<?php echo e(app_url('?page=sales')); ?>">Clear search</a>
            <?php endif; ?>
        </div>

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
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sales === []): ?>
                        <tr>
                            <td colspan="9">No sales recorded yet.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($sales as $sale): ?>
                        <?php $balance = (float) $sale['total'] - (float) $sale['paid']; ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $sale['sale_date']))); ?></td>
                            <td><?php echo e($sale['invoice_no']); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($sale['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                                <span class="table-subtitle"><?php echo e($sale['customer_phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo (int) $sale['item_count']; ?></td>
                            <td><?php echo (int) $sale['total_units']; ?></td>
                            <td><?php echo e(format_money($sale['total'])); ?></td>
                            <td><?php echo e(format_money($sale['paid'])); ?></td>
                            <td class="<?php echo $balance > 0 ? 'text-danger' : ''; ?>"><?php echo e(format_money($balance)); ?></td>
                            <td><span class="status <?php echo e(sale_status_class((string) $sale['status'])); ?>"><?php echo e(ucfirst((string) $sale['status'])); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php
function render_sale_row(string $productOptions): void
{
    ?>
    <div class="sale-row" data-sale-row>
        <label class="field compact-field">
            <span>Product</span>
            <select name="product_id[]" data-sale-product required>
                <option value="">Choose product</option>
                <?php echo $productOptions; ?>
            </select>
        </label>
        <div class="stock-pill" data-sale-stock>0</div>
        <label class="field compact-field">
            <span>Qty</span>
            <input type="number" name="quantity[]" value="1" min="1" step="1" data-sale-quantity required>
        </label>
        <label class="field compact-field">
            <span>Price</span>
            <input type="number" name="unit_price[]" value="0.00" min="0" step="0.01" data-sale-price required>
        </label>
        <label class="field compact-field">
            <span>Discount</span>
            <input type="number" name="line_discount[]" value="0.00" min="0" step="0.01" data-sale-line-discount>
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

function sale_status_class(string $status): string
{
    return match ($status) {
        'paid' => 'status-active',
        'partial' => 'status-warranty',
        'credit' => 'status-pending',
        default => 'status-inactive',
    };
}
