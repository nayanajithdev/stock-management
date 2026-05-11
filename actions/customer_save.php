<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=customers');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Import database/schema.sql before saving customers.');
    redirect('?page=customers');
}

$customerId = ($_POST['customer_id'] ?? '') !== '' ? (int) $_POST['customer_id'] : null;
$name = trim((string) ($_POST['name'] ?? ''));
$phone = nullable_string((string) ($_POST['phone'] ?? ''));
$email = nullable_string((string) ($_POST['email'] ?? ''));
$address = nullable_string((string) ($_POST['address'] ?? ''));
$creditLimit = max(0.0, input_decimal('credit_limit'));

if ($name === '') {
    set_flash('error', 'Customer name is required.');
    redirect('?page=customers' . ($customerId !== null ? '&edit=' . $customerId : ''));
}

if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    set_flash('error', 'Customer email is not valid.');
    redirect('?page=customers' . ($customerId !== null ? '&edit=' . $customerId : ''));
}

try {
    if ($customerId !== null) {
        $statement = $pdo->prepare(
            'UPDATE customers
             SET name = :name,
                 phone = :phone,
                 email = :email,
                 address = :address,
                 credit_limit = :credit_limit,
                 is_active = 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'credit_limit' => $creditLimit,
            'id' => $customerId,
        ]);
    } else {
        $existingCustomer = null;

        if ($phone !== null) {
            $findStatement = $pdo->prepare('SELECT id FROM customers WHERE phone = :phone LIMIT 1');
            $findStatement->execute(['phone' => $phone]);
            $existingCustomer = $findStatement->fetch();
        }

        if (is_array($existingCustomer)) {
            $statement = $pdo->prepare(
                'UPDATE customers
                 SET name = :name,
                     email = :email,
                     address = :address,
                     credit_limit = :credit_limit,
                     is_active = 1,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'address' => $address,
                'credit_limit' => $creditLimit,
                'id' => (int) $existingCustomer['id'],
            ]);
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO customers (name, phone, email, address, credit_limit)
                 VALUES (:name, :phone, :email, :address, :credit_limit)'
            );
            $statement->execute([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'credit_limit' => $creditLimit,
            ]);
        }
    }

    set_flash('success', 'Customer saved successfully.');
    redirect('?page=customers');
} catch (PDOException $exception) {
    set_flash('error', 'Customer could not be saved.');
    redirect('?page=customers' . ($customerId !== null ? '&edit=' . $customerId : ''));
}
