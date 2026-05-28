<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var array $config */

$search = trim((string) ($_GET['product_search'] ?? ''));
$statusFilter = (string) ($_GET['product_status'] ?? 'active');
$brandFilterId = max(0, (int) ($_GET['brand_id'] ?? 0));
$categoryFilterId = max(0, (int) ($_GET['category_id'] ?? 0));
$allowedStatuses = ['active', 'inactive', 'all'];

if (! in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'active';
}

$products = [];
$categories = [];
$brands = [];
$suppliers = [];
$editingProduct = null;
$summary = [
    'products' => 0,
    'low_stock' => 0,
    'stock_units' => 0,
    'stock_value' => 0.0,
];

if ($dbReady && $pdo !== null) {
    $categories = app_fetch_options($pdo, 'categories');
    $brands = app_fetch_options($pdo, 'brands');
    $suppliers = app_fetch_options($pdo, 'suppliers');

    $summary['products'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active"')->fetchColumn();
    $summary['low_stock'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE status = "active" AND reorder_level > 0 AND current_stock <= reorder_level')->fetchColumn();
    $summary['stock_units'] = (int) $pdo->query('SELECT COALESCE(SUM(current_stock), 0) FROM products WHERE status = "active"')->fetchColumn();
    $summary['stock_value'] = (float) $pdo->query('SELECT COALESCE(SUM(current_stock * cost_price), 0) FROM products WHERE status = "active"')->fetchColumn();

    $where = [];
    $params = [];

    if ($statusFilter !== 'all') {
        $where[] = 'p.status = :status';
        $params['status'] = $statusFilter;
    }

    if ($search !== '') {
        $where[] = 'p.name LIKE :search';
        $params['search'] = '%' . $search . '%';
    }

    if ($brandFilterId > 0) {
        $where[] = 'p.brand_id = :brand_id';
        $params['brand_id'] = $brandFilterId;
    }

    if ($categoryFilterId > 0) {
        $where[] = 'p.category_id = :category_id';
        $params['category_id'] = $categoryFilterId;
    }

    $sql = 'SELECT p.*, c.name AS category_name, b.name AS brand_name, s.name AS supplier_name
            FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN suppliers s ON s.id = p.supplier_id';

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY p.created_at DESC, p.id DESC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $products = $statement->fetchAll();

    if (isset($_GET['edit'])) {
        $editStatement = $pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $editStatement->execute(['id' => (int) $_GET['edit']]);
        $editingProduct = $editStatement->fetch() ?: null;
    }
}

$formTitle = $editingProduct === null ? 'Add Product' : 'Edit Product';
$showProductForm = $editingProduct !== null || (string) ($_GET['form'] ?? '') === 'product';
$selectedCategoryName = product_option_name($categories, (int) ($editingProduct['category_id'] ?? 0));
$selectedBrandName = product_option_name($brands, (int) ($editingProduct['brand_id'] ?? 0));
?>

<div class="page-heading">
    <div>
        <h1>Products</h1>
    </div>
    <?php if ($showProductForm): ?>
        <a class="top-action" href="<?php echo e(app_url('?page=products')); ?>">
            <i data-lucide="arrow-left"></i>
            Product List
        </a>
    <?php else: ?>
        <a class="top-action" href="<?php echo e(app_url('?page=products&form=product#product-form')); ?>">
            <i data-lucide="package-plus"></i>
            Add Product
        </a>
    <?php endif; ?>
</div>

<?php if ($showProductForm): ?>
<section class="product-form-window" id="product-form">
    <article class="panel form-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Product Setup</p>
                <h2><?php echo e($formTitle); ?></h2>
            </div>
            <div class="modal-actions">
                <?php if ($editingProduct !== null): ?>
                    <a class="muted-link" href="<?php echo e(app_url('?page=products&form=product#product-form')); ?>">New product</a>
                <?php endif; ?>
                <a class="icon-button" href="<?php echo e(app_url('?page=products')); ?>" aria-label="Close product form">
                    <i data-lucide="x"></i>
                </a>
            </div>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before adding products.</p>
        <?php else: ?>
            <form class="product-form" method="post" action="<?php echo e(app_url('actions/product_save.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" value="<?php echo e($editingProduct['id'] ?? ''); ?>">

                <label class="field span-4">
                    <span>Product Name</span>
                    <input type="text" name="name" value="<?php echo e($editingProduct['name'] ?? ''); ?>" placeholder="Logitech M185 Wireless Mouse" required>
                </label>

                <label class="field">
                    <span>SKU</span>
                    <input type="text" name="sku" value="<?php echo e($editingProduct['sku'] ?? ''); ?>" placeholder="MOU-LOG-M185" required>
                </label>

                <label class="field">
                    <span>Barcode</span>
                    <input type="text" name="barcode" value="<?php echo e($editingProduct['barcode'] ?? ''); ?>" placeholder="Scan or type barcode">
                </label>

                <label class="field">
                    <span>Category</span>
                    <input type="text" name="category_name" value="<?php echo e($selectedCategoryName); ?>" list="product-category-options" placeholder="Search or type new category" autocomplete="off">
                    <datalist id="product-category-options">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo e($category['name']); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>

                <label class="field">
                    <span>Brand</span>
                    <input type="text" name="brand_name" value="<?php echo e($selectedBrandName); ?>" list="product-brand-options" placeholder="Search or type new brand" autocomplete="off">
                    <datalist id="product-brand-options">
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?php echo e($brand['name']); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>

                <label class="field">
                    <span>Supplier</span>
                    <select name="supplier_id">
                        <option value="">No supplier</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo (int) $supplier['id']; ?>" <?php echo (int) ($editingProduct['supplier_id'] ?? 0) === (int) $supplier['id'] ? 'selected' : ''; ?>>
                                <?php echo e($supplier['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span>Model</span>
                    <input type="text" name="model" value="<?php echo e($editingProduct['model'] ?? ''); ?>" placeholder="M185">
                </label>

                <label class="field">
                    <span>Cost Price</span>
                    <input type="number" name="cost_price" value="<?php echo e($editingProduct['cost_price'] ?? '0.00'); ?>" min="0" step="0.01">
                </label>

                <label class="field">
                    <span>Selling Price</span>
                    <input type="number" name="selling_price" value="<?php echo e($editingProduct['selling_price'] ?? '0.00'); ?>" min="0" step="0.01">
                </label>

                <label class="field">
                    <span>Wholesale Price</span>
                    <input type="number" name="wholesale_price" value="<?php echo e($editingProduct['wholesale_price'] ?? '0.00'); ?>" min="0" step="0.01">
                </label>

                <label class="field">
                    <span>Warranty Months</span>
                    <input type="number" name="warranty_months" value="<?php echo e($editingProduct['warranty_months'] ?? '0'); ?>" min="0" step="1">
                </label>

                <label class="field">
                    <span>Reorder Level</span>
                    <input type="number" name="reorder_level" value="<?php echo e($editingProduct['reorder_level'] ?? ($config['default_reorder_level'] ?? '0')); ?>" min="0" step="1">
                </label>

                <?php if ($editingProduct === null): ?>
                    <label class="field">
                        <span>Purchase Date</span>
                        <input type="date" name="purchase_date" value="<?php echo e(date('Y-m-d')); ?>" required>
                    </label>

                    <label class="field">
                        <span>Opening Stock</span>
                        <input type="number" name="opening_stock" value="0" min="0" step="1">
                    </label>
                <?php endif; ?>

                <label class="checkbox-row span-4">
                    <input type="checkbox" name="item_tracking" value="1" <?php echo (int) ($editingProduct['item_tracking'] ?? 0) === 1 ? 'checked' : ''; ?>>
                    <span>Warranty Tracking</span>
                </label>

                <label class="field span-4">
                    <span>Description</span>
                    <textarea name="description" rows="3" placeholder="Optional product notes"><?php echo e($editingProduct['description'] ?? ''); ?></textarea>
                </label>

                <div class="form-actions span-4">
                    <a class="ghost-button" href="<?php echo e(app_url('?page=products')); ?>">Cancel</a>
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Product
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>
</section>
<?php else: ?>
<section class="product-layout product-catalog-layout">
    <article class="panel table-panel product-table-panel">
        <div class="table-action-header table-action-header-left">
            <form class="filter-row product-filter-row" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="products">
                <?php if ($brandFilterId > 0): ?>
                    <input type="hidden" name="brand_id" value="<?php echo (int) $brandFilterId; ?>">
                <?php endif; ?>
                <?php if ($categoryFilterId > 0): ?>
                    <input type="hidden" name="category_id" value="<?php echo (int) $categoryFilterId; ?>">
                <?php endif; ?>
                <select name="product_status">
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Archived</option>
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                </select>
                <input type="search" name="product_search" value="<?php echo e($search); ?>" placeholder="Search product name">
                <button class="icon-button" type="submit" aria-label="Apply filters">
                    <i data-lucide="search"></i>
                </button>
                <?php if ($search !== '' || $statusFilter !== 'active' || $brandFilterId > 0 || $categoryFilterId > 0): ?>
                    <a class="ghost-button compact-clear-button" href="<?php echo e(app_url('?page=products')); ?>">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>
                            <details class="th-filter-menu <?php echo $brandFilterId > 0 ? 'active' : ''; ?>">
                                <summary>
                                    Brand
                                    <i data-lucide="chevron-down"></i>
                                </summary>
                                <div class="th-filter-popover">
                                    <a class="<?php echo $brandFilterId === 0 ? 'active' : ''; ?>" href="<?php echo e(product_filter_url($statusFilter, $search, 0, $categoryFilterId)); ?>">All brands</a>
                                    <?php foreach ($brands as $brand): ?>
                                        <?php $brandId = (int) $brand['id']; ?>
                                        <a class="<?php echo $brandFilterId === $brandId ? 'active' : ''; ?>" href="<?php echo e(product_filter_url($statusFilter, $search, $brandId, $categoryFilterId)); ?>">
                                            <?php echo e($brand['name']); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </th>
                        <th>
                            <details class="th-filter-menu <?php echo $categoryFilterId > 0 ? 'active' : ''; ?>">
                                <summary>
                                    Category
                                    <i data-lucide="chevron-down"></i>
                                </summary>
                                <div class="th-filter-popover">
                                    <a class="<?php echo $categoryFilterId === 0 ? 'active' : ''; ?>" href="<?php echo e(product_filter_url($statusFilter, $search, $brandFilterId, 0)); ?>">All categories</a>
                                    <?php foreach ($categories as $category): ?>
                                        <?php $categoryId = (int) $category['id']; ?>
                                        <a class="<?php echo $categoryFilterId === $categoryId ? 'active' : ''; ?>" href="<?php echo e(product_filter_url($statusFilter, $search, $brandFilterId, $categoryId)); ?>">
                                            <?php echo e($category['name']); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </th>
                        <th>Stock</th>
                        <th>Reorder</th>
                        <th>Cost</th>
                        <th>Sell</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($products === []): ?>
                        <tr>
                            <td colspan="10">No products found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($products as $product): ?>
                        <?php $isLow = (int) $product['reorder_level'] > 0 && (int) $product['current_stock'] <= (int) $product['reorder_level']; ?>
                        <tr>
                            <td><?php echo e($product['sku']); ?></td>
                            <td>
                                <strong class="table-title"><?php echo product_highlight($product['name'], $search); ?></strong>
                                <span class="table-subtitle"><?php echo e($product['model'] ?: ($product['barcode'] ?: 'No model')); ?></span>
                            </td>
                            <td><?php echo e($product['brand_name'] ?? ''); ?></td>
                            <td><?php echo e($product['category_name'] ?? ''); ?></td>
                            <td class="<?php echo $isLow ? 'text-danger' : ''; ?>"><?php echo (int) $product['current_stock']; ?></td>
                            <td><?php echo (int) $product['reorder_level']; ?></td>
                            <td><?php echo e(format_money($product['cost_price'])); ?></td>
                            <td><?php echo e(format_money($product['selling_price'])); ?></td>
                            <td><span class="status status-<?php echo e($product['status']); ?>"><?php echo e(ucfirst((string) $product['status'])); ?></span></td>
                            <td>
                                <div class="table-actions">
                                    <a class="icon-button" href="<?php echo e(app_url('?page=product-history&id=' . (int) $product['id'])); ?>" aria-label="View stock history">
                                        <i data-lucide="history"></i>
                                    </a>
                                    <a class="icon-button" href="<?php echo e(app_url('?page=products&edit=' . (int) $product['id'] . '#product-form')); ?>" aria-label="Edit product">
                                        <i data-lucide="pencil"></i>
                                    </a>
                                    <?php if ($product['status'] === 'active'): ?>
                                        <form method="post" action="<?php echo e(app_url('actions/product_delete.php')); ?>" data-confirm="Archive this product?">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>">
                                            <button class="icon-button danger-button" type="submit" aria-label="Archive product">
                                                <i data-lucide="archive"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php endif; ?>

<?php
function product_option_name(array $options, int $selectedId): string
{
    if ($selectedId <= 0) {
        return '';
    }

    foreach ($options as $option) {
        if ((int) $option['id'] === $selectedId) {
            return (string) $option['name'];
        }
    }

    return '';
}

function product_filter_url(string $statusFilter, string $search, int $brandId, int $categoryId): string
{
    $params = [
        'page' => 'products',
        'product_status' => $statusFilter,
    ];

    if ($search !== '') {
        $params['product_search'] = $search;
    }

    if ($brandId > 0) {
        $params['brand_id'] = $brandId;
    }

    if ($categoryId > 0) {
        $params['category_id'] = $categoryId;
    }

    return app_url('?' . http_build_query($params));
}

function product_highlight(mixed $value, string $search): string
{
    $text = (string) $value;
    $search = trim($search);

    if ($text === '' || $search === '') {
        return e($text);
    }

    $parts = preg_split('/(' . preg_quote($search, '/') . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

    if ($parts === false || count($parts) === 1) {
        return e($text);
    }

    $html = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        if (strcasecmp($part, $search) === 0) {
            $html .= '<mark class="search-highlight">' . e($part) . '</mark>';
        } else {
            $html .= e($part);
        }
    }

    return $html;
}

?>
