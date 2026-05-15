<?php
/** @var array $config */
/** @var string $pageTitle */
/** @var ?array $flash */
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var ?string $dbError */
/** @var string $currentPage */
$isAuthPage = in_array($currentPage, ['login', 'setup-owner'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?> | <?php echo e($config['app_name']); ?></title>
    <link rel="preconnect" href="https://unpkg.com">
    <link rel="stylesheet" href="<?php echo e(app_url('assets/app.css')); ?>">
</head>
<body>
    <?php if ($isAuthPage): ?>
    <main class="auth-shell">
        <section class="auth-card">
            <?php if ($flash !== null): ?>
                <div class="flash flash-<?php echo e($flash['type'] ?? 'info'); ?>">
                    <?php echo e($flash['message'] ?? ''); ?>
                </div>
            <?php endif; ?>

            <?php if ($pdo === null): ?>
                <div class="setup-notice">
                    <i data-lucide="database-zap"></i>
                    <div>
                        <strong>Database is not connected.</strong>
                        <span>Create the database and import <code>database/schema.sql</code> before creating owner.</span>
                    </div>
                </div>
            <?php elseif (! $dbReady): ?>
                <div class="setup-notice">
                    <i data-lucide="database"></i>
                    <div>
                        <strong>Database connected, but tables are missing.</strong>
                        <span>Import <code>database/schema.sql</code> using phpMyAdmin or MySQL CLI.</span>
                    </div>
                </div>
            <?php endif; ?>
    <?php else: ?>
    <div class="app-shell">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <div class="workspace">
            <?php include __DIR__ . '/topbar.php'; ?>

            <main class="content">
                <?php if ($flash !== null): ?>
                    <div class="flash flash-<?php echo e($flash['type'] ?? 'info'); ?>">
                        <?php echo e($flash['message'] ?? ''); ?>
                    </div>
                <?php endif; ?>

                <?php if ($pdo === null): ?>
                    <div class="setup-notice">
                        <i data-lucide="database-zap"></i>
                        <div>
                            <strong>Database is not connected.</strong>
                            <span>Create the database and import <code>database/schema.sql</code>. Current config expects database <code>stock_management</code>.</span>
                            <?php if ($dbError !== null): ?>
                                <small><?php echo e($dbError); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif (! $dbReady): ?>
                    <div class="setup-notice">
                        <i data-lucide="database"></i>
                        <div>
                            <strong>Database connected, but tables are missing.</strong>
                            <span>Import <code>database/schema.sql</code> using phpMyAdmin or MySQL CLI.</span>
                        </div>
                    </div>
                <?php endif; ?>
    <?php endif; ?>
