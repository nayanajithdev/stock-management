<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$creditSearch = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['credit_status'] ?? 'open');
$collectSaleId = (int) ($_GET['collect'] ?? 0);
$allowedStatuses = ['open', 'partial', 'credit', 'all'];

if (! in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'open';
}

$creditSales = [];
$topCustomers = [];
$openInvoices = [];
$recentPayments = [];
$collectingSale = null;
$summary = [
    'open_invoices' => 0,
    'receivable' => 0.0,
    'partial_invoices' => 0,
    'collected_today' => 0.0,
];

if ($dbReady && $pdo !== null) {
    $summary['open_invoices'] = (int) $pdo->query('SELECT COUNT(*) FROM sales WHERE total > paid')->fetchColumn();
    $summary['receivable'] = (float) $pdo->query('SELECT COALESCE(SUM(total - paid), 0) FROM sales WHERE total > paid')->fetchColumn();
    $summary['partial_invoices'] = (int) $pdo->query('SELECT COUNT(*) FROM sales WHERE total > paid AND paid > 0')->fetchColumn();
    $summary['collected_today'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM customer_payments WHERE DATE(payment_date) = CURRENT_DATE')->fetchColumn();

    $openInvoices = $pdo->query(
        'SELECT s.id,
                s.invoice_no,
                s.sale_date,
                s.total,
                s.paid,
                c.name AS customer_name,
                c.phone AS customer_phone
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         WHERE s.total > s.paid
         ORDER BY s.sale_date DESC, s.id DESC'
    )->fetchAll();

    if ($collectSaleId > 0) {
        $collectStatement = $pdo->prepare(
            'SELECT s.id,
                    s.invoice_no,
                    s.total,
                    s.paid,
                    c.name AS customer_name,
                    c.phone AS customer_phone
             FROM sales s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $collectStatement->execute(['id' => $collectSaleId]);
        $collectingSale = $collectStatement->fetch() ?: null;
    }

    $creditSql = 'SELECT s.*,
                         c.name AS customer_name,
                         c.phone AS customer_phone,
                         c.credit_limit,
                         COUNT(si.id) AS item_count,
                         COALESCE(SUM(si.quantity), 0) AS total_units
                  FROM sales s
                  LEFT JOIN customers c ON c.id = s.customer_id
                  LEFT JOIN sale_items si ON si.sale_id = s.id';
    $where = [];
    $params = [];

    if ($statusFilter === 'open') {
        $where[] = 's.total > s.paid';
    } elseif ($statusFilter === 'partial') {
        $where[] = 's.total > s.paid AND s.paid > 0';
    } elseif ($statusFilter === 'credit') {
        $where[] = 's.total > s.paid AND s.paid <= 0';
    }

    if ($creditSearch !== '') {
        $where[] = '(s.invoice_no LIKE :search OR c.name LIKE :search OR c.phone LIKE :search)';
        $params['search'] = '%' . $creditSearch . '%';
    }

    if ($where !== []) {
        $creditSql .= ' WHERE ' . implode(' AND ', $where);
    }

    $creditSql .= ' GROUP BY s.id ORDER BY s.sale_date DESC, s.id DESC LIMIT 100';
    $creditStatement = $pdo->prepare($creditSql);
    $creditStatement->execute($params);
    $creditSales = $creditStatement->fetchAll();

    $topCustomers = $pdo->query(
        'SELECT c.id,
                c.name,
                c.phone,
                c.credit_limit,
                COUNT(s.id) AS open_invoices,
                COALESCE(SUM(s.total - s.paid), 0) AS balance
         FROM customers c
         INNER JOIN sales s ON s.customer_id = c.id
         WHERE s.total > s.paid
         GROUP BY c.id
         ORDER BY balance DESC
         LIMIT 8'
    )->fetchAll();

    $recentPayments = $pdo->query(
        'SELECT cp.*,
                s.invoice_no,
                c.name AS customer_name,
                c.phone AS customer_phone
         FROM customer_payments cp
         INNER JOIN sales s ON s.id = cp.sale_id
         LEFT JOIN customers c ON c.id = cp.customer_id
         ORDER BY cp.payment_date DESC, cp.id DESC
         LIMIT 20'
    )->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Receivables</p>
        <h1>Credit Sales</h1>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=sales')); ?>">
        <i data-lucide="circle-dollar-sign"></i>
        Collect Payment
    </a>
</div>

<section class="stats-grid compact-stats" aria-label="Credit sales summary">
    <article class="stat-card">
        <div>
            <span>Open Invoices</span>
            <strong><?php echo (int) $summary['open_invoices']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="receipt-text"></i></div>
        <small>Unpaid or partial</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Receivable</span>
            <strong><?php echo e(format_money($summary['receivable'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="wallet"></i></div>
        <small>Total amount due</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Partial Paid</span>
            <strong><?php echo (int) $summary['partial_invoices']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="circle-dollar-sign"></i></div>
        <small>Some payment received</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Collected Today</span>
            <strong><?php echo e(format_money($summary['collected_today'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="circle-dollar-sign"></i></div>
        <small>Later payments</small>
    </article>
</section>

<section class="credit-layout">
    <article class="panel" id="payment-collection-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Payment Collection</p>
                <h2>Record customer payment</h2>
            </div>
            <a class="muted-link" href="<?php echo e(app_url('?page=customers')); ?>">Customer accounts</a>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before collecting payments.</p>
        <?php elseif ($openInvoices === []): ?>
            <p class="empty-state">There are no unpaid invoices to collect.</p>
        <?php else: ?>
            <form class="collection-form" method="post" action="<?php echo e(app_url('actions/payment_collect.php')); ?>" data-collection-form>
                <?php echo csrf_field(); ?>

                <label class="field">
                    <span>Invoice</span>
                    <select name="sale_id" data-collection-invoice required>
                        <option value="">Choose unpaid invoice</option>
                        <?php foreach ($openInvoices as $invoice): ?>
                            <?php
                            $balance = (float) $invoice['total'] - (float) $invoice['paid'];
                            $label = $invoice['invoice_no'] . ' / ' . ($invoice['customer_name'] ?: 'Walk-in Customer') . ' / Balance ' . format_money($balance);
                            ?>
                            <option
                                value="<?php echo (int) $invoice['id']; ?>"
                                data-balance="<?php echo e($balance); ?>"
                                <?php echo (int) ($collectingSale['id'] ?? 0) === (int) $invoice['id'] ? 'selected' : ''; ?>
                            >
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="collection-balance">
                    <span>Invoice Balance</span>
                    <strong data-collection-balance><?php echo $collectingSale !== null ? e(format_money((float) $collectingSale['total'] - (float) $collectingSale['paid'])) : 'Choose invoice'; ?></strong>
                </div>

                <label class="field">
                    <span>Payment Amount</span>
                    <input type="number" name="amount" value="<?php echo $collectingSale !== null ? e(number_format((float) $collectingSale['total'] - (float) $collectingSale['paid'], 2, '.', '')) : '0.00'; ?>" min="0" step="0.01" data-collection-amount required>
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
                    <span>Payment Date</span>
                    <input type="datetime-local" name="payment_date" value="<?php echo e(date('Y-m-d\TH:i')); ?>" required>
                </label>

                <label class="field span-2">
                    <span>Notes</span>
                    <textarea name="notes" rows="3" placeholder="Optional receipt note"></textarea>
                </label>

                <div class="collection-preview span-2">
                    <i data-lucide="activity"></i>
                    <span data-collection-preview>Select an invoice to preview the remaining balance.</span>
                </div>

                <div class="form-actions span-2">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Payment
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Credit Ledger</p>
                <h2>Outstanding invoices</h2>
            </div>

            <form class="filter-row movement-filter" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="credit-sales">
                <input type="search" name="q" value="<?php echo e($creditSearch); ?>" placeholder="Invoice, customer, phone">
                <select name="credit_status">
                    <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="partial" <?php echo $statusFilter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="credit" <?php echo $statusFilter === 'credit' ? 'selected' : ''; ?>>Full Credit</option>
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Sales</option>
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
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($creditSales === []): ?>
                        <tr>
                            <td colspan="10">No credit sales found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($creditSales as $sale): ?>
                        <?php $balance = (float) $sale['total'] - (float) $sale['paid']; ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $sale['sale_date']))); ?></td>
                            <td><?php echo e($sale['invoice_no']); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($sale['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                                <span class="table-subtitle"><?php echo e($sale['customer_phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo (int) $sale['item_count']; ?> / <?php echo (int) $sale['total_units']; ?> units</td>
                            <td><?php echo e(format_money($sale['total'])); ?></td>
                            <td><?php echo e(format_money($sale['paid'])); ?></td>
                            <td class="<?php echo $balance > 0 ? 'text-danger' : ''; ?>"><?php echo e(format_money($balance)); ?></td>
                            <td><?php echo e(ucfirst((string) $sale['payment_method'])); ?></td>
                            <td><span class="status <?php echo e(credit_status_class((string) $sale['status'], $balance)); ?>"><?php echo e($balance > 0 ? ucfirst((string) $sale['status']) : 'Closed'); ?></span></td>
                            <td>
                                <?php if ($balance > 0): ?>
                                    <a class="icon-button" href="<?php echo e(app_url('?page=credit-sales&collect=' . (int) $sale['id'] . '#payment-collection-form')); ?>" aria-label="Collect payment">
                                        <i data-lucide="circle-dollar-sign"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="muted-link">Closed</span>
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
                <p class="panel-label">Highest Balances</p>
                <h2>Customers to follow up</h2>
            </div>
            <a class="muted-link" href="<?php echo e(app_url('?page=customers')); ?>">Customers</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Open Invoices</th>
                        <th>Limit</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($topCustomers === []): ?>
                        <tr>
                            <td colspan="4">No customer balances yet.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($topCustomers as $customer): ?>
                        <tr>
                            <td>
                                <strong class="table-title"><?php echo e($customer['name']); ?></strong>
                                <span class="table-subtitle"><?php echo e($customer['phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo (int) $customer['open_invoices']; ?></td>
                            <td><?php echo e(format_money($customer['credit_limit'])); ?></td>
                            <td class="text-danger"><?php echo e(format_money($customer['balance'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Payment History</p>
                <h2>Recent collections</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Method</th>
                        <th>Amount</th>
                        <th>Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recentPayments === []): ?>
                        <tr>
                            <td colspan="7">No payment collections recorded yet.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($recentPayments as $payment): ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $payment['payment_date']))); ?></td>
                            <td><?php echo e($payment['invoice_no']); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($payment['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                                <span class="table-subtitle"><?php echo e($payment['customer_phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo e(ucfirst((string) $payment['payment_method'])); ?></td>
                            <td class="text-good"><?php echo e(format_money($payment['amount'])); ?></td>
                            <td><?php echo e($payment['notes'] ?? ''); ?></td>
                            <td>
                                <a class="icon-button" href="<?php echo e(app_url('?page=payment-receipt&id=' . (int) $payment['id'])); ?>" aria-label="View payment receipt">
                                    <i data-lucide="receipt-text"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php
function credit_status_class(string $status, float $balance): string
{
    if ($balance <= 0) {
        return 'status-active';
    }

    return match ($status) {
        'partial' => 'status-warranty',
        'credit' => 'status-pending',
        default => 'status-inactive',
    };
}
