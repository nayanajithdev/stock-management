<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=login');
}

verify_csrf();

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=login');
}

$login = trim((string) ($_POST['login'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($login === '' || $password === '') {
    set_flash('error', 'Enter your username or email and password.');
    redirect('?page=login');
}

$statement = $pdo->prepare(
    'SELECT id, full_name, email, username, password_hash, role, status
     FROM users
     WHERE username = :login OR email = :login
     LIMIT 1'
);
$statement->execute(['login' => $login]);
$user = $statement->fetch();

if (! is_array($user) || ! password_verify($password, (string) $user['password_hash'])) {
    set_flash('error', 'Login details are not correct.');
    redirect('?page=login');
}

if ((string) $user['status'] !== 'active') {
    set_flash('error', 'Your account is inactive. Contact the owner.');
    redirect('?page=login');
}

auth_login_user($user);
app_log_activity($pdo, $user, 'login', 'Logged in successfully.');
set_flash('success', 'Logged in successfully.');
redirect('?page=dashboard');
