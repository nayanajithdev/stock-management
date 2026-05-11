<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$section = (string) ($_GET['section'] ?? 'categories');
$setupSearch = trim((string) ($_GET['q'] ?? ''));
$validSections = ['categories', 'brands', 'suppliers'];

if (! in_array($section, $validSections, true)) {
    $section = 'categories';
}

$editType = (string) ($_GET['edit_type'] ?? '');
$editId = (int) ($_GET['edit_id'] ?? 0);
$categories = [];
$brands = [];
$suppliers = [];
$editingCategory = null;
$editingBrand = null;
$editingSupplier = null;
$summary = [
    'categories' => 0,
    'brands' => 0,
    'suppliers' => 0,
    'linked_products' => 0,
];

if ($dbReady && $pdo !== null) {
    $categorySql = 'SELECT c.*,
                           COUNT(p.id) AS product_count
                    FROM categories c
                    LEFT JOIN products p ON p.category_id = c.id';
    $categoryParams = [];

    if ($setupSearch !== '') {
        $categorySql .= ' WHERE c.name LIKE :search OR c.description LIKE :search';
        $categoryParams['search'] = '%' . $setupSearch . '%';
    }

    $categorySql .= ' GROUP BY c.id ORDER BY c.is_active DESC, c.name ASC';
    $categoryStatement = $pdo->prepare($categorySql);
    $categoryStatement->execute($categoryParams);
    $categories = $categoryStatement->fetchAll();

    $brandSql = 'SELECT b.*,
                        COUNT(p.id) AS product_count
                 FROM brands b
                 LEFT JOIN products p ON p.brand_id = b.id';
    $brandParams = [];

    if ($setupSearch !== '') {
        $brandSql .= ' WHERE b.name LIKE :search';
        $brandParams['search'] = '%' . $setupSearch . '%';
    }

    $brandSql .= ' GROUP BY b.id ORDER BY b.is_active DESC, b.name ASC';
    $brandStatement = $pdo->prepare($brandSql);
    $brandStatement->execute($brandParams);
    $brands = $brandStatement->fetchAll();

    $supplierSql = 'SELECT s.*,
                           COUNT(p.id) AS product_count
                    FROM suppliers s
                    LEFT JOIN products p ON p.supplier_id = s.id';
    $supplierParams = [];

    if ($setupSearch !== '') {
        $supplierSql .= ' WHERE s.name LIKE :search OR s.contact_person LIKE :search OR s.phone LIKE :search OR s.email LIKE :search';
        $supplierParams['search'] = '%' . $setupSearch . '%';
    }

    $supplierSql .= ' GROUP BY s.id ORDER BY s.is_active DESC, s.name ASC';
    $supplierStatement = $pdo->prepare($supplierSql);
    $supplierStatement->execute($supplierParams);
    $suppliers = $supplierStatement->fetchAll();

    $summary['categories'] = (int) $pdo->query('SELECT COUNT(*) FROM categories WHERE is_active = 1')->fetchColumn();
    $summary['brands'] = (int) $pdo->query('SELECT COUNT(*) FROM brands WHERE is_active = 1')->fetchColumn();
    $summary['suppliers'] = (int) $pdo->query('SELECT COUNT(*) FROM suppliers WHERE is_active = 1')->fetchColumn();
    $summary['linked_products'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE category_id IS NOT NULL OR brand_id IS NOT NULL OR supplier_id IS NOT NULL')->fetchColumn();

    if ($editId > 0 && $editType === 'category') {
        $statement = $pdo->prepare('SELECT * FROM categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $editId]);
        $editingCategory = $statement->fetch() ?: null;
        $section = 'categories';
    }

    if ($editId > 0 && $editType === 'brand') {
        $statement = $pdo->prepare('SELECT * FROM brands WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $editId]);
        $editingBrand = $statement->fetch() ?: null;
        $section = 'brands';
    }

    if ($editId > 0 && $editType === 'supplier') {
        $statement = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $editId]);
        $editingSupplier = $statement->fetch() ?: null;
        $section = 'suppliers';
    }
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Inventory master data</p>
        <h1>Inventory Setup</h1>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=products')); ?>">
        <i data-lucide="package-plus"></i>
        Products
    </a>
</div>

<section class="stats-grid compact-stats" aria-label="Setup summary">
    <article class="stat-card">
        <div>
            <span>Categories</span>
            <strong><?php echo (int) $summary['categories']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="tags"></i></div>
        <small>Active product groups</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Brands</span>
            <strong><?php echo (int) $summary['brands']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="badge-check"></i></div>
        <small>Active manufacturers</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Suppliers</span>
            <strong><?php echo (int) $summary['suppliers']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="truck"></i></div>
        <small>Active vendor records</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Linked Products</span>
            <strong><?php echo (int) $summary['linked_products']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="link"></i></div>
        <small>Using setup records</small>
    </article>
</section>

<div class="tab-row" role="tablist" aria-label="Inventory setup sections">
    <a class="<?php echo $section === 'categories' ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=inventory-setup&section=categories' . ($setupSearch !== '' ? '&q=' . rawurlencode($setupSearch) : ''))); ?>">Categories</a>
    <a class="<?php echo $section === 'brands' ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=inventory-setup&section=brands' . ($setupSearch !== '' ? '&q=' . rawurlencode($setupSearch) : ''))); ?>">Brands</a>
    <a class="<?php echo $section === 'suppliers' ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=inventory-setup&section=suppliers' . ($setupSearch !== '' ? '&q=' . rawurlencode($setupSearch) : ''))); ?>">Suppliers</a>
</div>

<?php if ($setupSearch !== ''): ?>
    <p class="search-note">Showing setup records matching <strong><?php echo e($setupSearch); ?></strong>.</p>
<?php endif; ?>

<?php if (! $dbReady): ?>
    <section class="panel">
        <p class="empty-state">Import <code>database/schema.sql</code> before managing inventory setup records.</p>
    </section>
<?php endif; ?>

<?php if ($dbReady && $section === 'categories'): ?>
    <section class="setup-grid">
        <article class="panel form-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Category</p>
                    <h2><?php echo $editingCategory === null ? 'Add Category' : 'Edit Category'; ?></h2>
                </div>
                <?php if ($editingCategory !== null): ?>
                    <a class="muted-link" href="<?php echo e(app_url('?page=inventory-setup&section=categories')); ?>">Cancel edit</a>
                <?php endif; ?>
            </div>

            <form class="product-form single-form" method="post" action="<?php echo e(app_url('actions/master_save.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="entity" value="category">
                <input type="hidden" name="id" value="<?php echo e($editingCategory['id'] ?? ''); ?>">

                <label class="field">
                    <span>Category Name</span>
                    <input type="text" name="name" value="<?php echo e($editingCategory['name'] ?? ''); ?>" placeholder="Printers, Storage, Cables" required>
                </label>
                <label class="field">
                    <span>Description</span>
                    <textarea name="description" rows="4" placeholder="Short category note"><?php echo e($editingCategory['description'] ?? ''); ?></textarea>
                </label>
                <div class="form-actions">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Category
                    </button>
                </div>
            </form>
        </article>

        <article class="panel table-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Categories</p>
                    <h2>Product groups</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($categories === []): ?>
                            <tr>
                                <td colspan="5">No categories found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($categories as $category): ?>
                            <tr>
                                <td><strong class="table-title"><?php echo e($category['name']); ?></strong></td>
                                <td><?php echo e($category['description'] ?? ''); ?></td>
                                <td><?php echo (int) $category['product_count']; ?></td>
                                <td><span class="status status-<?php echo (int) $category['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $category['is_active'] === 1 ? 'Active' : 'Archived'; ?></span></td>
                                <td><?php render_master_actions('category', (int) $category['id'], (int) $category['is_active'], 'categories'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
<?php endif; ?>

<?php if ($dbReady && $section === 'brands'): ?>
    <section class="setup-grid">
        <article class="panel form-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Brand</p>
                    <h2><?php echo $editingBrand === null ? 'Add Brand' : 'Edit Brand'; ?></h2>
                </div>
                <?php if ($editingBrand !== null): ?>
                    <a class="muted-link" href="<?php echo e(app_url('?page=inventory-setup&section=brands')); ?>">Cancel edit</a>
                <?php endif; ?>
            </div>

            <form class="product-form single-form" method="post" action="<?php echo e(app_url('actions/master_save.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="entity" value="brand">
                <input type="hidden" name="id" value="<?php echo e($editingBrand['id'] ?? ''); ?>">

                <label class="field">
                    <span>Brand Name</span>
                    <input type="text" name="name" value="<?php echo e($editingBrand['name'] ?? ''); ?>" placeholder="Logitech, Kingston, TP-Link" required>
                </label>
                <div class="form-actions">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Brand
                    </button>
                </div>
            </form>
        </article>

        <article class="panel table-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Brands</p>
                    <h2>Manufacturers</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($brands === []): ?>
                            <tr>
                                <td colspan="4">No brands found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($brands as $brand): ?>
                            <tr>
                                <td><strong class="table-title"><?php echo e($brand['name']); ?></strong></td>
                                <td><?php echo (int) $brand['product_count']; ?></td>
                                <td><span class="status status-<?php echo (int) $brand['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $brand['is_active'] === 1 ? 'Active' : 'Archived'; ?></span></td>
                                <td><?php render_master_actions('brand', (int) $brand['id'], (int) $brand['is_active'], 'brands'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
<?php endif; ?>

<?php if ($dbReady && $section === 'suppliers'): ?>
    <section class="setup-grid">
        <article class="panel form-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Supplier</p>
                    <h2><?php echo $editingSupplier === null ? 'Add Supplier' : 'Edit Supplier'; ?></h2>
                </div>
                <?php if ($editingSupplier !== null): ?>
                    <a class="muted-link" href="<?php echo e(app_url('?page=inventory-setup&section=suppliers')); ?>">Cancel edit</a>
                <?php endif; ?>
            </div>

            <form class="product-form single-form" method="post" action="<?php echo e(app_url('actions/master_save.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="entity" value="supplier">
                <input type="hidden" name="id" value="<?php echo e($editingSupplier['id'] ?? ''); ?>">

                <label class="field">
                    <span>Supplier Name</span>
                    <input type="text" name="name" value="<?php echo e($editingSupplier['name'] ?? ''); ?>" placeholder="Supplier company name" required>
                </label>
                <label class="field">
                    <span>Contact Person</span>
                    <input type="text" name="contact_person" value="<?php echo e($editingSupplier['contact_person'] ?? ''); ?>" placeholder="Main contact">
                </label>
                <label class="field">
                    <span>Phone</span>
                    <input type="text" name="phone" value="<?php echo e($editingSupplier['phone'] ?? ''); ?>" placeholder="0770000000">
                </label>
                <label class="field">
                    <span>Email</span>
                    <input type="email" name="email" value="<?php echo e($editingSupplier['email'] ?? ''); ?>" placeholder="supplier@example.com">
                </label>
                <label class="field">
                    <span>Address</span>
                    <textarea name="address" rows="4" placeholder="Supplier address"><?php echo e($editingSupplier['address'] ?? ''); ?></textarea>
                </label>
                <div class="form-actions">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Supplier
                    </button>
                </div>
            </form>
        </article>

        <article class="panel table-panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Suppliers</p>
                    <h2>Vendor records</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Products</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($suppliers === []): ?>
                            <tr>
                                <td colspan="7">No suppliers found.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td><strong class="table-title"><?php echo e($supplier['name']); ?></strong></td>
                                <td><?php echo e($supplier['contact_person'] ?? ''); ?></td>
                                <td><?php echo e($supplier['phone'] ?? ''); ?></td>
                                <td><?php echo e($supplier['email'] ?? ''); ?></td>
                                <td><?php echo (int) $supplier['product_count']; ?></td>
                                <td><span class="status status-<?php echo (int) $supplier['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $supplier['is_active'] === 1 ? 'Active' : 'Archived'; ?></span></td>
                                <td><?php render_master_actions('supplier', (int) $supplier['id'], (int) $supplier['is_active'], 'suppliers'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
<?php endif; ?>

<?php
function render_master_actions(string $entity, int $id, int $isActive, string $section): void
{
    ?>
    <div class="table-actions">
        <a class="icon-button" href="<?php echo e(app_url('?page=inventory-setup&section=' . $section . '&edit_type=' . $entity . '&edit_id=' . $id)); ?>" aria-label="Edit">
            <i data-lucide="pencil"></i>
        </a>
        <?php if ($isActive === 1): ?>
            <form method="post" action="<?php echo e(app_url('actions/master_archive.php')); ?>" data-confirm="Archive this record? Products will keep their history.">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="entity" value="<?php echo e($entity); ?>">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <button class="icon-button danger-button" type="submit" aria-label="Archive">
                    <i data-lucide="archive"></i>
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}
