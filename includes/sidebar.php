<aside class="sidebar" id="sidebar">
    <div class="brand-card">
        <div class="brand-mark">S</div>
        <div>
            <span class="brand-kicker">Computer Shop</span>
            <strong><?php echo e($config['shop_name']); ?></strong>
        </div>
        <i data-lucide="chevrons-up-down"></i>
    </div>

    <nav class="side-nav" aria-label="Primary">
        <?php foreach ($menuSections as $section => $items): ?>
            <section class="nav-section">
                <h2><?php echo e($section); ?></h2>
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
        <?php endforeach; ?>
    </nav>
</aside>
