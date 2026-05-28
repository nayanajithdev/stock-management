<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$hasSaleProducts = false;

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
            <form class="sale-form" method="post" action="<?php echo e(app_url('actions/sale_save.php')); ?>" data-sale-form data-sale-product-search-url="<?php echo e(app_url('actions/sale_product_search.php')); ?>" data-sale-customer-search-url="<?php echo e(app_url('actions/customer_search.php')); ?>">
                <?php echo csrf_field(); ?>

                <div class="sale-meta">
                    <div class="field product-picker" data-sale-customer-picker>
                        <span>Customer</span>
                        <input type="hidden" name="customer_id" data-sale-customer>
                        <input type="search" name="customer_name" placeholder="Search customer, phone, email or type new customer" autocomplete="off" data-sale-customer-search>
                        <div class="product-suggestions" data-sale-customer-suggestions hidden></div>
                    </div>
                    <label class="field">
                        <span>Phone</span>
                        <input type="text" name="customer_phone" placeholder="Optional" data-sale-customer-phone>
                    </label>
                    <label class="field">
                        <span>Sale Date</span>
                        <input type="datetime-local" name="sale_date" value="<?php echo e(date('Y-m-d\TH:i')); ?>" required>
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
                        <?php render_sale_row(); ?>
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
                <?php render_sale_row(); ?>
            </template>
        <?php endif; ?>
    </article>

</section>

<?php
function render_sale_row(): void
{
    ?>
    <div class="sale-row" data-sale-row>
        <div class="field compact-field product-picker" data-sale-product-picker>
            <span>Product</span>
            <input type="hidden" name="product_id[]" data-sale-product required>
            <input type="search" placeholder="Search product, SKU, barcode" autocomplete="off" data-sale-product-search>
            <div class="product-suggestions" data-sale-product-suggestions hidden></div>
        </div>
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
