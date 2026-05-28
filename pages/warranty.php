<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$warrantySearch = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['warranty_status'] ?? 'open');
$allowedFilters = ['open', 'received', 'sent_to_supplier', 'ready_for_pickup', 'resolved', 'rejected', 'all'];

if (! in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'open';
}

$hasSoldWarrantyItems = false;
$claims = [];
$summary = [
    'open_claims' => 0,
    'received' => 0,
    'supplier' => 0,
    'ready' => 0,
];

if ($dbReady && $pdo !== null) {
    $summary['open_claims'] = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM warranty_claims
         WHERE status IN ("received", "sent_to_supplier", "ready_for_pickup")'
    )->fetchColumn();
    $summary['received'] = (int) $pdo->query('SELECT COUNT(*) FROM warranty_claims WHERE status = "received"')->fetchColumn();
    $summary['supplier'] = (int) $pdo->query('SELECT COUNT(*) FROM warranty_claims WHERE status = "sent_to_supplier"')->fetchColumn();
    $summary['ready'] = (int) $pdo->query('SELECT COUNT(*) FROM warranty_claims WHERE status = "ready_for_pickup"')->fetchColumn();

    $hasSoldWarrantyItems = (bool) $pdo->query(
        'SELECT EXISTS(
            SELECT 1
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            INNER JOIN products p ON p.id = si.product_id
            WHERE p.warranty_months > 0
              AND DATE_ADD(DATE(s.sale_date), INTERVAL p.warranty_months MONTH) >= CURRENT_DATE
            LIMIT 1
        )'
    )->fetchColumn();

    $claimSql = 'SELECT wc.*,
                        c.name AS customer_name,
                        c.phone AS customer_phone,
                        p.sku,
                        p.name AS product_name,
                        p.model,
                        p.warranty_months,
                        s.invoice_no,
                        s.sale_date,
                        DATE_ADD(DATE(s.sale_date), INTERVAL p.warranty_months MONTH) AS warranty_until
                 FROM warranty_claims wc
                 LEFT JOIN customers c ON c.id = wc.customer_id
                 INNER JOIN products p ON p.id = wc.product_id
                 LEFT JOIN sales s ON s.id = wc.sale_id';
    $where = [];
    $params = [];

    if ($statusFilter === 'open') {
        $where[] = 'wc.status IN ("received", "sent_to_supplier", "ready_for_pickup")';
    } elseif ($statusFilter !== 'all') {
        $where[] = 'wc.status = :status';
        $params['status'] = $statusFilter;
    }

    if ($warrantySearch !== '') {
        $where[] = '(wc.claim_no LIKE :search OR s.invoice_no LIKE :search OR c.name LIKE :search OR c.phone LIKE :search OR p.sku LIKE :search OR p.name LIKE :search)';
        $params['search'] = '%' . $warrantySearch . '%';
    }

    if ($where !== []) {
        $claimSql .= ' WHERE ' . implode(' AND ', $where);
    }

    $claimSql .= ' ORDER BY wc.received_date DESC, wc.id DESC LIMIT 100';
    $claimStatement = $pdo->prepare($claimSql);
    $claimStatement->execute($params);
    $claims = $claimStatement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <h1>Warranty / RMA</h1>
    </div>
    <a class="top-action" href="#warranty-claim-form">
        <i data-lucide="shield-plus"></i>
        New Claim
    </a>
</div>

<section class="stats-grid compact-stats" aria-label="Warranty summary">
    <article class="stat-card">
        <div>
            <span>Open Claims</span>
            <strong><?php echo (int) $summary['open_claims']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="shield-check"></i></div>
        <small>Active RMA workload</small>
    </article>
    <article class="stat-card">
        <div>
            <span>Supplier</span>
            <strong><?php echo (int) $summary['supplier']; ?></strong>
        </div>
        <div class="stat-icon"><i data-lucide="truck"></i></div>
        <small>Sent for repair</small>
    </article>
</section>

