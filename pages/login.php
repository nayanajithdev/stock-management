<?php
/** @var bool $dbReady */
?>

<div class="auth-heading">
    <p class="eyebrow">Secure access</p>
    <h1>Login</h1>
    <span>Use your username or email to continue.</span>
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

        <button class="top-action" type="submit">
            <i data-lucide="log-in"></i>
            Login
        </button>
    </form>
<?php endif; ?>
