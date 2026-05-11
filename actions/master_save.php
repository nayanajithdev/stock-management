<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=inventory-setup');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving setup data.');
    redirect('?page=inventory-setup');
}

$entity = (string) ($_POST['entity'] ?? '');
$entityConfig = [
    'category' => [
        'table' => 'categories',
        'label' => 'Category',
        'redirect' => 'categories',
    ],
    'brand' => [
        'table' => 'brands',
        'label' => 'Brand',
        'redirect' => 'brands',
    ],
    'supplier' => [
        'table' => 'suppliers',
        'label' => 'Supplier',
        'redirect' => 'suppliers',
    ],
];

if (! isset($entityConfig[$entity])) {
    set_flash('error', 'Invalid setup type selected.');
    redirect('?page=inventory-setup');
}

$configForEntity = $entityConfig[$entity];
$table = $configForEntity['table'];
$label = $configForEntity['label'];
$redirectSection = $configForEntity['redirect'];
$id = ($_POST['id'] ?? '') !== '' ? (int) $_POST['id'] : null;
$name = trim((string) ($_POST['name'] ?? ''));

if ($name === '') {
    set_flash('error', $label . ' name is required.');
    redirect('?page=inventory-setup&section=' . $redirectSection . ($id !== null ? '&edit_type=' . $entity . '&edit_id=' . $id : ''));
}

try {
    if ($entity === 'category') {
        $description = nullable_string((string) ($_POST['description'] ?? ''));

        if ($id !== null) {
            $statement = $pdo->prepare('UPDATE categories SET name = :name, description = :description, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $statement->execute([
                'name' => $name,
                'description' => $description,
                'id' => $id,
            ]);
        } else {
            $existing = find_master_by_name($pdo, 'categories', $name);

            if ($existing !== null) {
                $statement = $pdo->prepare('UPDATE categories SET description = :description, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $statement->execute([
                    'description' => $description,
                    'id' => (int) $existing['id'],
                ]);
            } else {
                $statement = $pdo->prepare('INSERT INTO categories (name, description) VALUES (:name, :description)');
                $statement->execute([
                    'name' => $name,
                    'description' => $description,
                ]);
            }
        }
    }

    if ($entity === 'brand') {
        if ($id !== null) {
            $statement = $pdo->prepare('UPDATE brands SET name = :name, is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $statement->execute([
                'name' => $name,
                'id' => $id,
            ]);
        } else {
            $existing = find_master_by_name($pdo, 'brands', $name);

            if ($existing !== null) {
                $statement = $pdo->prepare('UPDATE brands SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $statement->execute(['id' => (int) $existing['id']]);
            } else {
                $statement = $pdo->prepare('INSERT INTO brands (name) VALUES (:name)');
                $statement->execute(['name' => $name]);
            }
        }
    }

    if ($entity === 'supplier') {
        $contactPerson = nullable_string((string) ($_POST['contact_person'] ?? ''));
        $phone = nullable_string((string) ($_POST['phone'] ?? ''));
        $email = nullable_string((string) ($_POST['email'] ?? ''));
        $address = nullable_string((string) ($_POST['address'] ?? ''));

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            set_flash('error', 'Supplier email is not valid.');
            redirect('?page=inventory-setup&section=suppliers' . ($id !== null ? '&edit_type=supplier&edit_id=' . $id : ''));
        }

        $data = [
            'name' => $name,
            'contact_person' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
        ];

        if ($id !== null) {
            $statement = $pdo->prepare(
                'UPDATE suppliers
                 SET name = :name,
                     contact_person = :contact_person,
                     phone = :phone,
                     email = :email,
                     address = :address,
                     is_active = 1,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $statement->execute($data + ['id' => $id]);
        } else {
            $existing = find_master_by_name($pdo, 'suppliers', $name);

            if ($existing !== null) {
                $statement = $pdo->prepare(
                    'UPDATE suppliers
                     SET contact_person = :contact_person,
                         phone = :phone,
                         email = :email,
                         address = :address,
                         is_active = 1,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $statement->execute([
                    'contact_person' => $contactPerson,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address,
                    'id' => (int) $existing['id'],
                ]);
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO suppliers (name, contact_person, phone, email, address)
                     VALUES (:name, :contact_person, :phone, :email, :address)'
                );
                $statement->execute($data);
            }
        }
    }

    set_flash('success', $label . ' saved successfully.');
    redirect('?page=inventory-setup&section=' . $redirectSection);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        set_flash('error', $label . ' name already exists.');
    } else {
        set_flash('error', $label . ' could not be saved.');
    }

    redirect('?page=inventory-setup&section=' . $redirectSection . ($id !== null ? '&edit_type=' . $entity . '&edit_id=' . $id : ''));
}

function find_master_by_name(PDO $pdo, string $table, string $name): ?array
{
    $allowedTables = ['categories', 'brands', 'suppliers'];

    if (! in_array($table, $allowedTables, true)) {
        return null;
    }

    $statement = $pdo->prepare("SELECT id, is_active FROM {$table} WHERE name = :name LIMIT 1");
    $statement->execute(['name' => $name]);
    $row = $statement->fetch();

    return is_array($row) ? $row : null;
}
