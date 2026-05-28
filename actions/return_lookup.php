<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    return_lookup_json(405, ['matches' => []]);
}

if (! $dbReady || $pdo === null) {
    return_lookup_json(503, ['matches' => []]);
}

$type = (string) ($_GET['type'] ?? 'search');

try {
    match ($type) {
        'invoices' => return_lookup_json(200, ['invoices' => return_lookup_invoices($pdo, (int) ($_GET['customer_id'] ?? 0))]),
        'items' => return_lookup_json(200, ['items' => return_lookup_items($pdo, (int) ($_GET['sale_id'] ?? 0))]),
        default => return_lookup_json(200, ['matches' => return_lookup_search($pdo, trim((string) ($_GET['q'] ?? '')))]),
    };
} catch (Throwable) {
    return_lookup_json(500, ['matches' => []]);
}

function return_lookup_search(PDO $pdo, string $query): array
{
    if (mb_strlen($query) < 2) {
        return [];
    }

    $search = '%' . $query . '%';
    $prefix = $query . '%';
    $matches = [];

    $customerStatement = $pdo->prepare(
        'SELECT c.id,
                c.name,
                c.phone,
                c.email,
                COUNT(DISTINCT s.id) AS invoice_count,
                COALESCE(SUM(si.quantity - COALESCE(r.returned_quantity, 0)), 0) AS available_units
         FROM customers c
         INNER JOIN sales s ON s.customer_id = c.id
         INNER JOIN sale_items si ON si.sale_id = s.id
         LEFT JOIN (
            SELECT sale_item_id, COALESCE(SUM(quantity), 0) AS returned_quantity
            FROM sales_return_items
            GROUP BY sale_item_id
         ) r ON r.sale_item_id = si.id
         WHERE c.is_active = 1
           AND (c.name LIKE :search OR c.phone LIKE :search OR c.email LIKE :search)
         GROUP BY c.id
         HAVING available_units > 0
         ORDER BY
            CASE
                WHEN c.phone = :exact OR c.email = :exact THEN 0
                WHEN c.name LIKE :prefix OR c.phone LIKE :prefix THEN 1
                ELSE 2
            END,
            c.name ASC
         LIMIT 8'
    );
    $customerStatement->execute([
        'search' => $search,
        'exact' => $query,
        'prefix' => $prefix,
    ]);

    foreach ($customerStatement->fetchAll() as $customer) {
        $phone = trim((string) ($customer['phone'] ?? ''));
        $matches[] = [
            'type' => 'customer',
            'id' => (int) $customer['id'],
            'label' => (string) $customer['name'],
            'meta' => trim($phone . ' / ' . (int) $customer['invoice_count'] . ' invoice(s) / ' . (int) $customer['available_units'] . ' returnable unit(s)', ' /'),
        ];
    }

    if (! return_lookup_should_search_invoices($query)) {
        return $matches;
    }

    $invoiceStatement = $pdo->prepare(
        'SELECT s.id AS sale_id,
                s.invoice_no,
                s.sale_date,
                s.total,
                COALESCE(c.name, "Walk-in Customer") AS customer_name,
                c.phone AS customer_phone,
                COUNT(si.id) AS item_count,
                COALESCE(SUM(si.quantity - COALESCE(r.returned_quantity, 0)), 0) AS available_units
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         INNER JOIN sale_items si ON si.sale_id = s.id
         LEFT JOIN (
            SELECT sale_item_id, COALESCE(SUM(quantity), 0) AS returned_quantity
            FROM sales_return_items
            GROUP BY sale_item_id
         ) r ON r.sale_item_id = si.id
         WHERE s.invoice_no LIKE :search
         GROUP BY s.id
         HAVING available_units > 0
         ORDER BY
            CASE WHEN s.invoice_no LIKE :prefix THEN 0 ELSE 1 END,
            s.sale_date DESC,
            s.id DESC
         LIMIT 8'
    );
    $invoiceStatement->execute([
        'search' => $search,
        'prefix' => $prefix,
    ]);

    foreach ($invoiceStatement->fetchAll() as $invoice) {
        $matches[] = return_lookup_invoice_payload($invoice);
    }

    return $matches;
}

