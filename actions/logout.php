<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=dashboard');
}

verify_csrf();

if ($pdo !== null && is_array($currentUser)) {
    app_log_activity($pdo, $currentUser, 'logout', 'Logged out successfully.');
}

auth_forget_remember_token($pdo);
auth_logout_session();
set_flash('success', 'Logged out successfully.');
redirect('?page=login');
