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
$categoryId = ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
$brandId = ($_POST['brand_id'] ?? '') !== '' ? (int) $_POST['brand_id'] : null;
$supplierId = ($_POST['supplier_id'] ?? '') !== '' ? (int) $_POST['supplier_id'] : null;
$costPrice = input_decimal('cost_price');
$sellingPrice = input_decimal('selling_price');
$wholesalePrice = input_decimal('wholesale_price');
$warrantyMonths = input_int('warranty_months');
$reorderLevel = input_int('reorder_level');
$openingStock = input_int('opening_stock');
$formRedirect = '?page=products' . ($productId !== null ? '&edit=' . $productId : '&form=product');

if ($name === '' || $sku === '') {
    set_flash('error', 'Product name and SKU are required.');
    redirect($formRedirect);
}

if ($sellingPrice < $costPrice) {
    set_flash('error', 'Selling price should be equal to or higher than cost price.');
    redirect($formRedirect);
}

try {
    if ($productId !== null) {
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
            'reorder_level' => $reorderLevel,
            'id' => $productId,
        ]);

        app_log_activity($pdo, $currentUser, 'product_update', 'Updated product ' . $sku . ' - ' . $name . '.');
        set_flash('success', 'Product updated successfully.');
        redirect('?page=products');
    }

    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'INSERT INTO products
            (category_id, brand_id, supplier_id, sku, barcode, name, model, description, cost_price, selling_price, wholesale_price, warranty_months, reorder_level, current_stock)
         VALUES
            (:category_id, :brand_id, :supplier_id, :sku, :barcode, :name, :model, :description, :cost_price, :selling_price, :wholesale_price, :warranty_months, :reorder_level, :current_stock)'
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
        'reorder_level' => $reorderLevel,
        'current_stock' => $openingStock,
    ]);

    $newProductId = (int) $pdo->lastInsertId();

    if ($openingStock > 0) {
        $movement = $pdo->prepare(
            'INSERT INTO stock_movements
                (product_id, movement_type, quantity_change, stock_after, unit_cost, reference_type, reference_id, notes)
             VALUES
                (:product_id, "opening", :quantity_change, :stock_after, :unit_cost, "product", :reference_id, "Opening stock from product creation")'
        );
        $movement->execute([
            'product_id' => $newProductId,
            'quantity_change' => $openingStock,
            'stock_after' => $openingStock,
            'unit_cost' => $costPrice,
            'reference_id' => $newProductId,
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
}
