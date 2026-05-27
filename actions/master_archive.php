<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=inventory-setup');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=inventory-setup');
}

$entity = (string) ($_POST['entity'] ?? '');
$id = (int) ($_POST['id'] ?? 0);
$entityConfig = [
    'category' => ['table' => 'categories', 'label' => 'Category', 'redirect' => 'categories'],
    'brand' => ['table' => 'brands', 'label' => 'Brand', 'redirect' => 'brands'],
    'supplier' => ['table' => 'suppliers', 'label' => 'Supplier', 'redirect' => 'suppliers'],
];

if ($id <= 0 || ! isset($entityConfig[$entity])) {
    set_flash('error', 'Invalid setup record selected.');
    redirect('?page=inventory-setup');
}

$table = $entityConfig[$entity]['table'];
$label = $entityConfig[$entity]['label'];
$redirectSection = $entityConfig[$entity]['redirect'];

try {
    $pdo->beginTransaction();

    $recordStatement = $pdo->prepare("SELECT id, name FROM {$table} WHERE id = :id LIMIT 1 FOR UPDATE");
    $recordStatement->execute(['id' => $id]);
    $record = $recordStatement->fetch();

    if (! is_array($record)) {
        throw new RuntimeException($label . ' was not found.');
    }

    unlink_master_references($pdo, $entity, $id);

    $statement = $pdo->prepare("DELETE FROM {$table} WHERE id = :id");
    $statement->execute(['id' => $id]);

    $pdo->commit();

    app_log_activity($pdo, $currentUser, strtolower($label) . '_delete', 'Deleted ' . strtolower($label) . ' ' . (string) $record['name'] . '.');
    set_flash('success', $label . ' deleted successfully.');
    redirect('?page=inventory-setup&section=' . $redirectSection);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : $label . ' could not be deleted.');
    redirect('?page=inventory-setup&section=' . $redirectSection);
}

function unlink_master_references(PDO $pdo, string $entity, int $id): void
{
    if ($entity === 'category') {
        $statement = $pdo->prepare('UPDATE products SET category_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE category_id = :id');
        $statement->execute(['id' => $id]);

        return;
    }

    if ($entity === 'brand') {
        $statement = $pdo->prepare('UPDATE products SET brand_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE brand_id = :id');
        $statement->execute(['id' => $id]);

        return;
    }

    if ($entity === 'supplier') {
        foreach ([
            'UPDATE products SET supplier_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE supplier_id = :id',
            'UPDATE purchases SET supplier_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE supplier_id = :id',
            'UPDATE supplier_payments SET supplier_id = NULL WHERE supplier_id = :id',
        ] as $sql) {
            $statement = $pdo->prepare($sql);
            $statement->execute(['id' => $id]);
        }
    }
}
