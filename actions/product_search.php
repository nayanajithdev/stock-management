<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    product_search_json(405, ['products' => []]);
}

if (! $dbReady || $pdo === null) {
    product_search_json(503, ['products' => []]);
}

$canViewProductCost = auth_can_view_product_cost($pdo, $currentUser ?? null);
$query = trim((string) ($_GET['q'] ?? ''));
$categoryId = max(0, (int) ($_GET['category_id'] ?? 0));

if (str_starts_with($query, '@')) {
    $categoryQuery = trim(mb_substr($query, 1));
    $categoryWhere = 'c.is_active = 1
                      AND p.status = "active"';
    $categoryParams = [];

    if ($categoryQuery !== '') {
        $categoryWhere .= ' AND c.name LIKE :category_search';
        $categoryParams['category_search'] = '%' . $categoryQuery . '%';
    }

    $categorySql = 'SELECT c.id,
                           c.name,
                           COUNT(p.id) AS product_count
                    FROM categories c
                    INNER JOIN products p ON p.category_id = c.id
                    WHERE ' . $categoryWhere . '
                    GROUP BY c.id, c.name
                    ORDER BY ';

    if ($categoryQuery !== '') {
        $categorySql .= 'CASE
                            WHEN c.name = :category_exact THEN 0
                            WHEN c.name LIKE :category_prefix THEN 1
                            ELSE 2
                         END, ';
        $categoryParams['category_exact'] = $categoryQuery;
        $categoryParams['category_prefix'] = $categoryQuery . '%';
    }

    $categorySql .= 'c.name ASC
                     LIMIT 12';

    $statement = $pdo->prepare($categorySql);
    $statement->execute($categoryParams);

    $categories = [];

    foreach ($statement->fetchAll() as $category) {
        $categories[] = [
            'id' => (int) $category['id'],
            'name' => (string) $category['name'],
            'product_count' => (int) $category['product_count'],
        ];
    }

    product_search_json(200, [
        'mode' => 'categories',
        'categories' => $categories,
        'products' => [],
    ]);
}

if (mb_strlen($query) < 2 && $categoryId <= 0) {
    product_search_json(200, ['products' => []]);
}

$search = '%' . $query . '%';
$prefix = $query . '%';
$where = [
    'p.status = "active"',
];
$params = [];

if ($categoryId > 0) {
    $where[] = 'p.category_id = :category_id';
    $params['category_id'] = $categoryId;
}

if (mb_strlen($query) >= 2) {
    $where[] = '(p.sku LIKE :search_sku
                 OR p.name LIKE :search_name
                 OR p.barcode LIKE :search_barcode
                 OR p.model LIKE :search_model)';
    $params += [
        'search_sku' => $search,
        'search_name' => $search,
        'search_barcode' => $search,
        'search_model' => $search,
    ];
}

$orderSql = 'p.name ASC';

if (mb_strlen($query) >= 2) {
    $orderSql = 'CASE
                    WHEN p.sku = :exact_sku OR p.barcode = :exact_barcode THEN 0
                    WHEN p.sku LIKE :prefix_sku OR p.name LIKE :prefix_name THEN 1
                    ELSE 2
                 END,
                 p.name ASC';
    $params += [
        'exact_sku' => $query,
        'exact_barcode' => $query,
        'prefix_sku' => $prefix,
        'prefix_name' => $prefix,
    ];
}

$statement = $pdo->prepare(
    'SELECT p.id,
            p.sku,
            p.barcode,
            p.name,
            p.model,
            p.current_stock,
            p.cost_price,
            p.warranty_months,
            c.name AS category_name
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY ' . $orderSql . '
     LIMIT 20'
);
$statement->execute($params);

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
        'category' => (string) ($product['category_name'] ?? ''),
        'stock' => (int) $product['current_stock'],
        'cost' => $canViewProductCost ? (float) $product['cost_price'] : null,
        'cost_hidden' => ! $canViewProductCost,
        'warranty' => (int) $product['warranty_months'],
    ];
}

product_search_json(200, [
    'mode' => 'products',
    'products' => $products,
]);

function product_search_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}
