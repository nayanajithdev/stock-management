<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=users');
}

verify_csrf();
auth_require_owner($currentUser);

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=users');
}

$userId = (int) ($_POST['user_id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');

if ($userId <= 0 || ! in_array($status, ['active', 'inactive'], true)) {
    set_flash('error', 'Choose a valid user status.');
    redirect('?page=users');
}

if ($userId === (int) ($currentUser['id'] ?? 0)) {
    set_flash('error', 'You cannot deactivate your own account.');
    redirect('?page=users');
}

$statement = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1');
$statement->execute(['id' => $userId]);
$user = $statement->fetch();

if (! is_array($user)) {
    set_flash('error', 'User was not found.');
    redirect('?page=users');
}

if ((string) $user['role'] === 'owner') {
    set_flash('error', 'The owner account is protected.');
    redirect('?page=users');
}

$update = $pdo->prepare(
    'UPDATE users
     SET status = :status,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = :id
       AND role <> "owner"'
);
$update->execute([
    'status' => $status,
    'id' => $userId,
]);

app_log_activity($pdo, $currentUser, 'user_status_update', 'Changed ' . auth_role_label((string) $user['role']) . ' ID ' . $userId . ' to ' . $status . '.');
set_flash('success', 'User status updated. Inactive users are logged out on their next request.');
redirect('?page=users');
