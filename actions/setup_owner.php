<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=setup-owner');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=setup-owner');
}

if (auth_configured_owner_exists($pdo)) {
    set_flash('error', 'The owner account already exists.');
    redirect('?page=login');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

try {
    validate_owner_setup_input($fullName, $email, $username, $password, $passwordConfirm);

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->beginTransaction();

    $ownerStatement = $pdo->query('SELECT id FROM users WHERE role = "owner" ORDER BY id ASC LIMIT 1 FOR UPDATE');
    $existingOwnerId = (int) ($ownerStatement->fetchColumn() ?: 0);

    if ($existingOwnerId > 0) {
        $statement = $pdo->prepare(
            'UPDATE users
             SET full_name = :full_name,
                 email = :email,
                 username = :username,
                 password_hash = :password_hash,
                 role = "owner",
                 status = "active",
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'full_name' => $fullName,
            'email' => $email,
            'username' => $username,
            'password_hash' => $passwordHash,
            'id' => $existingOwnerId,
        ]);
        $ownerId = $existingOwnerId;
    } else {
        $statement = $pdo->prepare(
            'INSERT INTO users (full_name, email, username, password_hash, role, status)
             VALUES (:full_name, :email, :username, :password_hash, "owner", "active")'
        );
        $statement->execute([
            'full_name' => $fullName,
            'email' => $email,
            'username' => $username,
            'password_hash' => $passwordHash,
        ]);
        $ownerId = (int) $pdo->lastInsertId();
    }

    $cleanupOwners = $pdo->prepare('UPDATE users SET role = "manager" WHERE role = "owner" AND id <> :owner_id');
    $cleanupOwners->execute(['owner_id' => $ownerId]);

    $pdo->commit();

    app_log_activity($pdo, ['id' => $ownerId], 'owner_create', 'Created the first owner account for ' . $fullName . '.');

    auth_login_user([
        'id' => $ownerId,
        'role' => 'owner',
    ]);
    set_flash('success', 'Owner account created.');
    redirect('?page=dashboard');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = $exception instanceof RuntimeException ? $exception->getMessage() : 'Owner account could not be created.';
    set_flash('error', $message);
    redirect('?page=setup-owner');
}

function validate_owner_setup_input(string $fullName, string $email, string $username, string $password, string $passwordConfirm): void
{
    if ($fullName === '' || $email === '' || $username === '') {
        throw new RuntimeException('Full name, email, and username are required.');
    }

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    if (! preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username)) {
        throw new RuntimeException('Username must be 3-80 characters using letters, numbers, dot, dash, or underscore.');
    }

    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    if ($password !== $passwordConfirm) {
        throw new RuntimeException('Password confirmation does not match.');
    }
}
