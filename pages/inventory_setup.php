<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$section = (string) ($_GET['section'] ?? 'categories');
$setupSearch = trim((string) ($_GET['q'] ?? ''));
$validSections = ['categories', 'brands', 'suppliers'];
$setupPageSize = 50;
$setupPageNumber = max(1, (int) ($_GET['p'] ?? 1));

if (! in_array($section, $validSections, true)) {
    $section = 'categories';
}

$editType = (string) ($_GET['edit_type'] ?? '');
$editId = (int) ($_GET['edit_id'] ?? 0);

if ($editId > 0) {
    $section = match ($editType) {
        'brand' => 'brands',
        'supplier' => 'suppliers',
        'category' => 'categories',
        default => $section,
    };
}

$categories = [];
$brands = [];
$suppliers = [];
$setupTotals = [
    'categories' => 0,
    'brands' => 0,
    'suppliers' => 0,
];
$setupTotalPages = [
    'categories' => 1,
    'brands' => 1,
    'suppliers' => 1,
];
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
    $categoryCountSql = 'SELECT COUNT(*) FROM categories c';
    $categoryParams = [];

    if ($setupSearch !== '') {
        $categoryCountSql .= ' WHERE c.name LIKE :search OR c.description LIKE :search';
        $categoryParams['search'] = '%' . $setupSearch . '%';
    }

    $categoryCountStatement = $pdo->prepare($categoryCountSql);
    $categoryCountStatement->execute($categoryParams);
    $setupTotals['categories'] = (int) $categoryCountStatement->fetchColumn();

    $brandCountSql = 'SELECT COUNT(*) FROM brands b';
    $brandParams = [];

    if ($setupSearch !== '') {
        $brandCountSql .= ' WHERE b.name LIKE :search';
        $brandParams['search'] = '%' . $setupSearch . '%';
    }

    $brandCountStatement = $pdo->prepare($brandCountSql);
    $brandCountStatement->execute($brandParams);
    $setupTotals['brands'] = (int) $brandCountStatement->fetchColumn();

    $supplierCountSql = 'SELECT COUNT(*) FROM suppliers s';
    $supplierParams = [];

    if ($setupSearch !== '') {
        $supplierCountSql .= ' WHERE s.name LIKE :search OR s.contact_person LIKE :search OR s.phone LIKE :search OR s.email LIKE :search';
        $supplierParams['search'] = '%' . $setupSearch . '%';
    }

    $supplierCountStatement = $pdo->prepare($supplierCountSql);
    $supplierCountStatement->execute($supplierParams);
    $setupTotals['suppliers'] = (int) $supplierCountStatement->fetchColumn();

    foreach ($setupTotals as $setupSection => $setupTotal) {
        $setupTotalPages[$setupSection] = max(1, (int) ceil($setupTotal / $setupPageSize));
    }

    $setupPageNumber = min($setupPageNumber, $setupTotalPages[$section]);
    $setupOffset = ($setupPageNumber - 1) * $setupPageSize;

    $categorySql = 'SELECT c.*,
                           COUNT(p.id) AS product_count
                    FROM categories c
                    LEFT JOIN products p ON p.category_id = c.id';

    if ($setupSearch !== '') {
        $categorySql .= ' WHERE c.name LIKE :search OR c.description LIKE :search';
    }

    $categorySql .= ' GROUP BY c.id ORDER BY c.is_active DESC, c.name ASC LIMIT :limit OFFSET :offset';
    $categoryStatement = $pdo->prepare($categorySql);
    foreach ($categoryParams as $key => $value) {
        $categoryStatement->bindValue(':' . $key, $value);
    }
    $categoryStatement->bindValue(':limit', $setupPageSize, PDO::PARAM_INT);
    $categoryStatement->bindValue(':offset', $setupOffset, PDO::PARAM_INT);
    $categoryStatement->execute();
    $categories = $categoryStatement->fetchAll();

    $brandSql = 'SELECT b.*,
                        COUNT(p.id) AS product_count
                 FROM brands b
                 LEFT JOIN products p ON p.brand_id = b.id';

    if ($setupSearch !== '') {
        $brandSql .= ' WHERE b.name LIKE :search';
    }

    $brandSql .= ' GROUP BY b.id ORDER BY b.is_active DESC, b.name ASC LIMIT :limit OFFSET :offset';
    $brandStatement = $pdo->prepare($brandSql);
    foreach ($brandParams as $key => $value) {
        $brandStatement->bindValue(':' . $key, $value);
    }
    $brandStatement->bindValue(':limit', $setupPageSize, PDO::PARAM_INT);
    $brandStatement->bindValue(':offset', $setupOffset, PDO::PARAM_INT);
    $brandStatement->execute();
    $brands = $brandStatement->fetchAll();

    $supplierSql = 'SELECT s.*,
                           COUNT(p.id) AS product_count
                    FROM suppliers s
                    LEFT JOIN products p ON p.supplier_id = s.id';

    if ($setupSearch !== '') {
        $supplierSql .= ' WHERE s.name LIKE :search OR s.contact_person LIKE :search OR s.phone LIKE :search OR s.email LIKE :search';
    }

    $supplierSql .= ' GROUP BY s.id ORDER BY s.is_active DESC, s.name ASC LIMIT :limit OFFSET :offset';
    $supplierStatement = $pdo->prepare($supplierSql);
    foreach ($supplierParams as $key => $value) {
        $supplierStatement->bindValue(':' . $key, $value);
    }
    $supplierStatement->bindValue(':limit', $setupPageSize, PDO::PARAM_INT);
    $supplierStatement->bindValue(':offset', $setupOffset, PDO::PARAM_INT);
    $supplierStatement->execute();
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
        <h1>Inventory Setup</h1>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=products')); ?>">
        <i data-lucide="package-plus"></i>
        Products
    </a>
