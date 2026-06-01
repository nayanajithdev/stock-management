<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=products');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving products.');
    redirect('?page=products');
}

$productId = ($_POST['product_id'] ?? '') !== '' ? (int) $_POST['product_id'] : null;
$name = trim((string) ($_POST['name'] ?? ''));
$sku = trim((string) ($_POST['sku'] ?? ''));
$barcode = nullable_string((string) ($_POST['barcode'] ?? ''));
$model = nullable_string((string) ($_POST['model'] ?? ''));
$description = nullable_string((string) ($_POST['description'] ?? ''));
$categoryName = trim((string) ($_POST['category_name'] ?? ''));
$brandName = trim((string) ($_POST['brand_name'] ?? ''));
$categoryId = null;
$brandId = null;
$supplierId = ($_POST['supplier_id'] ?? '') !== '' ? (int) $_POST['supplier_id'] : null;
$costPrice = input_decimal('cost_price');
$sellingPrice = input_decimal('selling_price');
$wholesalePrice = input_decimal('wholesale_price');
$warrantyMonths = input_int('warranty_months');
$itemTracking = isset($_POST['item_tracking']) ? 1 : 0;
$reorderLevel = input_int('reorder_level');
$openingStock = input_int('opening_stock');
$purchaseDate = trim((string) ($_POST['purchase_date'] ?? date('Y-m-d')));
$formRedirect = '?page=products' . ($productId !== null ? '&edit=' . $productId : '&form=product');

if ($name === '' || $sku === '') {
    set_flash('error', 'Product name and SKU are required.');
    redirect($formRedirect);
}

if ($costPrice < 0 || $sellingPrice < 0 || $wholesalePrice < 0) {
    set_flash('error', 'Product prices cannot be negative.');
    redirect($formRedirect);
}

if ($sellingPrice < $costPrice) {
    set_flash('error', 'Selling price should be equal to or higher than cost price.');
    redirect($formRedirect);
}

try {
    if ($productId === null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchaseDate)) {
        throw new RuntimeException('Purchase date is not valid.');
    }

    if ($productId !== null) {
        $pdo->beginTransaction();
        $categoryId = resolve_product_master_id($pdo, 'categories', $categoryName);
        $brandId = resolve_product_master_id($pdo, 'brands', $brandName);

        $statement = $pdo->prepare(
            'UPDATE products
             SET category_id = :category_id,
                 brand_id = :brand_id,
                 supplier_id = :supplier_id,
                 sku = :sku,
                 barcode = :barcode,
                 name = :name,
                 model = :model,
                 description = :description,
                 cost_price = :cost_price,
                 selling_price = :selling_price,
                 wholesale_price = :wholesale_price,
                 warranty_months = :warranty_months,
                 item_tracking = :item_tracking,
                 reorder_level = :reorder_level,
                 status = "active",
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'supplier_id' => $supplierId,
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $name,
            'model' => $model,
            'description' => $description,
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'wholesale_price' => $wholesalePrice,
            'warranty_months' => $warrantyMonths,
            'item_tracking' => $itemTracking,
            'reorder_level' => $reorderLevel,
            'id' => $productId,
        ]);

        $pdo->commit();

        app_log_activity($pdo, $currentUser, 'product_update', 'Updated product ' . $sku . ' - ' . $name . '.');
        set_flash('success', 'Product updated successfully.');
        redirect('?page=products');
    }

    $pdo->beginTransaction();
    $categoryId = resolve_product_master_id($pdo, 'categories', $categoryName);
    $brandId = resolve_product_master_id($pdo, 'brands', $brandName);

    $statement = $pdo->prepare(
        'INSERT INTO products
            (category_id, brand_id, supplier_id, sku, barcode, name, model, description, cost_price, selling_price, wholesale_price, warranty_months, item_tracking, reorder_level, current_stock)
         VALUES
            (:category_id, :brand_id, :supplier_id, :sku, :barcode, :name, :model, :description, :cost_price, :selling_price, :wholesale_price, :warranty_months, :item_tracking, :reorder_level, :current_stock)'
    );
    $statement->execute([
        'category_id' => $categoryId,
        'brand_id' => $brandId,
        'supplier_id' => $supplierId,
        'sku' => $sku,
        'barcode' => $barcode,
        'name' => $name,
        'model' => $model,
        'description' => $description,
        'cost_price' => $costPrice,
        'selling_price' => $sellingPrice,
        'wholesale_price' => $wholesalePrice,
        'warranty_months' => $warrantyMonths,
        'item_tracking' => $itemTracking,
        'reorder_level' => $reorderLevel,
        'current_stock' => $openingStock,
    ]);

    $newProductId = (int) $pdo->lastInsertId();

    if ($openingStock > 0) {
        $movement = $pdo->prepare(
            'INSERT INTO stock_movements
                (product_id, movement_type, quantity_change, stock_after, unit_cost, warranty_months, reference_type, reference_id, notes, created_by, created_at)
             VALUES
                (:product_id, "opening", :quantity_change, :stock_after, :unit_cost, :warranty_months, "product", :reference_id, "Opening stock from product creation", :created_by, :created_at)'
        );
        $movement->execute([
            'product_id' => $newProductId,
            'quantity_change' => $openingStock,
            'stock_after' => $openingStock,
            'unit_cost' => $costPrice,
            'warranty_months' => $warrantyMonths,
            'reference_id' => $newProductId,
            'created_by' => (int) ($currentUser['id'] ?? 0) ?: null,
            'created_at' => $purchaseDate . ' 00:00:00',
        ]);
    }

    $pdo->commit();

    app_log_activity($pdo, $currentUser, 'product_create', 'Created product ' . $sku . ' - ' . $name . '.');
    set_flash('success', 'Product created successfully.');
    redirect('?page=products');
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($exception->getCode() === '23000') {
        set_flash('error', 'SKU or barcode already exists. Use a unique value.');
    } else {
        set_flash('error', 'Product could not be saved. Please check the database and try again.');
    }

    redirect($formRedirect);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception->getMessage());
    redirect($formRedirect);
}

function resolve_product_master_id(PDO $pdo, string $table, string $name): ?int
{
    $allowedTables = ['categories', 'brands'];

    if (! in_array($table, $allowedTables, true)) {
        return null;
    }

    $name = trim($name);

    if ($name === '') {
        return null;
    }

    if (strlen($name) > 120) {
        throw new RuntimeException(($table === 'categories' ? 'Category' : 'Brand') . ' name is too long.');
    }

    $statement = $pdo->prepare("SELECT id, is_active FROM {$table} WHERE name = :name LIMIT 1");
    $statement->execute(['name' => $name]);
    $row = $statement->fetch();

    if (is_array($row)) {
        if ((int) $row['is_active'] !== 1) {
            $restore = $pdo->prepare("UPDATE {$table} SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $restore->execute(['id' => (int) $row['id']]);
        }

        return (int) $row['id'];
    }

    $insert = $pdo->prepare("INSERT INTO {$table} (name) VALUES (:name)");
    $insert->execute(['name' => $name]);

    return (int) $pdo->lastInsertId();
}
