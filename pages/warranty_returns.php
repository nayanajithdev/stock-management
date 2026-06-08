<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$claimSearch = trim((string) ($_GET['q'] ?? ''));
$claims = [];
$returns = [];
$hasServiceItems = false;

if ($dbReady && $pdo !== null) {
    $hasServiceItems = (int) $pdo->query(
        'SELECT COUNT(*)
         FROM sale_items si
         LEFT JOIN (
            SELECT sale_item_id, COALESCE(SUM(quantity), 0) AS returned_quantity
            FROM sales_return_items
            GROUP BY sale_item_id
         ) r ON r.sale_item_id = si.id
         LEFT JOIN (
            SELECT sale_item_id, COUNT(*) AS claimed_quantity
            FROM warranty_claims
            WHERE sale_item_id IS NOT NULL
              AND status <> "rejected"
            GROUP BY sale_item_id
         ) w ON w.sale_item_id = si.id
         WHERE si.quantity - COALESCE(r.returned_quantity, 0) - COALESCE(w.claimed_quantity, 0) > 0'
    )->fetchColumn() > 0;

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
    $claimParams = [];

    if ($claimSearch !== '') {
        $claimSql .= ' WHERE wc.claim_no LIKE :search OR s.invoice_no LIKE :search OR c.name LIKE :search OR c.phone LIKE :search OR p.sku LIKE :search OR p.name LIKE :search';
        $claimParams['search'] = '%' . $claimSearch . '%';
    } else {
        $claimSql .= ' WHERE wc.status IN ("received", "sent_to_supplier", "ready_for_pickup")';
    }

    $claimSql .= ' ORDER BY wc.received_date DESC, wc.id DESC LIMIT 50';
    $claimStatement = $pdo->prepare($claimSql);
    $claimStatement->execute($claimParams);
    $claims = $claimStatement->fetchAll();

    $returnStatement = $pdo->query(
        'SELECT sr.*,
                s.invoice_no,
                c.name AS customer_name,
                c.phone AS customer_phone,
                COUNT(sri.id) AS item_count,
                COALESCE(SUM(sri.quantity), 0) AS total_units,
                COALESCE(SUM(CASE WHEN sri.restock = 1 THEN sri.quantity ELSE 0 END), 0) AS restocked_units
         FROM sales_returns sr
         INNER JOIN sales s ON s.id = sr.sale_id
         LEFT JOIN customers c ON c.id = sr.customer_id
         LEFT JOIN sales_return_items sri ON sri.return_id = sr.id
         GROUP BY sr.id
         ORDER BY sr.return_date DESC, sr.id DESC
         LIMIT 12'
    );
    $returns = $returnStatement->fetchAll();
}
?>

<div class="page-heading">
    <div>
        <h1>Warranty / Returns</h1>
    </div>
</div>

