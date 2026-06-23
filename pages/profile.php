<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var ?array $currentUser */

$profileUser = is_array($currentUser) ? $currentUser : [];
$profileName = (string) ($profileUser['full_name'] ?? '');
$profileEmail = (string) ($profileUser['email'] ?? '');
$profileUsername = (string) ($profileUser['username'] ?? '');
$profileRole = auth_role_label((string) ($profileUser['role'] ?? 'cashier'));
?>

<div class="page-heading">
    <div>
        <h1>Profile</h1>
    </div>
</div>

<section class="profile-layout">
    <article class="panel">
        <div class="panel-header compact">
            <h2>Profile Details</h2>
            <span class="status status-ready"><?php echo e($profileRole); ?></span>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before updating your profile.</p>
        <?php else: ?>
            <form class="profile-form" method="post" action="<?php echo e(app_url('actions/profile_save.php')); ?>">
                <?php echo csrf_field(); ?>

                <label class="field">
                    <span>Name</span>
                    <input type="text" name="full_name" value="<?php echo e($profileName); ?>" maxlength="120" required>
                </label>

                <label class="field">
                    <span>Username</span>
                    <input type="text" value="<?php echo e($profileUsername); ?>" readonly>
                </label>

                <label class="field">
                    <span>Email</span>
                    <input type="email" name="email" value="<?php echo e($profileEmail); ?>" maxlength="160" required>
                </label>

                <label class="field">
                    <span>Role</span>
                    <input type="text" value="<?php echo e($profileRole); ?>" readonly>
                    <small>Role cannot be changed from profile.</small>
                </label>

                <div class="form-actions span-2">
                    <button class="top-action" type="submit">
                        <i data-lucide="save"></i>
                        Save Profile
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <article class="panel">
        <div class="panel-header compact">
            <h2>Change Password</h2>
        </div>

        <?php if (! $dbReady): ?>
            <p class="empty-state">Import <code>database/schema.sql</code> before changing your password.</p>
        <?php else: ?>
            <form class="profile-password-form" method="post" action="<?php echo e(app_url('actions/profile_password.php')); ?>">
                <?php echo csrf_field(); ?>

                <label class="field">
                    <span>Current Password</span>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>

                <label class="field">
                    <span>New Password</span>
                    <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
                </label>

                <label class="field">
                    <span>Confirm New Password</span>
                    <input type="password" name="new_password_confirm" autocomplete="new-password" minlength="8" required>
                </label>

                <div class="security-note">
                    <strong>Security note</strong>
                    <span>Use a unique password with at least 8 characters.</span>
                </div>

                <div class="form-actions">
                    <button class="top-action" type="submit">
                        <i data-lucide="lock-keyhole"></i>
                        Update Password
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </article>
</section>