</div>

<?php if ($dbReady): ?>
<section class="stats-grid compact-stats" aria-label="Inventory setup summary">
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
        <div class="stat-icon"><i data-lucide="badge"></i></div>
        <small>Active manufacturers</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Suppliers</span>
            <strong><?php echo (int) $summary['suppliers']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="truck"></i></div>
        <small>Active vendors</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Linked Products</span>
            <strong><?php echo (int) $summary['linked_products']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="package-check"></i></div>
        <small>Using setup records</small>
    </article>
</section>
<?php endif; ?>

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
                    <h2><?php echo $editingCategory === null ? 'Add Category' : 'Edit Category'; ?></h2>
                </div>
                <?php if ($editingCategory !== null): ?>
                    <a class="muted-link" href="<?php echo e(app_url('?' . setup_page_query('categories', $setupPageNumber, $setupSearch))); ?>">Cancel edit</a>
                <?php endif; ?>
            </div>

            <form class="product-form single-form" method="post" action="<?php echo e(app_url('actions/master_save.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="entity" value="category">
                <input type="hidden" name="id" value="<?php echo e($editingCategory['id'] ?? ''); ?>">

                <label class="field">
                    <span>Category Name</span>
                    <input type="text" name="name" value="<?php echo e($editingCategory['name'] ?? ''); ?>" placeholder="Category name" required>
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
                                <td><span class="status status-<?php echo (int) $category['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $category['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td><?php render_master_actions('category', (int) $category['id'], (int) $category['is_active'], 'categories'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php render_setup_pagination('categories', $setupPageNumber, $setupTotalPages['categories'], $setupSearch); ?>
        </article>
    </section>
<?php endif; ?>

<?php if ($dbReady && $section === 'brands'): ?>
    <section class="setup-grid">
        <article class="panel form-panel">
            <div class="panel-header">
                <div>
                    <h2><?php echo $editingBrand === null ? 'Add Brand' : 'Edit Brand'; ?></h2>
                </div>
                <?php if ($editingBrand !== null): ?>
                    <a class="muted-link" href="<?php echo e(app_url('?' . setup_page_query('brands', $setupPageNumber, $setupSearch))); ?>">Cancel edit</a>
                <?php endif; ?>
            </div>

            <form class="product-form single-form" method="post" action="<?php echo e(app_url('actions/master_save.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="entity" value="brand">
                <input type="hidden" name="id" value="<?php echo e($editingBrand['id'] ?? ''); ?>">

                <label class="field">
                    <span>Brand Name</span>
                    <input type="text" name="name" value="<?php echo e($editingBrand['name'] ?? ''); ?>" placeholder="Brand name" required>
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
                                <td><span class="status status-<?php echo (int) $brand['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $brand['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td><?php render_master_actions('brand', (int) $brand['id'], (int) $brand['is_active'], 'brands'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php render_setup_pagination('brands', $setupPageNumber, $setupTotalPages['brands'], $setupSearch); ?>
        </article>
    </section>
<?php endif; ?>

