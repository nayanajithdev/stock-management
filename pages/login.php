<?php
/** @var bool $dbReady */
/** @var array $config */
$loginTitle = trim((string) ($config['shop_name'] ?? ''));
?>

<div class="auth-heading">
    <h1><?php echo e($loginTitle !== '' ? $loginTitle : 'Login'); ?></h1>
    <span>Login to continue</span>
</div>

<?php if (! $dbReady): ?>
    <p class="empty-state">Import <code>database/schema.sql</code> before logging in.</p>
<?php else: ?>
    <form class="auth-form" method="post" action="<?php echo e(app_url('actions/login.php')); ?>">
        <?php echo csrf_field(); ?>

        <label class="field">
            <span>Username or Email</span>
            <input type="text" name="login" autocomplete="username" required autofocus>
        </label>

        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <div class="auth-options">
            <label class="checkbox-line">
                <input type="checkbox" name="stay_logged_in" value="1">
                <span>Stay logged in</span>
            </label>
            <a href="#" aria-disabled="true">Forgot password?</a>
        </div>

        <button class="top-action" type="submit">
            <i data-lucide="log-in"></i>
            Login
        </button>
    </form>
<?php endif; ?>