<section class="warranty-return-layout">
    <article class="panel" id="warranty-return-form">
        <div class="panel-header">
            <div>
                <h2>Customer item handling</h2>
                <p class="modal-subtitle">Search the invoice item first. The next steps change based on your choices.</p>
            </div>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before saving warranty or return records.</p>
        <?php elseif (! $hasServiceItems): ?>
            <p class="empty-state">No sold items are available for returns or warranty claims.</p>
        <?php else: ?>
            <form class="service-wizard" method="post" action="<?php echo e(app_url('actions/warranty_return_save.php')); ?>" data-service-form data-service-lookup-url="<?php echo e(app_url('actions/warranty_return_lookup.php')); ?>">
                <?php echo csrf_field(); ?>

                <section class="service-step is-active">
                    <div class="service-step-title">
                        <span>1</span>
                        <strong>Select item</strong>
                    </div>

                    <div class="field product-picker">
                        <span>Customer or Invoice</span>
                        <input type="search" placeholder="Search customer, phone, email, or invoice" autocomplete="off" data-service-search>
                        <div class="product-suggestions" data-service-suggestions hidden></div>
                    </div>

                    <div class="return-picker-grid">
                        <section class="return-picker-panel">
                            <div class="return-picker-heading">
                                <strong>Invoices</strong>
                                <span data-service-customer-label>Search and select a customer or invoice.</span>
                            </div>
                            <div class="return-choice-list" data-service-invoices>
                                <p class="return-choice-empty">No customer selected.</p>
                            </div>
                        </section>

                        <section class="return-picker-panel">
                            <div class="return-picker-heading">
                                <strong>Items</strong>
                                <span data-service-invoice-label>Select an invoice to view items.</span>
                            </div>
                            <div class="return-choice-list" data-service-items>
                                <p class="return-choice-empty">No invoice selected.</p>
                            </div>
                        </section>
                    </div>
                </section>

                <input type="hidden" name="sale_item_id" data-service-item>
                <input type="hidden" name="outcome" data-service-outcome>

                <section class="service-step" data-service-path-step hidden>
                    <div class="service-step-title">
                        <span>2</span>
                        <strong>Customer need</strong>
                    </div>

                    <div class="replacement-options">
                        <label class="replacement-option">
                            <input type="radio" name="service_path" value="normal_return" data-service-path>
                            <span>
                                <strong>Return / refund</strong>
                                <small>Customer does not want the item.</small>
                            </span>
                        </label>
                        <label class="replacement-option">
                            <input type="radio" name="service_path" value="damaged_item" data-service-path>
                            <span>
                                <strong>Faulty item</strong>
                                <small>Item is damaged or not working.</small>
                            </span>
                        </label>
                    </div>
                </section>

                <section class="service-step" data-service-outcome-step hidden>
                    <div class="service-step-title">
                        <span>3</span>
                        <strong>Choose action</strong>
                    </div>

                    <div class="service-outcome-group" data-service-normal-outcomes hidden>
                        <label class="service-outcome-card">
                            <input type="radio" name="service_outcome_choice" value="normal_restock" data-service-outcome-choice>
                            <span>
                                <strong>Refund and return to stock</strong>
                                <small>Item is sellable. Stock + returned quantity.</small>
                            </span>
                        </label>
                    </div>

                    <div class="service-outcome-group" data-service-damaged-outcomes hidden>
                        <label class="service-outcome-card" data-service-needs-warranty>
                            <input type="radio" name="service_outcome_choice" value="warranty_wait_supplier" data-service-outcome-choice>
                            <span>
                                <strong>Return to supplier</strong>
                                <small>Customer waits until supplier result.</small>
                            </span>
                        </label>
                        <label class="service-outcome-card" data-service-needs-warranty>
                            <input type="radio" name="service_outcome_choice" value="warranty_refund_now" data-service-outcome-choice>
                            <span>
                                <strong>Refund Now</strong>
                                <small>Customer is refunded now. Supplier decision comes later.</small>
                            </span>
                        </label>
                        <label class="service-outcome-card" data-service-needs-warranty>
                            <input type="radio" name="service_outcome_choice" value="warranty_replace_now" data-service-outcome-choice>
                            <span>
                                <strong>Replace Now</strong>
                                <small>Customer gets new stock now. Stock -1.</small>
                            </span>
                        </label>
                    </div>
                </section>

                <section class="service-step" data-service-details-step hidden>
                    <div class="service-step-title">
                        <span>4</span>
                        <strong>Details</strong>
                    </div>

                    <div class="warranty-form">
                        <label class="field">
                            <span>Quantity</span>
                            <input type="number" name="quantity" value="1" min="1" step="1" data-service-quantity>
                        </label>

                        <label class="field">
                            <span>Date</span>
                            <input type="datetime-local" name="return_date" value="<?php echo e(date('Y-m-d\TH:i')); ?>" required>
                        </label>

                        <div class="field" data-service-refund-fields>
                            <span>Refund Method</span>
                            <select name="refund_method">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="store_credit">Store Credit</option>
                                <option value="none">No Refund</option>
                            </select>
                        </div>

                        <label class="field" data-service-refund-fields>
                            <span>Refund Amount</span>
                            <input type="number" name="refund_amount" value="0.00" min="0" step="0.01" data-service-refund>
                        </label>

                        <label class="field span-2">
                            <span>Customer Issue / Reason</span>
                            <textarea name="issue_description" rows="3" placeholder="Example: Not powering on, customer wants refund" required></textarea>
                        </label>

                        <label class="field span-2">
                            <span>Internal Notes</span>
                            <textarea name="notes" rows="3" placeholder="Optional supplier handover or inspection note"></textarea>
                        </label>
                    </div>

                    <div class="collection-preview">
                        <i data-lucide="activity"></i>
                        <span data-service-preview>Select an item and action.</span>
                    </div>

                    <div class="form-actions">
                        <button class="top-action" type="submit">
                            <i data-lucide="save"></i>
                            Save
                        </button>
                    </div>
                </section>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <h2>Open warranty cases</h2>
                <p class="modal-subtitle">Click a row to update supplier/customer status.</p>
            </div>

            <form class="filter-row movement-filter" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="warranty-returns">
                <input type="search" name="q" value="<?php echo e($claimSearch); ?>" placeholder="Claim, invoice, customer">
                <button class="icon-button" type="submit" aria-label="Search cases">
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
                        <th>Issue</th>
                        <th>Progress</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($claims === []): ?>
                        <tr><td colspan="6">No active warranty cases found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($claims as $claim): ?>
                        <?php $supplierRefundAmount = (float) ($claim['supplier_refund_amount'] ?? 0); ?>
                        <tr
                            class="clickable-row"
                            tabindex="0"
                            data-warranty-claim-row
                            data-claim-id="<?php echo (int) $claim['id']; ?>"
                            data-claim-no="<?php echo e($claim['claim_no']); ?>"
                            data-claim-status="<?php echo e($claim['status']); ?>"
                            data-claim-customer="<?php echo e($claim['customer_name'] ?: 'Walk-in Customer'); ?>"
                            data-claim-product="<?php echo e($claim['sku'] . ' - ' . $claim['product_name']); ?>"
                            data-claim-refund-amount="<?php echo e(number_format($supplierRefundAmount, 2, '.', '')); ?>"
                            data-claim-refund-date="<?php echo e($claim['supplier_refund_date'] ?? date('Y-m-d')); ?>"
                            data-customer-replacement-status="<?php echo e($claim['customer_replacement_status'] ?? 'pending'); ?>"
                            data-supplier-replacement-status="<?php echo e($claim['supplier_replacement_status'] ?? 'pending'); ?>"
                        >
                            <td>
                                <strong class="table-title"><?php echo e($claim['claim_no']); ?></strong>
                                <span class="table-subtitle"><?php echo wr_age_days((string) $claim['received_date']); ?> day(s) open</span>
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
                            <td><?php echo e($claim['issue_description']); ?></td>
                            <td>
                                <span class="status <?php echo e(wr_warranty_status_class((string) $claim['status'])); ?>"><?php echo e(wr_warranty_status_label((string) $claim['status'])); ?></span>
                                <span class="table-subtitle"><?php echo e(wr_replacement_summary($claim)); ?></span>
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

    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <h2>Recent returns</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Return</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Units</th>
                        <th>Restocked</th>
                        <th>Refund</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($returns === []): ?>
                        <tr><td colspan="7">No returns recorded yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($returns as $return): ?>
                        <tr>
                            <td><?php echo e(date('Y-m-d H:i', strtotime((string) $return['return_date']))); ?></td>
                            <td><?php echo e($return['return_no']); ?></td>
                            <td><?php echo e($return['invoice_no']); ?></td>
                            <td>
                                <strong class="table-title"><?php echo e($return['customer_name'] ?: 'Walk-in Customer'); ?></strong>
                                <span class="table-subtitle"><?php echo e($return['customer_phone'] ?? ''); ?></span>
                            </td>
                            <td><?php echo (int) $return['total_units']; ?></td>
                            <td><?php echo (int) $return['restocked_units']; ?></td>
                            <td><?php echo e(format_money($return['refund_amount'])); ?></td>
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
                <h2 id="claim-update-title">Update warranty case</h2>
                <p class="modal-subtitle" data-warranty-claim-summary>Select a claim from the table.</p>
            </div>
            <button class="icon-button" type="button" aria-label="Close claim update" data-warranty-claim-close>
                <i data-lucide="x"></i>
            </button>
        </div>

        <form class="warranty-form single-form" method="post" action="<?php echo e(app_url('actions/warranty_return_update.php')); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="claim_id" value="" data-warranty-claim-id required>

            <label class="field span-2">
                <span>Supplier update</span>
                <select name="supplier_decision" data-warranty-supplier-decision>
                    <option value="">No change</option>
                    <option value="send_to_supplier">Send to supplier</option>
                    <option value="no_supplier_warranty">No supplier warranty - close as shop loss</option>
                </select>
            </label>

            <label class="field" data-warranty-status-field hidden>
                <span>New Status</span>
                <select name="status" required data-warranty-claim-status>
                    <option value="received">Received</option>
                    <option value="sent_to_supplier">Sent to Supplier</option>
                    <option value="ready_for_pickup">Ready for Pickup</option>
                    <option value="resolved">Resolved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </label>

            <label class="field" data-warranty-resolved-field hidden>
                <span>Resolved Date</span>
                <input type="date" name="resolved_date" value="<?php echo e(date('Y-m-d')); ?>" data-warranty-resolved-date>
            </label>

            <label class="checkbox-row claim-refund-toggle span-2" data-warranty-refund-toggle>
                <input type="checkbox" data-warranty-refund-toggle-input>
                <span>Supplier refund received</span>
                <small>Record amount for profit calculation.</small>
            </label>

            <label class="field" data-warranty-refund-field hidden>
                <span>Supplier Refund</span>
                <input type="number" name="supplier_refund_amount" value="0.00" min="0" step="0.01" data-warranty-supplier-refund>
            </label>

            <label class="field" data-warranty-refund-field hidden>
                <span>Refund Date</span>
                <input type="date" name="supplier_refund_date" value="<?php echo e(date('Y-m-d')); ?>" data-warranty-supplier-refund-date>
            </label>

            <div class="claim-stock-actions span-2" data-warranty-stock-actions>
                <div>
                    <strong>Replacement stock</strong>
                    <span data-warranty-replacement-summary>Select a claim first.</span>
                </div>
                <label class="claim-stock-action" data-warranty-supplier-action>
                    <input type="checkbox" name="supplier_replacement_received" value="1" data-warranty-supplier-replacement>
                    <span>
                        <strong>Supplier replacement received</strong>
                        <small>Stock +1</small>
                    </span>
                </label>
                <label class="claim-stock-action" data-warranty-customer-action>
                    <input type="checkbox" name="customer_replacement_issued" value="1" data-warranty-customer-replacement>
                    <span>
                        <strong>Give replacement to customer</strong>
                        <small>Stock -1</small>
                    </span>
                </label>
            </div>

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
function wr_warranty_status_label(string $status): string
{
    return match ($status) {
        'sent_to_supplier' => 'Supplier',
        'ready_for_pickup' => 'Ready',
        'resolved' => 'Resolved',
        'rejected' => 'Rejected',
        default => 'Received',
    };
}