function return_lookup_should_search_invoices(string $query): bool
{
    return preg_match('/^INV[\w-]*/i', trim($query)) === 1;
}

function return_lookup_invoices(PDO $pdo, int $customerId): array
{
    if ($customerId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT s.id AS sale_id,
                s.invoice_no,
                s.sale_date,
                s.total,
                COALESCE(c.name, "Walk-in Customer") AS customer_name,
                c.phone AS customer_phone,
                COUNT(si.id) AS item_count,
                COALESCE(SUM(si.quantity - COALESCE(r.returned_quantity, 0)), 0) AS available_units
         FROM sales s
         LEFT JOIN customers c ON c.id = s.customer_id
         INNER JOIN sale_items si ON si.sale_id = s.id
         LEFT JOIN (
            SELECT sale_item_id, COALESCE(SUM(quantity), 0) AS returned_quantity
            FROM sales_return_items
            GROUP BY sale_item_id
         ) r ON r.sale_item_id = si.id
         WHERE s.customer_id = :customer_id
         GROUP BY s.id
         HAVING available_units > 0
         ORDER BY s.sale_date DESC, s.id DESC
         LIMIT 40'
    );
    $statement->execute(['customer_id' => $customerId]);

    return array_map('return_lookup_invoice_payload', $statement->fetchAll());
}

function return_lookup_items(PDO $pdo, int $saleId): array
{
    if ($saleId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT si.id AS sale_item_id,
                si.sale_id,
                si.product_id,
                si.quantity AS sold_quantity,
                si.unit_price,
                p.sku,
                p.name AS product_name,
                p.model,
                COALESCE(r.returned_quantity, 0) AS returned_quantity,
                si.quantity - COALESCE(r.returned_quantity, 0) AS available_quantity
         FROM sale_items si
         INNER JOIN products p ON p.id = si.product_id
         LEFT JOIN (
            SELECT sale_item_id, COALESCE(SUM(quantity), 0) AS returned_quantity
            FROM sales_return_items
            GROUP BY sale_item_id
         ) r ON r.sale_item_id = si.id
         WHERE si.sale_id = :sale_id
         HAVING available_quantity > 0
         ORDER BY si.id ASC'
    );
    $statement->execute(['sale_id' => $saleId]);

    $items = [];

    foreach ($statement->fetchAll() as $item) {
        $items[] = [
            'sale_item_id' => (int) $item['sale_item_id'],
            'label' => (string) $item['sku'] . ' - ' . (string) $item['product_name'],
            'sku' => (string) $item['sku'],
            'product' => (string) $item['product_name'],
            'model' => (string) ($item['model'] ?? ''),
            'sold' => (int) $item['sold_quantity'],
            'returned' => (int) $item['returned_quantity'],
            'available' => (int) $item['available_quantity'],
            'price' => number_format((float) $item['unit_price'], 2, '.', ''),
        ];
    }

    return $items;
}

function return_lookup_invoice_payload(array $invoice): array
{
    return [
        'type' => 'invoice',
        'sale_id' => (int) $invoice['sale_id'],
        'label' => (string) $invoice['invoice_no'],
        'invoice_no' => (string) $invoice['invoice_no'],
        'date' => date('Y-m-d H:i', strtotime((string) $invoice['sale_date'])),
        'customer' => (string) $invoice['customer_name'],
        'phone' => (string) ($invoice['customer_phone'] ?? ''),
        'total' => number_format((float) $invoice['total'], 2, '.', ''),
        'items' => (int) $invoice['item_count'],
        'available' => (int) $invoice['available_units'],
        'meta' => (string) $invoice['customer_name'] . ' / ' . date('Y-m-d H:i', strtotime((string) $invoice['sale_date'])) . ' / ' . (int) $invoice['available_units'] . ' returnable unit(s)',
    ];
}

function return_lookup_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}
