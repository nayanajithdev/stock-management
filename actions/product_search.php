<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    product_search_json(405, ['products' => []]);
}

if (! $dbReady || $pdo === null) {
    product_search_json(503, ['products' => []]);
}

$query = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($query) < 2) {
    product_search_json(200, ['products' => []]);
}

$search = '%' . $query . '%';
$prefix = $query . '%';

$statement = $pdo->prepare(
    'SELECT id,
            sku,
            barcode,
            name,
            model,
            current_stock,
            cost_price,
            warranty_months
     FROM products
     WHERE status = "active"
       AND (
            sku LIKE :search_sku
            OR name LIKE :search_name
            OR barcode LIKE :search_barcode
            OR model LIKE :search_model
       )
     ORDER BY
        CASE
            WHEN sku = :exact_sku OR barcode = :exact_barcode THEN 0
            WHEN sku LIKE :prefix_sku OR name LIKE :prefix_name THEN 1
            ELSE 2
        END,
        name ASC
     LIMIT 12'
);
$statement->execute([
    'search_sku' => $search,
    'search_name' => $search,
    'search_barcode' => $search,
    'search_model' => $search,
    'exact_sku' => $query,
    'exact_barcode' => $query,
    'prefix_sku' => $prefix,
    'prefix_name' => $prefix,
]);

$products = [];

foreach ($statement->fetchAll() as $product) {
    $model = trim((string) ($product['model'] ?? ''));
    $label = (string) $product['sku'] . ' - ' . (string) $product['name'];

    if ($model !== '') {
        $label .= ' (' . $model . ')';
    }

    $products[] = [
        'id' => (int) $product['id'],
        'label' => $label,
        'sku' => (string) $product['sku'],
        'name' => (string) $product['name'],
        'model' => $model,
        'stock' => (int) $product['current_stock'],
        'cost' => (float) $product['cost_price'],
        'warranty' => (int) $product['warranty_months'],
    ];
}

product_search_json(200, ['products' => $products]);

function product_search_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}
