<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */

$productId = (int) ($_GET['id'] ?? 0);
$product = null;
$stockLots = [];
$movementLabels = product_history_movement_labels();
$summary = [
    'lot_count' => 0,
    'stock_in' => 0,
    'stock_out' => 0,
];

if ($dbReady && $pdo !== null && $productId > 0) {
    $productStatement = $pdo->prepare(
        'SELECT p.*, c.name AS category_name, b.name AS brand_name, s.name AS supplier_name
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         LEFT JOIN brands b ON b.id = p.brand_id
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         WHERE p.id = :id
         LIMIT 1'
    );
    $productStatement->execute(['id' => $productId]);
    $product = $productStatement->fetch() ?: null;

    if (is_array($product)) {
        $summaryStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(CASE WHEN quantity_change > 0 THEN quantity_change ELSE 0 END), 0) AS stock_in,
                    COALESCE(SUM(CASE WHEN quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END), 0) AS stock_out
             FROM stock_movements
             WHERE product_id = :product_id
               AND (reference_type IS NULL OR reference_type <> "stock_lot")'
        );
        $summaryStatement->execute(['product_id' => $productId]);
        $summaryRow = $summaryStatement->fetch() ?: [];
        $summary['stock_in'] = (int) ($summaryRow['stock_in'] ?? 0);
        $summary['stock_out'] = (int) ($summaryRow['stock_out'] ?? 0);

        $lotStatement = $pdo->prepare(
            'SELECT sm.*,
                    ' . app_lot_unit_cost_sql('sm', 'pc') . ' AS display_unit_cost,
                    COALESCE(pu.purchase_date, DATE(sm.created_at)) AS history_date,
                    sm.created_at AS history_created_at,
                    CASE
                        WHEN sm.warranty_months > 0 AND sm.movement_type IN ("opening", "purchase", "warranty_supplier_in")
                            THEN DATE_ADD(COALESCE(pu.purchase_date, DATE(sm.created_at)), INTERVAL sm.warranty_months MONTH)
                        ELSE NULL
                    END AS warranty_ends_at,
                    u.full_name AS created_by_name
             FROM stock_movements sm
             LEFT JOIN purchases pu ON sm.reference_type = "purchase" AND pu.id = sm.reference_id
             ' . app_purchase_cost_join_sql('sm', 'pc') . '
             LEFT JOIN users u ON u.id = sm.created_by
             WHERE sm.product_id = :product_id
               AND sm.quantity_change > 0
               AND sm.movement_type IN ("opening", "purchase", "return_in", "adjustment_in", "warranty_supplier_in")
             ORDER BY COALESCE(pu.purchase_date, DATE(sm.created_at)) ASC, sm.id ASC
             LIMIT 200'
        );
        $lotStatement->execute(['product_id' => $productId]);
        $stockLots = $lotStatement->fetchAll();
        $summary['lot_count'] = count($stockLots);

        $lotIds = array_map(static fn (array $lot): int => (int) $lot['id'], $stockLots);
        $lotAdjustments = product_history_lot_adjustments($pdo, $productId, $lotIds);

        $outboundStatement = $pdo->prepare(
            'SELECT COALESCE(SUM(ABS(quantity_change)), 0)
             FROM stock_movements
             WHERE product_id = :product_id
               AND quantity_change < 0
               AND (reference_type IS NULL OR reference_type <> "stock_lot")'
        );
        $outboundStatement->execute(['product_id' => $productId]);
        $outboundRemaining = (int) $outboundStatement->fetchColumn();

        foreach ($stockLots as $index => $lot) {
            $lotId = (int) $lot['id'];
            $lotQuantity = max(0, (int) $lot['quantity_change'] + (int) ($lotAdjustments[$lotId] ?? 0));
            $deducted = min($lotQuantity, $outboundRemaining);
            $stockLots[$index]['adjusted_lot_quantity'] = $lotQuantity;
            $stockLots[$index]['current_lot_stock'] = max(0, $lotQuantity - $deducted);
            $outboundRemaining = max(0, $outboundRemaining - $deducted);
        }

        $stockLots = array_reverse($stockLots);
    }
}
?>

<div class="page-heading">
    <div>
        <h1><?php echo e(is_array($product) ? $product['name'] : 'Product History'); ?></h1>
        <?php if (is_array($product)): ?>
            <div class="history-meta-row">
                <span><?php echo e($product['sku']); ?></span>
                <span><?php echo e($product['brand_name'] ?: 'No brand'); ?></span>
                <span><?php echo e($product['category_name'] ?: 'Uncategorized'); ?></span>
                <span>Current stock: <?php echo (int) $product['current_stock']; ?></span>
                <span>Stock in: <?php echo (int) $summary['stock_in']; ?></span>
                <span>Stock out: <?php echo (int) $summary['stock_out']; ?></span>
            </div>
        <?php endif; ?>
    </div>
    <a class="top-action" href="<?php echo e(app_url('?page=products')); ?>">
        <i data-lucide="arrow-left"></i>
        Product List
    </a>
</div>

<?php if (! $dbReady): ?>
    <section class="panel">
        <p class="empty-state">Import <code>database/schema.sql</code> before viewing product history.</p>
    </section>
<?php elseif (! is_array($product)): ?>
    <section class="panel">
        <p class="empty-state">Product was not found.</p>
    </section>
