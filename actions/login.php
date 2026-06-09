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
$loginIdentifier = auth_login_identifier($login);
$ipAddress = auth_client_ip();

if ($login === '' || $password === '') {
    set_flash('error', 'Enter your username or email and password.');
    redirect('?page=login');
}

auth_prune_login_attempts($pdo);

$lockout = auth_login_lockout($pdo, $loginIdentifier, $ipAddress);

if ($lockout !== null) {
    app_log_security_event(
        'login_blocked',
        'Blocked repeated login attempts for ' . substr($loginIdentifier, 0, 80) . '.'
    );
    set_flash('error', 'Too many failed login attempts. Try again in ' . (int) $lockout['remaining_minutes'] . ' minute(s).');
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
    auth_record_login_attempt(
        $pdo,
        $loginIdentifier,
        $ipAddress,
        is_array($user) ? (int) $user['id'] : null,
        false,
        'invalid_credentials'
    );
    app_log_security_event('login_failed', 'Failed login for ' . substr($loginIdentifier, 0, 80) . '.');
    set_flash('error', 'Login details are not correct.');
    redirect('?page=login');
}

if ((string) $user['status'] !== 'active') {
    auth_record_login_attempt($pdo, $loginIdentifier, $ipAddress, (int) $user['id'], false, 'inactive_account');
    app_log_security_event('login_inactive', 'Inactive account login blocked for ' . substr($loginIdentifier, 0, 80) . '.', $user);
    set_flash('error', 'Your account is inactive. Contact the owner.');
    redirect('?page=login');
}

auth_clear_login_failures($pdo, $loginIdentifier, $ipAddress);
auth_record_login_attempt($pdo, $loginIdentifier, $ipAddress, (int) $user['id'], true);
auth_login_user($user);
app_log_activity($pdo, $user, 'login', 'Logged in successfully.');
set_flash('success', 'Logged in successfully.');
redirect('?page=dashboard');
