<?php
/** @var bool $dbReady */
?>

<?php if ($dbReady): ?>
    <div class="auth-heading">
        <h1>Create Owner</h1>
        <span>The first account is the only owner account for this shop.</span>
    </div>

    <form class="auth-form" method="post" action="<?php echo e(app_url('actions/setup_owner.php')); ?>">
        <?php echo csrf_field(); ?>

        <label class="field">
            <span>Your Name</span>
            <input type="text" name="full_name" autocomplete="name" required autofocus>
        </label>

        <label class="field">
            <span>Username</span>
            <input type="text" name="username" autocomplete="username" required>
        </label>

        <label class="field">
            <span>Email</span>
            <input type="email" name="email" autocomplete="email" required>
        </label>

        <label class="field">
            <span>Shop Name</span>
            <input type="text" name="shop_name" maxlength="120" required>
        </label>

        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="new-password" minlength="8" required>
        </label>

        <label class="field">
            <span>Confirm Password</span>
            <input type="password" name="password_confirm" autocomplete="new-password" minlength="8" required>
        </label>

        <button class="top-action" type="submit">
            <i data-lucide="user-plus"></i>
            Create Owner
        </button>
    </form>
<?php endif; ?>