<?php else: ?>
    <section class="panel table-panel">
        <div class="panel-header">
            <div>
                <h2>Stock history</h2>
            </div>
            <span class="dashboard-pill"><?php echo (int) $summary['lot_count']; ?> lot(s)</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Received</th>
                        <th>Current Stock</th>
                        <th>Unit Cost</th>
                        <th>Warranty</th>
                        <th>By</th>
                        <th>Reference / Note</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($stockLots === []): ?>
                        <tr><td colspan="8">No stock lots recorded for this product.</td></tr>
                    <?php endif; ?>

                    <?php foreach ($stockLots as $movement): ?>
                        <?php
                        $quantityChange = (int) $movement['quantity_change'];
                        $currentLotStock = (int) ($movement['current_lot_stock'] ?? 0);
                        $reference = trim((string) ($movement['reference_type'] ?? ''));
                        if ($reference !== '' && $movement['reference_id'] !== null) {
                            $reference .= ' #' . (int) $movement['reference_id'];
                        }
                        ?>
                        <?php
                        $historyDateTime = trim((string) $movement['history_date']) . ' ' . date('H:i', strtotime((string) $movement['history_created_at']));
                        ?>
                        <tr>
                            <td><?php echo e($historyDateTime); ?></td>
                            <td><?php echo $quantityChange; ?></td>
                            <td class="<?php echo $currentLotStock <= 0 ? 'text-danger' : 'text-good'; ?>"><?php echo $currentLotStock; ?></td>
                            <td><?php echo e(format_money($movement['display_unit_cost'])); ?></td>
                            <td>
                                <?php if ((int) $movement['warranty_months'] > 0 && $movement['warranty_ends_at'] !== null): ?>
                                    <?php echo (int) $movement['warranty_months']; ?> mo
                                    <span class="table-subtitle">Ends <?php echo e((string) $movement['warranty_ends_at']); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo e($movement['created_by_name'] ?: '-'); ?></td>
                            <td class="reference-note-cell">
                                <span class="reference-text"><?php echo e($reference !== '' ? $reference : 'Manual'); ?></span>
                                <span class="note-text"><?php echo e(trim((string) ($movement['notes'] ?? '')) !== '' ? (string) $movement['notes'] : '-'); ?></span>
                            </td>
                            <td>
                                <button
                                    class="icon-button"
                                    type="button"
                                    aria-label="Correct this stock lot"
                                    data-lot-correct-button
                                    data-lot-id="<?php echo (int) $movement['id']; ?>"
                                    data-product-id="<?php echo (int) $product['id']; ?>"
                                    data-current-stock="<?php echo $currentLotStock; ?>"
                                    data-lot-summary="<?php echo e($historyDateTime . ' / received ' . $quantityChange); ?>"
                                >
                                    <i data-lucide="sliders-horizontal"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($dbReady && is_array($product)): ?>
    <div class="modal-backdrop" data-lot-correct-modal hidden>
        <div class="modal-card lot-correct-modal" role="dialog" aria-modal="true" aria-labelledby="lot-correct-title">
            <div class="panel-header">
                <div>
                    <h2 id="lot-correct-title">Correct stock</h2>
                    <p class="modal-subtitle" data-lot-correct-summary>Select a stock lot.</p>
                </div>
                <button class="icon-button" type="button" aria-label="Close stock correction" data-lot-correct-close>
                    <i data-lucide="x"></i>
                </button>
            </div>

            <form class="warranty-form single-form lot-correct-form" method="post" action="<?php echo e(app_url('actions/stock_adjust.php')); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="product_id" value="<?php echo (int) $product['id']; ?>" data-lot-correct-product>
                <input type="hidden" name="lot_movement_id" value="" data-lot-correct-lot required>

                <div class="field stock-current-field">
                    <span>Current lot stock</span>
                    <div class="stock-current">
                        <strong data-lot-correct-current>0</strong>
                    </div>
                </div>

                <label class="field">
                    <span>New stock count</span>
                    <input type="number" name="exact_stock" min="0" step="1" value="0" required data-lot-correct-exact>
                </label>

                <label class="field span-2">
                    <span>Notes</span>
                    <textarea name="notes" rows="3" placeholder="Example: Physical count correction" required></textarea>
                </label>

                <div class="form-actions span-2">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
function product_history_movement_labels(): array
{
    return [
        'opening' => 'Opening Stock',
        'purchase' => 'Purchase',
        'sale' => 'Sale',
        'return_in' => 'Sales Return',
        'return_out' => 'Purchase Return',
        'warranty_supplier_in' => 'Supplier Replacement',
        'warranty_customer_out' => 'Customer Replacement',
        'adjustment_in' => 'Manual Increase',
        'adjustment_out' => 'Manual Decrease',
        'damage' => 'Damage / Loss',
        'stock_count' => 'Lot Correction',
    ];
}

function product_history_movement_status_class(string $type): string
{
    return match ($type) {
        'purchase', 'opening', 'return_in', 'adjustment_in', 'warranty_supplier_in' => 'status-active',
        'damage', 'sale', 'return_out', 'adjustment_out', 'warranty_customer_out' => 'status-pending',
        'stock_count' => 'status-warranty',
        default => 'status-inactive',
    };
}

function product_history_lot_adjustments(PDO $pdo, int $productId, array $lotIds): array
{
    if ($lotIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($lotIds), '?'));
    $statement = $pdo->prepare(
        'SELECT reference_id, COALESCE(SUM(quantity_change), 0) AS adjustment
         FROM stock_movements
         WHERE product_id = ?
           AND reference_type = "stock_lot"
           AND reference_id IN (' . $placeholders . ')
         GROUP BY reference_id'
    );
    $statement->execute(array_merge([$productId], $lotIds));

    $adjustments = [];

    foreach ($statement->fetchAll() as $row) {
        $adjustments[(int) $row['reference_id']] = (int) $row['adjustment'];
    }

    return $adjustments;
}