<section class="warranty-layout">
    <div class="warranty-forms">
        <article class="panel" id="warranty-claim-form">
            <div class="panel-header">
                <div>
                    <p class="panel-label">New Claim</p>
                    <h2>Receive warranty item</h2>
                </div>
                <a class="muted-link" href="<?php echo e(app_url('?page=sales')); ?>">Sales history</a>
            </div>

            <?php if (! $dbReady): ?>
                <p class="empty-state">Import <code>database/schema.sql</code> before saving warranty claims.</p>
            <?php elseif (! $hasSoldWarrantyItems): ?>
                <p class="empty-state">No sold items are currently inside warranty period.</p>
            <?php else: ?>
                <form class="warranty-form" method="post" action="<?php echo e(app_url('actions/warranty_save.php')); ?>" data-warranty-form data-warranty-lookup-url="<?php echo e(app_url('actions/warranty_lookup.php')); ?>">
                    <?php echo csrf_field(); ?>

                    <div class="field product-picker span-2">
                        <span>Customer or Invoice</span>
                        <input type="search" placeholder="Search customer, phone, email, or invoice" autocomplete="off" data-warranty-search>
                        <div class="product-suggestions" data-warranty-suggestions hidden></div>
                    </div>

                    <div class="return-picker-grid span-2">
                        <section class="return-picker-panel">
                            <div class="return-picker-heading">
                                <strong>Invoices</strong>
                                <span data-warranty-customer-label>Search and select a customer or invoice.</span>
                            </div>
                            <div class="return-choice-list" data-warranty-invoices>
                                <p class="return-choice-empty">No customer selected.</p>
                            </div>
                        </section>

                        <section class="return-picker-panel">
                            <div class="return-picker-heading">
                                <strong>Warranty Items</strong>
                                <span data-warranty-invoice-label>Select an invoice to view warranty items.</span>
                            </div>
                            <div class="return-choice-list" data-warranty-items>
                                <p class="return-choice-empty">No invoice selected.</p>
                            </div>
                        </section>
                    </div>

                    <input type="hidden" name="sale_item_id" data-warranty-item>

                    <div class="collection-preview span-2">
                        <i data-lucide="shield-check"></i>
                        <span data-warranty-preview>Search customer, select invoice, then select the warranty item.</span>
                    </div>

                    <label class="field">
                        <span>Received Date</span>
                        <input type="date" name="received_date" value="<?php echo e(date('Y-m-d')); ?>" required>
                    </label>

                    <label class="field">
                        <span>Status</span>
                        <select name="status">
                            <option value="received">Received</option>
                            <option value="sent_to_supplier">Sent to Supplier</option>
                            <option value="ready_for_pickup">Ready for Pickup</option>
                        </select>
                    </label>

                    <label class="field span-2">
                        <span>Customer Issue</span>
                        <textarea name="issue_description" rows="3" placeholder="Example: Keyboard keys not responding" required></textarea>
                    </label>

                    <label class="field span-2">
                        <span>Supplier / Internal Notes</span>
                        <textarea name="supplier_notes" rows="3" placeholder="Optional inspection or supplier handover note"></textarea>
                    </label>

                    <div class="form-actions span-2">
                        <button class="top-action" type="submit">
                            <i data-lucide="save"></i>
                            Save Claim
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </article>

    </div>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">Claim Ledger</p>
                <h2>Warranty claims</h2>
            </div>

            <form class="filter-row movement-filter" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="warranty">
                <input type="search" name="q" value="<?php echo e($warrantySearch); ?>" placeholder="Claim, invoice, customer">
                <select name="warranty_status">
                    <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="received" <?php echo $statusFilter === 'received' ? 'selected' : ''; ?>>Received</option>
                    <option value="sent_to_supplier" <?php echo $statusFilter === 'sent_to_supplier' ? 'selected' : ''; ?>>Supplier</option>
                    <option value="ready_for_pickup" <?php echo $statusFilter === 'ready_for_pickup' ? 'selected' : ''; ?>>Ready</option>
                    <option value="resolved" <?php echo $statusFilter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                    <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
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
                        <th>Claim</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>Invoice</th>
                        <th>Received</th>
                        <th>Warranty Until</th>
                        <th>Issue</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($claims === []): ?>
                        <tr>
                            <td colspan="8">No warranty claims found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($claims as $claim): ?>
                        <?php $supplierRefundAmount = (float) ($claim['supplier_refund_amount'] ?? 0); ?>
                        <tr class="clickable-row" tabindex="0" data-warranty-claim-row data-claim-id="<?php echo (int) $claim['id']; ?>" data-claim-no="<?php echo e($claim['claim_no']); ?>" data-claim-status="<?php echo e($claim['status']); ?>" data-claim-customer="<?php echo e($claim['customer_name'] ?: 'Walk-in Customer'); ?>" data-claim-product="<?php echo e($claim['sku'] . ' - ' . $claim['product_name']); ?>" data-claim-refund-amount="<?php echo e(number_format($supplierRefundAmount, 2, '.', '')); ?>" data-claim-refund-date="<?php echo e($claim['supplier_refund_date'] ?? date('Y-m-d')); ?>">
                            <td>
                                <strong class="table-title"><?php echo e($claim['claim_no']); ?></strong>
                                <span class="table-subtitle"><?php echo warranty_age_days((string) $claim['received_date']); ?> day(s) open</span>
                            </td>
                            <td>
                                <strong class="table-title"><?php echo e($claim['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                                <span class="table-subtitle"><?php echo e($claim['customer_phone'] ?? ''); ?></span>
                            </td>
                            <td>
                                <strong class="table-title"><?php echo e($claim['sku'] . ' - ' . $claim['product_name']); ?></strong>
                                <span class="table-subtitle"><?php echo e($claim['model'] ?? ''); ?></span>
                            </td>
                            <td>
                                <?php echo e($claim['invoice_no'] ?? 'Manual'); ?>
                                <span class="table-subtitle"><?php echo isset($claim['sale_date']) ? e(date('Y-m-d', strtotime((string) $claim['sale_date']))) : ''; ?></span>
                            </td>
                            <td><?php echo e($claim['received_date']); ?></td>
                            <td><?php echo e($claim['warranty_until'] ?? ''); ?></td>
                            <td><?php echo e($claim['issue_description']); ?></td>
                            <td>
                                <span class="status <?php echo e(warranty_status_class((string) $claim['status'])); ?>"><?php echo e(warranty_status_label((string) $claim['status'])); ?></span>
                                <?php if (! empty($claim['resolved_date'])): ?>
                                    <span class="table-subtitle"><?php echo e($claim['resolved_date']); ?></span>
                                <?php endif; ?>
                                <?php if ($supplierRefundAmount > 0): ?>
                                    <span class="table-subtitle">Supplier refund <?php echo e(format_money($supplierRefundAmount)); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<div class="modal-backdrop" data-warranty-claim-modal hidden>
    <div class="modal-card claim-update-modal" role="dialog" aria-modal="true" aria-labelledby="claim-update-title">
        <div class="panel-header">
            <div>
                <h2 id="claim-update-title">Move claim status</h2>
                <p class="modal-subtitle" data-warranty-claim-summary>Select a claim from the table.</p>
            </div>
            <button class="icon-button" type="button" aria-label="Close claim update" data-warranty-claim-close>
                <i data-lucide="x"></i>
            </button>
        </div>

        <form class="warranty-form single-form" method="post" action="<?php echo e(app_url('actions/warranty_save.php')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="claim_id" value="" data-warranty-claim-id required>

            <label class="field">
                <span>New Status</span>
                <select name="status" required data-warranty-claim-status>
                    <option value="received">Received</option>
                    <option value="sent_to_supplier">Sent to Supplier</option>
                    <option value="ready_for_pickup">Ready for Pickup</option>
                    <option value="resolved">Resolved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </label>

            <label class="field">
                <span>Resolved Date</span>
                <input type="date" name="resolved_date" value="<?php echo e(date('Y-m-d')); ?>">
            </label>

            <label class="field">
                <span>Supplier Refund</span>
                <input type="number" name="supplier_refund_amount" value="0.00" min="0" step="0.01" data-warranty-supplier-refund>
            </label>

            <label class="field">
                <span>Refund Date</span>
                <input type="date" name="supplier_refund_date" value="<?php echo e(date('Y-m-d')); ?>" data-warranty-supplier-refund-date>
            </label>

            <label class="field span-2">
                <span>Status Note</span>
                <textarea name="supplier_notes" rows="3" placeholder="Optional note to append"></textarea>
            </label>

            <div class="form-actions span-2">
                <button class="top-action" type="submit">
                    <i data-lucide="refresh-cw"></i>
                    Update Claim
                </button>
            </div>
        </form>
    </div>
</div>

<?php
function warranty_status_label(string $status): string
{
    return match ($status) {
        'sent_to_supplier' => 'Supplier',
        'ready_for_pickup' => 'Ready',
        'resolved' => 'Resolved',
        'rejected' => 'Rejected',
        default => 'Received',
    };
}

function warranty_status_class(string $status): string
{
    return match ($status) {
        'resolved' => 'status-active',
        'sent_to_supplier' => 'status-warranty',
        'ready_for_pickup' => 'status-ready',
        'rejected' => 'status-pending',
        default => 'status-inactive',
    };
}

function warranty_age_days(string $receivedDate): int
{
    $received = new DateTimeImmutable($receivedDate);
    $today = new DateTimeImmutable(date('Y-m-d'));

    return (int) $received->diff($today)->format('%a');
}
