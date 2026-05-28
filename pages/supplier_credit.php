<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$creditSearch = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['purchase_status'] ?? 'open');
$collectPurchaseId = (int) ($_GET['collect'] ?? 0);
$supplierTab = (string) ($_GET['tab'] ?? 'outstanding');
$allowedStatuses = ['open', 'partial', 'credit', 'all'];
$allowedTabs = ['outstanding', 'payments'];

if (! in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'open';
}

if (! in_array($supplierTab, $allowedTabs, true)) {
    $supplierTab = 'outstanding';
}

$creditPurchases = [];
$openPurchases = [];
$recentPayments = [];
$collectingPurchase = null;
$summary = [
    'open_purchases' => 0,
    'payable' => 0.0,
    'partial_purchases' => 0,
    'paid_today' => 0.0,
];

if ($dbReady && $pdo !== null) {
    $summary['open_purchases'] = (int) $pdo->query('SELECT COUNT(*) FROM purchases WHERE total > paid')->fetchColumn();
    $summary['payable'] = (float) $pdo->query('SELECT COALESCE(SUM(total - paid), 0) FROM purchases WHERE total > paid')->fetchColumn();
    $summary['partial_purchases'] = (int) $pdo->query('SELECT COUNT(*) FROM purchases WHERE total > paid AND paid > 0')->fetchColumn();
    $summary['paid_today'] = (float) $pdo->query('SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE DATE(payment_date) = CURRENT_DATE')->fetchColumn();

    $openPurchases = $pdo->query(
        'SELECT p.id,
                p.invoice_no,
                p.purchase_date,
                p.total,
                p.paid,
                s.name AS supplier_name,
                s.phone AS supplier_phone
         FROM purchases p
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         WHERE p.total > p.paid
         ORDER BY p.purchase_date DESC, p.id DESC'
    )->fetchAll();

    if ($collectPurchaseId > 0) {
        $collectStatement = $pdo->prepare(
            'SELECT p.id,
                    p.invoice_no,
                    p.total,
                    p.paid,
                    s.name AS supplier_name,
                    s.phone AS supplier_phone
             FROM purchases p
             LEFT JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $collectStatement->execute(['id' => $collectPurchaseId]);
        $collectingPurchase = $collectStatement->fetch() ?: null;
    }

    $creditSql = 'SELECT p.*,
                         s.name AS supplier_name,
                         s.phone AS supplier_phone,
                         COUNT(pi.id) AS item_count,
                         COALESCE(SUM(pi.quantity), 0) AS total_units
                  FROM purchases p
                  LEFT JOIN suppliers s ON s.id = p.supplier_id
                  LEFT JOIN purchase_items pi ON pi.purchase_id = p.id';
    $where = [];
    $params = [];

    if ($statusFilter === 'open') {
        $where[] = 'p.total > p.paid';
    } elseif ($statusFilter === 'partial') {
        $where[] = 'p.total > p.paid AND p.paid > 0';
    } elseif ($statusFilter === 'credit') {
        $where[] = 'p.total > p.paid AND p.paid <= 0';
    }

    if ($creditSearch !== '') {
        $where[] = '(p.invoice_no LIKE :search OR s.name LIKE :search OR s.phone LIKE :search)';
        $params['search'] = '%' . $creditSearch . '%';
    }

    if ($where !== []) {
        $creditSql .= ' WHERE ' . implode(' AND ', $where);
    }

    $creditSql .= ' GROUP BY p.id ORDER BY p.purchase_date DESC, p.id DESC LIMIT 100';
    $creditStatement = $pdo->prepare($creditSql);
    $creditStatement->execute($params);
    $creditPurchases = $creditStatement->fetchAll();

    $recentPayments = $pdo->query(
        'SELECT sp.*,
                p.invoice_no,
                s.name AS supplier_name,
                s.phone AS supplier_phone
         FROM supplier_payments sp
         INNER JOIN purchases p ON p.id = sp.purchase_id
         LEFT JOIN suppliers s ON s.id = sp.supplier_id
         ORDER BY sp.payment_date DESC, sp.id DESC
         LIMIT 20'
    )->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <h1>Supplier Credit</h1>
    </div>
</div>

<section class="stats-grid compact-stats" aria-label="Supplier credit summary">
    <article class="stat-card">
        <div>
            <span>Open Purchases</span>
            <strong><?php echo (int) $summary['open_purchases']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="receipt-text"></i></div>
        <small>Unpaid or partial</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Payable</span>
            <strong><?php echo e(format_money($summary['payable'])); ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="wallet"></i></div>
        <small>Total supplier balance</small>
    </article>
</section>

<section class="credit-layout">
    <article class="panel" id="supplier-payment-form">
        <div class="panel-header">
            <div>
                <p class="panel-label">Payment Entry</p>
                <h2>Record supplier payment</h2>
            </div>
            <a class="muted-link" href="<?php echo e(app_url('?page=purchases')); ?>">Purchases</a>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before collecting supplier payments.</p>
        <?php elseif ($openPurchases === []): ?>
            <p class="empty-state">There are no unpaid supplier purchases.</p>
        <?php else: ?>
            <form class="collection-form" method="post" action="<?php echo e(app_url('actions/supplier_payment_collect.php')); ?>" data-collection-form data-collection-label="purchase">
                <?php echo csrf_field(); ?>

                <label class="field">
                    <span>Purchase</span>
                    <select name="purchase_id" data-collection-invoice required>
                        <option value="">Choose unpaid purchase</option>
                        <?php foreach ($openPurchases as $purchase): ?>
                            <?php
                            $balance = (float) $purchase['total'] - (float) $purchase['paid'];
                            $label = ($purchase['invoice_no'] ?: 'Purchase #' . $purchase['id']) . ' / ' . ($purchase['supplier_name'] ?: 'No supplier') . ' / Balance ' . format_money($balance);
                            ?>
                            <option
                                value="<?php echo (int) $purchase['id']; ?>"
                                data-balance="<?php echo e($balance); ?>"
                                <?php echo (int) ($collectingPurchase['id'] ?? 0) === (int) $purchase['id'] ? 'selected' : ''; ?>
                            >
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="collection-balance">
                    <span>Purchase Balance</span>
                    <strong data-collection-balance><?php echo $collectingPurchase !== null ? e(format_money((float) $collectingPurchase['total'] - (float) $collectingPurchase['paid'])) : 'Choose purchase'; ?></strong>
                </div>

                <label class="field">
                    <span>Payment Amount</span>
                    <input type="number" name="amount" value="<?php echo $collectingPurchase !== null ? e(number_format((float) $collectingPurchase['total'] - (float) $collectingPurchase['paid'], 2, '.', '')) : '0.00'; ?>" min="0" step="0.01" data-collection-amount required>
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
                    <textarea name="notes" rows="3" placeholder="Optional supplier receipt note"></textarea>
                </label>

                <div class="collection-preview span-2">
                    <i data-lucide="activity"></i>
                    <span data-collection-preview>Select a purchase to preview the remaining balance.</span>
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

    <div class="ledger-tabs-block">
        <div class="tab-row table-tabs-outside" role="tablist" aria-label="Supplier ledger sections">
            <a class="<?php echo $supplierTab === 'outstanding' ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=supplier-credit&tab=outstanding')); ?>">Outstanding Purchases</a>
            <a class="<?php echo $supplierTab === 'payments' ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=supplier-credit&tab=payments')); ?>">Payment History</a>
        </div>

        <article class="panel table-panel tabbed-table-panel">
        <?php if ($supplierTab === 'outstanding'): ?>
        <div class="table-action-header">
            <form class="filter-row movement-filter" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="supplier-credit">
                <input type="hidden" name="tab" value="outstanding">
                <input type="search" name="q" value="<?php echo e($creditSearch); ?>" placeholder="Invoice, supplier, phone">
                <select name="purchase_status">
                    <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="partial" <?php echo $statusFilter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="credit" <?php echo $statusFilter === 'credit' ? 'selected' : ''; ?>>Full Credit</option>
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Purchases</option>
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
                        <th>Supplier</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($creditPurchases === []): ?>
                        <tr>
                            <td colspan="9">No supplier credit purchases found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($creditPurchases as $purchase): ?>
                        <?php $balance = (float) $purchase['total'] - (float) $purchase['paid']; ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d', strtotime((string) $purchase['purchase_date']))); ?></td>
                            <td><?php echo e($purchase['invoice_no'] ?: 'No invoice'); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($purchase['supplier_name'] ?: 'No supplier'); ?></strong>
                                <span class="table-subtitle"><?php echo e($purchase['supplier_phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo (int) $purchase['item_count']; ?> / <?php echo (int) $purchase['total_units']; ?> units</td>
                            <td><?php echo e(format_money($purchase['total'])); ?></td>
                            <td><?php echo e(format_money($purchase['paid'])); ?></td>
                            <td class="<?php echo $balance > 0 ? 'text-danger' : ''; ?>"><?php echo e(format_money($balance)); ?></td>
                            <td><span class="status <?php echo e(supplier_credit_status_class((string) $purchase['status'], $balance)); ?>"><?php echo e($balance > 0 ? ucfirst((string) $purchase['status']) : 'Closed'); ?></span></td>
                            <td>
                                <?php if ($balance > 0): ?>
                                    <a class="icon-button" href="<?php echo e(app_url('?page=supplier-credit&collect=' . (int) $purchase['id'] . '#supplier-payment-form')); ?>" aria-label="Pay supplier">
                                        <i data-lucide="hand-coins"></i>
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
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Supplier</th>
                        <th>Method</th>
                        <th>Amount</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recentPayments === []): ?>
                        <tr>
                            <td colspan="6">No supplier payments recorded yet.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($recentPayments as $payment): ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $payment['payment_date']))); ?></td>
                            <td><?php echo e($payment['invoice_no'] ?: 'No invoice'); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($payment['supplier_name'] ?: 'No supplier'); ?></strong>
                                <span class="table-subtitle"><?php echo e($payment['supplier_phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo e(ucfirst((string) $payment['payment_method'])); ?></td>
                            <td class="text-good"><?php echo e(format_money($payment['amount'])); ?></td>
                            <td><?php echo e($payment['notes'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        </article>
    </div>
</section>

<?php
function supplier_credit_status_class(string $status, float $balance): string
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
