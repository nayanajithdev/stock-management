<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    supplier_search_json(405, ['suppliers' => []]);
}

if (! $dbReady || $pdo === null) {
    supplier_search_json(503, ['suppliers' => []]);
}

$query = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($query) < 2) {
    supplier_search_json(200, ['suppliers' => []]);
}

$search = '%' . $query . '%';
$prefix = $query . '%';

$statement = $pdo->prepare(
    'SELECT id,
            name,
            contact_person,
            phone,
            email
     FROM suppliers
     WHERE is_active = 1
       AND (
            name LIKE :search_name
            OR contact_person LIKE :search_contact
            OR phone LIKE :search_phone
            OR email LIKE :search_email
       )
     ORDER BY
        CASE
            WHEN name = :exact_name OR phone = :exact_phone OR email = :exact_email THEN 0
            WHEN name LIKE :prefix_name OR phone LIKE :prefix_phone THEN 1
            ELSE 2
        END,
        name ASC
     LIMIT 12'
);
$statement->execute([
    'search_name' => $search,
    'search_contact' => $search,
    'search_phone' => $search,
    'search_email' => $search,
    'exact_name' => $query,
    'exact_phone' => $query,
    'exact_email' => $query,
    'prefix_name' => $prefix,
    'prefix_phone' => $prefix,
]);

$suppliers = [];

foreach ($statement->fetchAll() as $supplier) {
    $contact = trim((string) ($supplier['contact_person'] ?? ''));
    $phone = trim((string) ($supplier['phone'] ?? ''));
    $email = trim((string) ($supplier['email'] ?? ''));

    $suppliers[] = [
        'id' => (int) $supplier['id'],
        'label' => (string) $supplier['name'],
        'name' => (string) $supplier['name'],
        'contact' => $contact,
        'phone' => $phone,
        'email' => $email,
    ];
}

supplier_search_json(200, ['suppliers' => $suppliers]);

function supplier_search_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}
