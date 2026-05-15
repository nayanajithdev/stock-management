<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($pdo !== null && is_array($currentUser)) {
    app_log_activity($pdo, $currentUser, 'logout', 'Logged out successfully.');
}

auth_logout_session();
set_flash('success', 'Logged out successfully.');
redirect('?page=login');
