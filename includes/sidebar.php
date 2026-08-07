<?php
$shopLogoPath = trim((string) ($config['shop_logo'] ?? ''));
$shopLogoUrl = $shopLogoPath !== '' ? app_url($shopLogoPath) : '';
$shopName = trim((string) ($config['shop_name'] ?? ''));
$shopInitial = strtoupper(substr($shopName !== '' ? $shopName : 'S', 0, 1));
?>
<aside class="sidebar" id="sidebar">
    <div class="brand-card">
        <div class="brand-mark">
            <?php if ($shopLogoUrl !== ''): ?>
                <img src="<?php echo e($shopLogoUrl); ?>" alt="">
            <?php else: ?>
                <?php echo e($shopInitial); ?>
            <?php endif; ?>
        </div>
        <div>
            <strong><?php echo e($shopName); ?></strong>
        </div>
    </div>

    <nav class="side-nav" aria-label="Primary">
        <?php foreach ($menuSections as $section => $items): ?>
            <?php
            $isDropdownSection = in_array($section, ['Management', 'Settings'], true);
            $sectionIsActive = false;

            foreach ($items as $item) {
                if (($item['key'] ?? '') === $currentPage) {
                    $sectionIsActive = true;
                    break;
                }
            }
            ?>

            <?php if ($isDropdownSection): ?>
                <section class="nav-section nav-dropdown <?php echo $sectionIsActive ? 'open' : ''; ?>" data-nav-dropdown>
                    <button class="nav-dropdown-toggle" type="button" aria-expanded="<?php echo $sectionIsActive ? 'true' : 'false'; ?>">
                        <span>
                            <i data-lucide="<?php echo $section === 'Settings' ? 'settings' : 'briefcase-business'; ?>"></i>
                            <?php echo e($section); ?>
                        </span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-down-icon lucide-chevron-down" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>

                    <div class="nav-dropdown-items">
                        <?php foreach ($items as $item): ?>
                            <?php
                            $isDisabled = ! empty($item['disabled']);
                            $isActive = ($item['key'] ?? '') === $currentPage;
                            $href = $isDisabled ? '#' : app_url('?page=' . rawurlencode((string) $item['key']));
                            ?>
                            <a class="nav-link <?php echo $isActive ? 'active' : ''; ?> <?php echo $isDisabled ? 'disabled' : ''; ?>" href="<?php echo e($href); ?>" <?php echo $isDisabled ? 'aria-disabled="true"' : ''; ?>>
                                <i data-lucide="<?php echo e($item['icon']); ?>"></i>
                                <span><?php echo e($item['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php else: ?>
                <section class="nav-section">
                    <?php if (! in_array($section, ['Main Menu', 'Customers'], true)): ?>
                        <h2><?php echo e($section); ?></h2>
                    <?php endif; ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $isDisabled = ! empty($item['disabled']);
                        $isActive = ($item['key'] ?? '') === $currentPage;
                        $href = $isDisabled ? '#' : app_url('?page=' . rawurlencode((string) $item['key']));
                        $isProductForm = $currentPage === 'products' && ((string) ($_GET['form'] ?? '') === 'product' || isset($_GET['edit']));
                        ?>
                        <?php if (($item['key'] ?? '') === 'products' && ! $isDisabled): ?>
                            <div class="nav-split-row">
                                <a class="nav-link <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo e($href); ?>">
                                    <i data-lucide="<?php echo e($item['icon']); ?>"></i>
                                    <span><?php echo e($item['label']); ?></span>
                                </a>
                                <a class="nav-side-action <?php echo $isProductForm ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=products&form=product#product-form')); ?>" title="Add Product" aria-label="Add Product">
                                    <i data-lucide="circle-plus"></i>
                                </a>
                            </div>
                        <?php elseif (($item['key'] ?? '') === 'purchases' && ! $isDisabled): ?>
                            <div class="nav-split-row">
                                <a class="nav-link <?php echo ($isActive || $currentPage === 'purchase-history') ? 'active' : ''; ?>" href="<?php echo e($href); ?>">
                                    <i data-lucide="<?php echo e($item['icon']); ?>"></i>
                                    <span><?php echo e($item['label']); ?></span>
                                </a>
                                <a class="nav-side-action <?php echo $currentPage === 'purchase-history' ? 'active' : ''; ?>" href="<?php echo e(app_url('?page=purchase-history')); ?>" title="Stock History" aria-label="Stock History">
                                    <i data-lucide="history"></i>
                                </a>
                            </div>
                        <?php else: ?>
                            <a class="nav-link <?php echo $isActive ? 'active' : ''; ?> <?php echo $isDisabled ? 'disabled' : ''; ?>" href="<?php echo e($href); ?>" <?php echo $isDisabled ? 'aria-disabled="true"' : ''; ?>>
                                <i data-lucide="<?php echo e($item['icon']); ?>"></i>
                                <span><?php echo e($item['label']); ?></span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if (isset($currentUser) && is_array($currentUser)): ?>
        <?php
        $sidebarProfileName = trim((string) ($currentUser['full_name'] ?? 'User'));
        $sidebarProfileRole = (string) ($currentUser['role_label'] ?? auth_role_label((string) ($currentUser['role'] ?? 'cashier')));
        ?>
        <div class="sidebar-account">
            <div class="user-menu" data-user-menu>
                <button class="user-menu-toggle" type="button" aria-haspopup="true" aria-expanded="false" data-user-menu-toggle>
                    <span class="user-avatar user-avatar-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </span>
                    <span class="user-menu-text">
                        <strong><?php echo e($sidebarProfileName); ?></strong>
                        <small><?php echo e($sidebarProfileRole); ?></small>
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
</aside>
