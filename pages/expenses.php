<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$expenseSearch = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$statusFilter = (string) ($_GET['expense_status'] ?? 'active');
$startDate = expense_valid_date((string) ($_GET['start_date'] ?? date('Y-m-01')), date('Y-m-01'));
$endDate = expense_valid_date((string) ($_GET['end_date'] ?? date('Y-m-d')), date('Y-m-d'));
$allowedStatuses = ['active', 'voided', 'all'];

if (! in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'active';
}

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$categories = [
    'Rent',
    'Utilities',
    'Salary',
    'Transport',
    'Internet',
    'Marketing',
    'Repairs',
    'Stationery',
    'Bank Charges',
    'Other',
];
$expenses = [];
$categoryRows = [];
$summary = [
    'period_total' => 0.0,
    'today_total' => 0.0,
    'month_total' => 0.0,
    'expense_count' => 0,
];

if ($dbReady && $pdo !== null) {
    $existingCategories = $pdo->query(
        'SELECT DISTINCT category
         FROM expenses
         WHERE category <> ""
         ORDER BY category ASC'
    )->fetchAll(PDO::FETCH_COLUMN);
    $categories = array_values(array_unique(array_merge($categories, array_map('strval', $existingCategories))));
    sort($categories);

    $periodStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS period_total,
                COUNT(*) AS expense_count
         FROM expenses
         WHERE status = "active"
           AND expense_date BETWEEN :start_date AND :end_date'
    );
    $periodStatement->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);
    $periodRow = $periodStatement->fetch() ?: [];
    $summary['period_total'] = (float) ($periodRow['period_total'] ?? 0);
    $summary['expense_count'] = (int) ($periodRow['expense_count'] ?? 0);
    $summary['today_total'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = "active" AND expense_date = CURRENT_DATE')->fetchColumn();
    $summary['month_total'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE status = "active" AND expense_date >= DATE_FORMAT(CURRENT_DATE, "%Y-%m-01") AND expense_date <= CURRENT_DATE')->fetchColumn();

    $where = ['expense_date BETWEEN :start_date AND :end_date'];
    $params = [
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];

    if ($statusFilter !== 'all') {
        $where[] = 'e.status = :status';
        $params['status'] = $statusFilter;
    }

    if ($categoryFilter !== '') {
        $where[] = 'e.category = :category';
        $params['category'] = $categoryFilter;
    }

    if ($expenseSearch !== '') {
        $where[] = '(e.category LIKE :search OR e.vendor LIKE :search OR e.reference_no LIKE :search OR e.notes LIKE :search)';
        $params['search'] = '%' . $expenseSearch . '%';
    }

    $expenseSql = 'SELECT e.*,
                          u.full_name AS created_by_name
                   FROM expenses e
                   LEFT JOIN users u ON u.id = e.created_by
                   WHERE ' . implode(' AND ', $where) . '
                   ORDER BY e.expense_date DESC, e.id DESC
                   LIMIT 100';
    $expenseStatement = $pdo->prepare($expenseSql);
    $expenseStatement->execute($params);
    $expenses = $expenseStatement->fetchAll();

    $categoryStatement = $pdo->prepare(
        'SELECT category,
                COUNT(*) AS expense_count,
                COALESCE(SUM(amount), 0) AS total_amount
         FROM expenses
         WHERE status = "active"
           AND expense_date BETWEEN :start_date AND :end_date
         GROUP BY category
         ORDER BY total_amount DESC
         LIMIT 10'
    );
    $categoryStatement->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);
    $categoryRows = $categoryStatement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Operating costs</p>
        <h1>Expense Management</h1>
    </div>
    <a class="top-action" href="#expense-form">
        <i data-lucide="receipt"></i>
        New Expense
    </a>
</div>

<section class="stats-grid compact-stats" aria-label="Expense summary">
    <article class="stat-card">
        <div>
            <span>Selected Period</span>
            <strong><?php echo e(format_money($summary['period_total'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="calendar-range"></i></div>
        <small><?php echo (int) $summary['expense_count']; ?> active expense(s)</small>
    </article>
    <article class="stat-card">
        <div>
            <span>This Month</span>
            <strong><?php echo e(format_money($summary['month_total'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="receipt"></i></div>
        <small>Active expenses</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Today</span>
            <strong><?php echo e(format_money($summary['today_total'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="wallet-cards"></i></div>
        <small>Paid today</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Top Category</span>
            <strong><?php echo e($categoryRows[0]['category'] ?? 'None'); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="tags"></i></div>
        <small><?php echo e(isset($categoryRows[0]) ? format_money($categoryRows[0]['total_amount']) : format_money(0)); ?></small>
    </article>
</section>

<section class="expense-layout">
    <article class="panel" id="expense-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Expense Entry</p>
                <h2>Record operating cost</h2>
            </div>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before saving expenses.</p>
        <?php else: ?>
            <form class="expense-form" method="post" action="<?php echo e(app_url('actions/expense_save.php')); ?>">
                <?php echo csrf_field(); ?>

                <label class="field">
                    <span>Date</span>
                    <input type="date" name="expense_date" value="<?php echo e(date('Y-m-d')); ?>" required>
                </label>

                <label class="field">
                    <span>Category</span>
                    <input type="text" name="category" list="expense-categories" maxlength="80" placeholder="Rent, salary, transport..." required>
                    <datalist id="expense-categories">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo e($category); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>

                <label class="field">
                    <span>Vendor / Payee</span>
                    <input type="text" name="vendor" maxlength="160" placeholder="Optional">
                </label>

                <label class="field">
                    <span>Amount</span>
                    <input type="number" name="amount" value="0.00" min="0" step="0.01" required>
                </label>

                <label class="field">
                    <span>Payment Method</span>
                    <select name="payment_method">
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="cheque">Cheque</option>
                        <option value="online">Online</option>
                    </select>
                </label>

                <label class="field">
                    <span>Reference No</span>
                    <input type="text" name="reference_no" maxlength="100" placeholder="Receipt, bill, cheque no">
                </label>

                <label class="field span-2">
                    <span>Notes</span>
                    <textarea name="notes" rows="3" placeholder="Optional expense note"></textarea>
                </label>

                <div class="form-actions span-2">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Expense
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Expense Ledger</p>
                <h2>Recorded expenses</h2>
            </div>

            <form class="expense-filter-form" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="expenses">
                <input type="search" name="q" value="<?php echo e($expenseSearch); ?>" placeholder="Category, vendor, reference">
                <select name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo e($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo e($category); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="expense_status">
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="voided" <?php echo $statusFilter === 'voided' ? 'selected' : ''; ?>>Voided</option>
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                </select>
                <input type="date" name="start_date" value="<?php echo e($startDate); ?>">
                <input type="date" name="end_date" value="<?php echo e($endDate); ?>">
                <button class="icon-button" type="submit" aria-label="Apply filters">
                    <i data-lucide="search"></i>
                </button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Vendor</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>By</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($expenses === []): ?>
                        <tr>
                            <td colspan="9">No expenses found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d', strtotime((string) $expense['expense_date']))); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($expense['category']); ?></strong>
                                <span class="table-subtitle"><?php echo e($expense['notes'] ?? ''); ?></span>
                            </td>
                            <td><?php echo e($expense['vendor'] ?? ''); ?></td>
                            <td><?php echo e(ucfirst((string) $expense['payment_method'])); ?></td>
                            <td><?php echo e($expense['reference_no'] ?? ''); ?></td>
                            <td class="<?php echo (string) $expense['status'] === 'active' ? 'text-danger' : ''; ?>"><?php echo e(format_money($expense['amount'])); ?></td>
                            <td><span class="status <?php echo (string) $expense['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo e(ucfirst((string) $expense['status'])); ?></span></td>
                            <td><?php echo e($expense['created_by_name'] ?? ''); ?></td>
                            <td>
                                <?php if ((string) $expense['status'] === 'active'): ?>
                                    <form method="post" action="<?php echo e(app_url('actions/expense_void.php')); ?>" data-confirm="Void this expense? Audit history will be kept.">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="expense_id" value="<?php echo (int) $expense['id']; ?>">
                                        <button class="icon-button danger-button" type="submit" aria-label="Void expense">
                                            <i data-lucide="ban"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted-link">Voided</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Category Summary</p>
                <h2>Selected period</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Entries</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categoryRows === []): ?>
                        <tr>
                            <td colspan="3">No active expenses in this period.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($categoryRows as $row): ?>
                        <tr>
                            <td><?php echo e($row['category']); ?></td>
                            <td><?php echo (int) $row['expense_count']; ?></td>
                            <td class="text-danger"><?php echo e(format_money($row['total_amount'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php
function expense_valid_date(string $value, string $fallback): string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
}
