<header class="topbar">
    <button class="icon-button menu-toggle" type="button" aria-label="Open menu" data-sidebar-toggle>
        <i data-lucide="menu"></i>
    </button>

    <?php if (isset($currentUser) && is_array($currentUser)): ?>
        <?php
        $canOpenSales = isset($pdo) && $pdo instanceof PDO && auth_user_has_permission($pdo, $currentUser, 'sales');
        $profileName = trim((string) ($currentUser['full_name'] ?? 'User'));
        $profileRole = (string) ($currentUser['role_label'] ?? auth_role_label((string) ($currentUser['role'] ?? 'cashier')));
        ?>
        <?php if ($canOpenSales): ?>
            <a class="top-action topbar-sales-link <?php echo $currentPage === 'sales' ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=sales')); ?>">
                <i data-lucide="scan-barcode"></i>
                Sales
            </a>
        <?php endif; ?>

        <div class="topbar-account">
            <span class="online-pill">Online</span>

            <div class="user-menu" data-user-menu>
                <button class="user-menu-toggle" type="button" aria-haspopup="true" aria-expanded="false" data-user-menu-toggle>
                    <span class="user-avatar user-avatar-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </span>
                    <span class="user-menu-text">
                        <strong><?php echo e($profileName); ?></strong>
                        <small><?php echo e($profileRole); ?></small>
                    </span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-down-icon lucide-chevron-down" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>

                <div class="user-menu-dropdown" role="menu">
                    <a href="<?php echo e(app_url('?page=profile')); ?>" role="menuitem">Account</a>
                    <a class="user-menu-logout" href="<?php echo e(app_url('actions/logout.php')); ?>" role="menuitem">Logout</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</header>
