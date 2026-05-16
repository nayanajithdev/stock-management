<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=profile');
}

verify_csrf();

if (! $dbReady || $pdo === null || ! is_array($currentUser)) {
    set_flash('error', 'Password cannot be updated right now.');
    redirect('?page=profile');
}

$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');
$userId = (int) $currentUser['id'];

try {
    if ($currentPassword === '' || $newPassword === '' || $newPasswordConfirm === '') {
        throw new RuntimeException('Complete all password fields.');
    }

    if (strlen($newPassword) < 8) {
        throw new RuntimeException('New password must be at least 8 characters.');
    }

    if ($newPassword !== $newPasswordConfirm) {
        throw new RuntimeException('Password confirmation does not match.');
    }

    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $userId]);
    $passwordHash = (string) $statement->fetchColumn();

    if ($passwordHash === '' || ! password_verify($currentPassword, $passwordHash)) {
        throw new RuntimeException('Current password is not correct.');
    }

    $updateStatement = $pdo->prepare(
        'UPDATE users
         SET password_hash = :password_hash,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    );
    $updateStatement->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $userId,
    ]);

    app_log_activity($pdo, $currentUser, 'password_update', 'Updated own password.');
    set_flash('success', 'Password updated.');
    redirect('?page=profile');
} catch (RuntimeException $exception) {
    set_flash('error', $exception->getMessage());
    redirect('?page=profile');
}
