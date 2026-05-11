<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$search = trim((string) ($_GET['product_search'] ?? ''));
$statusFilter = (string) ($_GET['product_status'] ?? 'active');
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
        $where[] = '(p.name LIKE :search OR p.sku LIKE :search OR p.barcode LIKE :search OR p.model LIKE :search)';
        $params['search'] = '%' . $search . '%';
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
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Inventory control</p>
        <h1>Products</h1>
    </div>
    <a class="top-action" href="#product-form">
        <i data-lucide="package-plus"></i>
        Add Product
    </a>
</div>

<section class="stats-grid compact-stats" aria-label="Product summary">
    <article class="stat-card">
        <div>
            <span>Active Products</span>
            <strong><?php echo (int) $summary['products']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="package-search"></i></div>
        <small>Available in catalog</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Low Stock</span>
            <strong><?php echo (int) $summary['low_stock']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="triangle-alert"></i></div>
        <small>At or below reorder level</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Stock Units</span>
            <strong><?php echo (int) $summary['stock_units']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="boxes"></i></div>
        <small>Total quantity on hand</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Stock Value</span>
            <strong><?php echo e(format_money($summary['stock_value'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="badge-dollar-sign"></i></div>
        <small>Cost value on hand</small>
    </article>
</section>

<section class="product-layout">
    <article class="panel form-panel" id="product-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Product Setup</p>
                <h2><?php echo e($formTitle); ?></h2>
            </div>
            <?php if ($editingProduct !== null): ?>
                <a class="muted-link" href="<?php echo e(app_url('?page=products')); ?>">Cancel edit</a>
            <?php endif; ?>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before adding products.</p>
        <?php else: ?>
            <form class="product-form" method="post" action="<?php echo e(app_url('actions/product_save.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" value="<?php echo e($editingProduct['id'] ?? ''); ?>">

                <label class="field span-2">
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
                    <select name="category_id">
                        <option value="">Uncategorized</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int) $category['id']; ?>" <?php echo (int) ($editingProduct['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : ''; ?>>
                                <?php echo e($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field">
                    <span>Brand</span>
                    <select name="brand_id">
                        <option value="">No brand</option>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?php echo (int) $brand['id']; ?>" <?php echo (int) ($editingProduct['brand_id'] ?? 0) === (int) $brand['id'] ? 'selected' : ''; ?>>
                                <?php echo e($brand['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                    <input type="number" name="reorder_level" value="<?php echo e($editingProduct['reorder_level'] ?? '0'); ?>" min="0" step="1">
                </label>

                <?php if ($editingProduct === null): ?>
                    <label class="field">
                        <span>Opening Stock</span>
                        <input type="number" name="opening_stock" value="0" min="0" step="1">
                    </label>
                <?php endif; ?>

                <label class="field span-2">
                    <span>Description</span>
                    <textarea name="description" rows="3" placeholder="Optional product notes"><?php echo e($editingProduct['description'] ?? ''); ?></textarea>
                </label>

                <div class="form-actions span-2">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Product
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Product Catalog</p>
                <h2>Inventory list</h2>
            </div>

            <form class="filter-row" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="products">
                <input type="search" name="product_search" value="<?php echo e($search); ?>" placeholder="Search catalog">
                <select name="product_status">
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Archived</option>
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                </select>
                <button class="icon-button" type="submit" aria-label="Apply filters">
                    <i data-lucide="search"></i>
                </button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Brand</th>
                        <th>Category</th>
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
                                <strong class="table-title"><?php echo e($product['name']); ?></strong>
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
                                    <a class="icon-button" href="<?php echo e(app_url('?page=products&edit=' . (int) $product['id'])); ?>" aria-label="Edit product">
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
