<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    customer_search_json(405, ['customers' => []]);
}

if (! $dbReady || $pdo === null) {
    customer_search_json(503, ['customers' => []]);
}

$query = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($query) < 2) {
    customer_search_json(200, ['customers' => []]);
}

$search = '%' . $query . '%';
$prefix = $query . '%';

$statement = $pdo->prepare(
    'SELECT id,
            name,
            phone,
            email
     FROM customers
     WHERE is_active = 1
       AND (
            name LIKE :search_name
            OR phone LIKE :search_phone
            OR email LIKE :search_email
       )
     ORDER BY
        CASE
            WHEN phone = :exact_phone OR email = :exact_email THEN 0
            WHEN name LIKE :prefix_name OR phone LIKE :prefix_phone THEN 1
            ELSE 2
        END,
        name ASC
     LIMIT 12'
);
$statement->execute([
    'search_name' => $search,
    'search_phone' => $search,
    'search_email' => $search,
    'exact_phone' => $query,
    'exact_email' => $query,
    'prefix_name' => $prefix,
    'prefix_phone' => $prefix,
]);

$customers = [];

foreach ($statement->fetchAll() as $customer) {
    $phone = trim((string) ($customer['phone'] ?? ''));
    $email = trim((string) ($customer['email'] ?? ''));
    $label = (string) $customer['name'];

    if ($phone !== '') {
        $label .= ' / ' . $phone;
    }

    $customers[] = [
        'id' => (int) $customer['id'],
        'label' => $label,
        'name' => (string) $customer['name'],
        'phone' => $phone,
        'email' => $email,
    ];
}

customer_search_json(200, ['customers' => $customers]);

function customer_search_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}