function wr_warranty_status_class(string $status): string
{
    return match ($status) {
        'resolved' => 'status-active',
        'sent_to_supplier' => 'status-warranty',
        'ready_for_pickup' => 'status-ready',
        'rejected' => 'status-pending',
        default => 'status-inactive',
    };
}

function wr_replacement_summary(array $claim): string
{
    $customerStatus = (string) ($claim['customer_replacement_status'] ?? 'pending');
    $supplierStatus = (string) ($claim['supplier_replacement_status'] ?? 'pending');

    if ($customerStatus === 'issued' && $supplierStatus === 'received') {
        return 'Replacement complete';
    }

    if ($customerStatus === 'refunded') {
        return $supplierStatus === 'received' ? 'Customer refunded / Supplier received' : 'Customer refunded / Supplier pending';
    }

    if ($supplierStatus === 'none') {
        return $customerStatus === 'issued' ? 'Customer replaced / Shop loss' : 'No supplier cover';
    }

    $customerText = $customerStatus === 'issued' ? 'Customer replaced' : 'Customer waiting';
    $supplierText = $supplierStatus === 'received' ? 'Supplier received' : 'Supplier pending';

    return $customerText . ' / ' . $supplierText;
}

function wr_age_days(string $receivedDate): int
{
    $received = new DateTimeImmutable($receivedDate);
    $today = new DateTimeImmutable(date('Y-m-d'));

    return (int) $received->diff($today)->format('%a');
}
