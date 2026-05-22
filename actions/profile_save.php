<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=profile');
}

verify_csrf();

if (! $dbReady || $pdo === null || ! is_array($currentUser)) {
    set_flash('error', 'Profile cannot be updated right now.');
    redirect('?page=profile');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$userId = (int) $currentUser['id'];

try {
    if ($fullName === '' || strlen($fullName) > 120) {
        throw new RuntimeException('Name is required and must be 120 characters or less.');
    }

    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
        throw new RuntimeException('Enter a valid email address.');
    }

    $duplicateStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM users
         WHERE email = :email
           AND id <> :id'
    );
    $duplicateStatement->execute([
        'email' => $email,
        'id' => $userId,
    ]);

    if ((int) $duplicateStatement->fetchColumn() > 0) {
        throw new RuntimeException('That email is already used by another user.');
    }

    $statement = $pdo->prepare(
        'UPDATE users
         SET full_name = :full_name,
             email = :email,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $statement->execute([
        'full_name' => $fullName,
        'email' => $email,
        'id' => $userId,
    ]);

    app_log_activity($pdo, ['id' => $userId, 'full_name' => $fullName], 'profile_update', 'Updated own profile.');
    set_flash('success', 'Profile updated.');
    redirect('?page=profile');
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        set_flash('error', 'That email is already used by another user.');
    } else {
        set_flash('error', 'Profile could not be updated.');
    }

    redirect('?page=profile');
} catch (RuntimeException $exception) {
    set_flash('error', $exception->getMessage());
    redirect('?page=profile');
}
