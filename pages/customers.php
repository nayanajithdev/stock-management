<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$customerSearch = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['customer_status'] ?? 'active');
$allowedStatuses = ['active', 'inactive', 'all'];

if (! in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'active';
}

$customers = [];
$editingCustomer = null;
$summary = [
    'active_customers' => 0,
    'credit_customers' => 0,
    'receivable' => 0.0,
    'lifetime_sales' => 0.0,
];

if ($dbReady && $pdo !== null) {
    $summary['active_customers'] = (int) $pdo->query('SELECT COUNT(*) FROM customers WHERE is_active = 1')->fetchColumn();
    $summary['credit_customers'] = (int) $pdo->query(
        'SELECT COUNT(DISTINCT customer_id)
         FROM sales
         WHERE customer_id IS NOT NULL
           AND total > paid'
    )->fetchColumn();
    $summary['receivable'] = (float) $pdo->query('SELECT COALESCE(SUM(total - paid), 0) FROM sales WHERE total > paid')->fetchColumn();
    $summary['lifetime_sales'] = (float) $pdo->query('SELECT COALESCE(SUM(total), 0) FROM sales')->fetchColumn();

    $customerSql = 'SELECT c.*,
                           COUNT(s.id) AS order_count,
                           COALESCE(SUM(s.total), 0) AS total_sales,
                           COALESCE(SUM(s.paid), 0) AS total_paid,
                           COALESCE(SUM(s.total - s.paid), 0) AS balance
                    FROM customers c
                    LEFT JOIN sales s ON s.customer_id = c.id';
    $where = [];
    $params = [];

    if ($statusFilter !== 'all') {
        $where[] = 'c.is_active = :is_active';
        $params['is_active'] = $statusFilter === 'active' ? 1 : 0;
    }

    if ($customerSearch !== '') {
        $where[] = '(c.name LIKE :search OR c.phone LIKE :search OR c.email LIKE :search)';
        $params['search'] = '%' . $customerSearch . '%';
    }

    if ($where !== []) {
        $customerSql .= ' WHERE ' . implode(' AND ', $where);
    }

    $customerSql .= ' GROUP BY c.id ORDER BY c.is_active DESC, c.name ASC';
    $customerStatement = $pdo->prepare($customerSql);
    $customerStatement->execute($params);
    $customers = $customerStatement->fetchAll();

    if (isset($_GET['edit'])) {
        $editStatement = $pdo->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
        $editStatement->execute(['id' => (int) $_GET['edit']]);
        $editingCustomer = $editStatement->fetch() ?: null;
    }
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Customer accounts</p>
        <h1>Customers</h1>
    </div>
    <a class="top-action" href="#customer-form">
        <i data-lucide="user-plus"></i>
        Add Customer
    </a>
</div>

<section class="stats-grid compact-stats" aria-label="Customer summary">
    <article class="stat-card">
        <div>
            <span>Active Customers</span>
            <strong><?php echo (int) $summary['active_customers']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="users"></i></div>
        <small>Available for POS</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Credit Customers</span>
            <strong><?php echo (int) $summary['credit_customers']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="receipt-text"></i></div>
        <small>Have unpaid invoices</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Receivable</span>
            <strong><?php echo e(format_money($summary['receivable'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="wallet"></i></div>
        <small>Total customer balance</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Lifetime Sales</span>
            <strong><?php echo e(format_money($summary['lifetime_sales'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="badge-dollar-sign"></i></div>
        <small>All invoices</small>
    </article>
</section>

<section class="customer-layout">
    <article class="panel form-panel" id="customer-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Customer Profile</p>
                <h2><?php echo $editingCustomer === null ? 'Add Customer' : 'Edit Customer'; ?></h2>
            </div>
            <?php if ($editingCustomer !== null): ?>
                <a class="muted-link" href="<?php echo e(app_url('?page=customers')); ?>">Cancel edit</a>
            <?php endif; ?>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before saving customers.</p>
        <?php else: ?>
            <form class="product-form single-form" method="post" action="<?php echo e(app_url('actions/customer_save.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="customer_id" value="<?php echo e($editingCustomer['id'] ?? ''); ?>">

                <label class="field">
                    <span>Name</span>
                    <input type="text" name="name" value="<?php echo e($editingCustomer['name'] ?? ''); ?>" placeholder="Customer name" required>
                </label>
                <label class="field">
                    <span>Phone</span>
                    <input type="text" name="phone" value="<?php echo e($editingCustomer['phone'] ?? ''); ?>" placeholder="0770000000">
                </label>
                <label class="field">
                    <span>Email</span>
                    <input type="email" name="email" value="<?php echo e($editingCustomer['email'] ?? ''); ?>" placeholder="customer@example.com">
                </label>
                <label class="field">
                    <span>Credit Limit</span>
                    <input type="number" name="credit_limit" value="<?php echo e($editingCustomer['credit_limit'] ?? '0.00'); ?>" min="0" step="0.01">
                </label>
                <label class="field">
                    <span>Address</span>
                    <textarea name="address" rows="4" placeholder="Customer address"><?php echo e($editingCustomer['address'] ?? ''); ?></textarea>
                </label>
                <div class="form-actions">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Customer
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Customer Ledger</p>
                <h2>Accounts and balances</h2>
            </div>

            <form class="filter-row movement-filter" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="customers">
                <input type="search" name="q" value="<?php echo e($customerSearch); ?>" placeholder="Search customers">
                <select name="customer_status">
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
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Orders</th>
                        <th>Total Sales</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Limit</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($customers === []): ?>
                        <tr>
                            <td colspan="10">No customers found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($customers as $customer): ?>
                        <?php $balance = (float) $customer['balance']; ?>
                        <tr>
                            <td><strong class="table-title"><?php echo e($customer['name']); ?></strong></td>
                            <td><?php echo e($customer['phone'] ?? ''); ?></td>
                            <td><?php echo e($customer['email'] ?? ''); ?></td>
                            <td><?php echo (int) $customer['order_count']; ?></td>
                            <td><?php echo e(format_money($customer['total_sales'])); ?></td>
                            <td><?php echo e(format_money($customer['total_paid'])); ?></td>
                            <td class="<?php echo $balance > 0 ? 'text-danger' : ''; ?>"><?php echo e(format_money($balance)); ?></td>
                            <td><?php echo e(format_money($customer['credit_limit'])); ?></td>
                            <td><span class="status status-<?php echo (int) $customer['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int) $customer['is_active'] === 1 ? 'Active' : 'Archived'; ?></span></td>
                            <td>
                                <div class="table-actions">
                                    <a class="icon-button" href="<?php echo e(app_url('?page=customers&edit=' . (int) $customer['id'])); ?>" aria-label="Edit customer">
                                        <i data-lucide="pencil"></i>
                                    </a>
                                    <a class="icon-button" href="<?php echo e(app_url('?page=credit-sales&q=' . rawurlencode((string) $customer['phone']))); ?>" aria-label="View credit sales">
                                        <i data-lucide="receipt-text"></i>
                                    </a>
                                    <?php if ((int) $customer['is_active'] === 1): ?>
                                        <form method="post" action="<?php echo e(app_url('actions/customer_archive.php')); ?>" data-confirm="Archive this customer? Sales history will be kept.">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="customer_id" value="<?php echo (int) $customer['id']; ?>">
                                            <button class="icon-button danger-button" type="submit" aria-label="Archive customer">
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
