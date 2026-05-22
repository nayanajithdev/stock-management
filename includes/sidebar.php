<aside class="sidebar" id="sidebar">
    <div class="brand-card">
        <div class="brand-mark">S</div>
        <div>
            <span class="brand-kicker">Computer Shop</span>
            <strong><?php echo e($config['shop_name']); ?></strong>
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
                        ?>
                        <a class="nav-link <?php echo $isActive ? 'active' : ''; ?> <?php echo $isDisabled ? 'disabled' : ''; ?>" href="<?php echo e($href); ?>" <?php echo $isDisabled ? 'aria-disabled="true"' : ''; ?>>
                            <i data-lucide="<?php echo e($item['icon']); ?>"></i>
                            <span><?php echo e($item['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</aside>