<?php if ($dbReady && $section === 'suppliers'): ?>
    <section class="setup-grid">
        <article class="panel form-panel">
            <div class="panel-header">
                <div>
                    <h2><?php echo $editingSupplier === null ? 'Add Supplier' : 'Edit Supplier'; ?></h2>
                </div>
                <?php if ($editingSupplier !== null): ?>
                    <a class="muted-link" href="<?php echo e(app_url('?' . setup_page_query('suppliers', $setupPageNumber, $setupSearch))); ?>">Cancel edit</a>
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
                                <td><span class="status status-<?php echo (int) $supplier['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $supplier['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td><?php render_master_actions('supplier', (int) $supplier['id'], (int) $supplier['is_active'], 'suppliers'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php render_setup_pagination('suppliers', $setupPageNumber, $setupTotalPages['suppliers'], $setupSearch); ?>
        </article>
    </section>
<?php endif; ?>

<?php
function render_setup_pagination(string $section, int $pageNumber, int $totalPages, string $setupSearch): void
{
    if ($totalPages <= 1) {
        return;
    }

    $previousQuery = setup_page_query($section, $pageNumber - 1, $setupSearch);
    $nextQuery = setup_page_query($section, $pageNumber + 1, $setupSearch);
    ?>
    <div class="pagination-row product-pagination" aria-label="<?php echo e(ucfirst($section) . ' pages'); ?>">
        <?php if ($pageNumber <= 1): ?>
            <span class="product-page-button disabled">Previous</span>
        <?php else: ?>
            <a class="product-page-button" href="<?php echo e(app_url('?' . $previousQuery)); ?>">Previous</a>
        <?php endif; ?>

        <?php foreach (setup_pagination_pages($pageNumber, $totalPages) as $paginationPage): ?>
            <?php if ($paginationPage === 'ellipsis'): ?>
                <span class="product-page-ellipsis">...</span>
            <?php elseif ((int) $paginationPage === $pageNumber): ?>
                <span class="product-page-button active" aria-current="page"><?php echo (int) $paginationPage; ?></span>
            <?php else: ?>
                <a class="product-page-button" href="<?php echo e(app_url('?' . setup_page_query($section, (int) $paginationPage, $setupSearch))); ?>"><?php echo (int) $paginationPage; ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($pageNumber >= $totalPages): ?>
            <span class="product-page-button disabled">Next</span>
        <?php else: ?>
            <a class="product-page-button" href="<?php echo e(app_url('?' . $nextQuery)); ?>">Next</a>
        <?php endif; ?>
    </div>
    <?php
}

function setup_page_query(string $section, int $pageNumber, string $setupSearch): string
{
    $query = [
        'page' => 'inventory-setup',
        'section' => $section,
        'p' => max(1, $pageNumber),
    ];

    if ($setupSearch !== '') {
        $query['q'] = $setupSearch;
    }

    return http_build_query($query);
}

function setup_pagination_pages(int $pageNumber, int $totalPages): array
{
    if ($totalPages <= 7) {
        return range(1, $totalPages);
    }

    $pages = [1];
    $start = max(2, $pageNumber - 1);
    $end = min($totalPages - 1, $pageNumber + 1);

    if ($pageNumber <= 3) {
        $start = 2;
        $end = 4;
    } elseif ($pageNumber >= $totalPages - 2) {
        $start = $totalPages - 3;
        $end = $totalPages - 1;
    }

    if ($start > 2) {
        $pages[] = 'ellipsis';
    }

    for ($page = $start; $page <= $end; $page++) {
        $pages[] = $page;
    }

    if ($end < $totalPages - 1) {
        $pages[] = 'ellipsis';
    }

    $pages[] = $totalPages;

    return $pages;
}

function render_master_actions(string $entity, int $id, int $isActive, string $section): void
{
    $editQuery = [
        'page' => 'inventory-setup',
        'section' => $section,
        'edit_type' => $entity,
        'edit_id' => $id,
    ];

    if (isset($_GET['p']) && (int) $_GET['p'] > 1) {
        $editQuery['p'] = max(1, (int) $_GET['p']);
    }

    if (isset($_GET['q']) && trim((string) $_GET['q']) !== '') {
        $editQuery['q'] = trim((string) $_GET['q']);
    }

    ?>
    <div class="table-actions">
        <a class="icon-button" href="<?php echo e(app_url('?' . http_build_query($editQuery))); ?>" aria-label="Edit">
            <i data-lucide="pencil"></i>
        </a>
        <form method="post" action="<?php echo e(app_url('actions/master_archive.php')); ?>" data-confirm="Delete this record permanently? Linked products and history will keep working, but this setup label will be removed.">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="entity" value="<?php echo e($entity); ?>">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <button class="icon-button danger-button" type="submit" aria-label="Delete">
                <i data-lucide="trash-2"></i>
            </button>
        </form>
    </div>
    <?php
}
