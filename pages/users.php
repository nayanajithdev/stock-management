<?php
/** @var ?PDO $pdo */
/** @var bool $dbReady */
/** @var ?array $currentUser */

$userSearch = trim((string) ($_GET['q'] ?? ''));
$editUserId = (int) ($_GET['edit'] ?? 0);
$users = [];
$editingUser = null;
$editingPermissions = [];
$permissionDefinitions = auth_permission_definitions();
$summary = [
    'owners' => 0,
    'managers' => 0,
    'active' => 0,
    'inactive' => 0,
];
$canManageUsers = ($currentUser['role'] ?? '') === 'owner';

if ($dbReady && $pdo !== null) {
    $summary['owners'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "owner"')->fetchColumn();
    $summary['managers'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "manager"')->fetchColumn();
    $summary['active'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "active"')->fetchColumn();
    $summary['inactive'] = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE status = "inactive"')->fetchColumn();

    $userSql = 'SELECT id, full_name, email, username, role, status, created_at, updated_at
                FROM users';
    $params = [];

    if ($userSearch !== '') {
        $userSql .= ' WHERE full_name LIKE :search OR email LIKE :search OR username LIKE :search OR role LIKE :search OR status LIKE :search';
        $params['search'] = '%' . $userSearch . '%';
    }

    $userSql .= ' ORDER BY role = "owner" DESC, full_name ASC';
    $statement = $pdo->prepare($userSql);
    $statement->execute($params);
    $users = $statement->fetchAll();

    if ($editUserId > 0) {
        $editStatement = $pdo->prepare(
            'SELECT id, full_name, email, username, role, status
             FROM users
             WHERE id = :id
               AND role = "manager"
             LIMIT 1'
        );
        $editStatement->execute(['id' => $editUserId]);
        $editingUser = $editStatement->fetch() ?: null;

        if (is_array($editingUser)) {
            $editingPermissions = auth_user_permissions($pdo, (int) $editingUser['id']);
        }
    }
}

$isEditing = is_array($editingUser);
$showUserForm = $canManageUsers && ($isEditing || (string) ($_GET['modal'] ?? '') === 'user');
?>

<div class="page-heading">
    <div>
        <p class="eyebrow">Access control</p>
        <h1>Users & Roles</h1>
    </div>
    <?php if ($canManageUsers && ! $showUserForm): ?>
        <a class="top-action" href="<?php echo e(app_url('?page=users&modal=user#user-form')); ?>">
            <i data-lucide="user-plus"></i>
            New Manager
        </a>
    <?php elseif ($showUserForm): ?>
        <a class="top-action" href="<?php echo e(app_url('?page=users')); ?>">
            <i data-lucide="arrow-left"></i>
            Users List
        </a>
    <?php endif; ?>
</div>

<?php if ($showUserForm): ?>
    <section class="user-form-window" id="user-form">
        <article class="panel">
            <div class="panel-header">
                <div>
                    <p class="panel-label">Manager Account</p>
                    <h2><?php echo $isEditing ? 'Edit manager' : 'Create manager'; ?></h2>
                </div>
                <div class="modal-actions">
                    <?php if ($isEditing): ?>
                        <a class="muted-link" href="<?php echo e(app_url('?page=users&modal=user#user-form')); ?>">New manager</a>
                    <?php endif; ?>
                    <a class="icon-button" href="<?php echo e(app_url('?page=users')); ?>" aria-label="Close manager form">
                        <i data-lucide="x"></i>
                    </a>
                </div>
            </div>

            <?php if (! $dbReady): ?>
                <p class="empty-state">Import <code>database/schema.sql</code> before managing users.</p>
            <?php else: ?>
                <form class="user-form" method="post" action="<?php echo e(app_url('actions/user_save.php')); ?>">
                    <?php echo csrf_field(); ?>
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="user_id" value="<?php echo (int) $editingUser['id']; ?>">
                    <?php endif; ?>

                    <label class="field">
                        <span>Full Name</span>
                        <input type="text" name="full_name" value="<?php echo e($editingUser['full_name'] ?? ''); ?>" required autofocus>
                    </label>

                    <label class="field">
                        <span>Email</span>
                        <input type="email" name="email" value="<?php echo e($editingUser['email'] ?? ''); ?>" required>
                    </label>

                    <label class="field">
                        <span>Username</span>
                        <input type="text" name="username" value="<?php echo e($editingUser['username'] ?? ''); ?>" required>
                    </label>

                    <label class="field">
                        <span>Role</span>
                        <input type="text" value="Manager" readonly>
                    </label>

                    <label class="field">
                        <span>Status</span>
                        <select name="status">
                            <option value="active" <?php echo ($editingUser['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($editingUser['status'] ?? 'active') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </label>

                    <label class="field">
                        <span><?php echo $isEditing ? 'New Password (Optional)' : 'Password'; ?></span>
                        <input type="password" name="password" minlength="8" autocomplete="new-password" <?php echo $isEditing ? 'placeholder="Leave blank to keep current password"' : 'required'; ?>>
                    </label>

                    <label class="field">
                        <span>Confirm Password</span>
                        <input type="password" name="password_confirm" minlength="8" autocomplete="new-password" <?php echo $isEditing ? '' : 'required'; ?>>
                    </label>

                    <div class="permission-panel span-2">
                        <div>
                            <strong>Module Permissions</strong>
                            <span>Owner always has full access. These permissions apply to this manager only.</span>
                        </div>

                        <div class="permission-grid">
                            <?php foreach ($permissionDefinitions as $permissionKey => $permission): ?>
                                <?php $checked = $editingPermissions === [] ? true : ($editingPermissions[$permissionKey] ?? false); ?>
                                <label class="permission-card">
                                    <input type="checkbox" name="permissions[]" value="<?php echo e($permissionKey); ?>" <?php echo $checked ? 'checked' : ''; ?> <?php echo $permissionKey === 'dashboard' ? 'disabled' : ''; ?>>
                                    <?php if ($permissionKey === 'dashboard'): ?>
                                        <input type="hidden" name="permissions[]" value="dashboard">
                                    <?php endif; ?>
                                    <span>
                                        <strong><?php echo e($permission['label']); ?></strong>
                                        <small><?php echo e($permission['description']); ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-actions span-2">
                        <a class="ghost-button" href="<?php echo e(app_url('?page=users')); ?>">Cancel</a>
                        <button class="top-action" type="submit">
                            <i data-lucide="save"></i>
                            <?php echo $isEditing ? 'Update Manager' : 'Save Manager'; ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </article>
    </section>
<?php else: ?>
<section class="user-layout user-directory-layout">
    <article class="panel table-panel">
        <div class="panel-header">
            <div>
                <p class="panel-label">User Directory</p>
                <h2>System users</h2>
            </div>

            <form class="filter-row" method="get" action="<?php echo e(app_url('')); ?>">
                <input type="hidden" name="page" value="users">
                <input type="search" name="q" value="<?php echo e($userSearch); ?>" placeholder="Name, email, username">
                <button class="icon-button" type="submit" aria-label="Search users">
                    <i data-lucide="search"></i>
                </button>
            </form>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($users === []): ?>
                        <tr>
                            <td colspan="7">No users found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($users as $user): ?>
                        <?php
                        $isOwner = (string) $user['role'] === 'owner';
                        $isCurrentUser = (int) $user['id'] === (int) ($currentUser['id'] ?? 0);
                        $nextStatus = (string) $user['status'] === 'active' ? 'inactive' : 'active';
                        ?>
                        <tr>
                            <td><?php echo e($user['full_name']); ?></td>
                            <td><?php echo e($user['email'] ?? ''); ?></td>
                            <td><?php echo e($user['username']); ?></td>
                            <td><?php echo e(auth_role_label((string) $user['role'])); ?></td>
                            <td><span class="status <?php echo (string) $user['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo e(ucfirst((string) $user['status'])); ?></span></td>
                            <td><?php echo e(date('Y-m-d', strtotime((string) $user['created_at']))); ?></td>
                            <td>
                                <?php if ($canManageUsers && ! $isOwner && ! $isCurrentUser): ?>
                                    <div class="table-actions">
                                        <a class="icon-button" href="<?php echo e(app_url('?page=users&edit=' . (int) $user['id'] . '#user-form')); ?>" aria-label="Edit user">
                                            <i data-lucide="pencil"></i>
                                        </a>
                                        <form method="post" action="<?php echo e(app_url('actions/user_status.php')); ?>">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                            <input type="hidden" name="status" value="<?php echo e($nextStatus); ?>">
                                            <button class="icon-button <?php echo $nextStatus === 'inactive' ? 'danger-button' : ''; ?>" type="submit" aria-label="<?php echo e(ucfirst($nextStatus)); ?> user">
                                                <i data-lucide="<?php echo $nextStatus === 'inactive' ? 'user-x' : 'user-check'; ?>"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="muted-link"><?php echo $isOwner ? 'Protected' : 'View only'; ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>
<?php endif; ?>
