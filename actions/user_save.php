<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=users');
}

verify_csrf();
auth_require_owner($currentUser);

if (! $dbReady || $pdo === null) {
    set_flash('error', 'Database is not ready.');
    redirect('?page=users');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
$userId = ($_POST['user_id'] ?? '') !== '' ? (int) $_POST['user_id'] : null;
$status = (string) ($_POST['status'] ?? 'active');
$submittedPermissions = $_POST['permissions'] ?? [];
$formRedirect = '?page=users' . ($userId !== null ? '&edit=' . $userId : '&modal=user');

try {
    validate_manager_input($fullName, $email, $username, $password, $passwordConfirm, $userId !== null);

    if (! in_array($status, ['active', 'inactive'], true)) {
        throw new RuntimeException('Choose a valid user status.');
    }

    $pdo->beginTransaction();

    if ($userId !== null) {
        if ($userId === (int) ($currentUser['id'] ?? 0)) {
            throw new RuntimeException('You cannot edit your own account here.');
        }

        $existingStatement = $pdo->prepare('SELECT id, role FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
        $existingStatement->execute(['id' => $userId]);
        $existingUser = $existingStatement->fetch();

        if (! is_array($existingUser)) {
            throw new RuntimeException('User was not found.');
        }

        if ((string) $existingUser['role'] === 'owner') {
            throw new RuntimeException('The owner account is protected.');
        }

        $sql = 'UPDATE users
                SET full_name = :full_name,
                    email = :email,
                    username = :username,
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP';
        $params = [
            'full_name' => $fullName,
            'email' => $email,
            'username' => $username,
            'status' => $status,
            'id' => $userId,
        ];

        if ($password !== '') {
            $sql .= ', password_hash = :password_hash';
            $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $sql .= ' WHERE id = :id AND role = "manager"';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        save_manager_permissions($pdo, $userId, is_array($submittedPermissions) ? $submittedPermissions : []);
        $pdo->commit();

        app_log_activity($pdo, $currentUser, 'user_update', 'Updated manager account for ' . $fullName . '.');
        set_flash('success', 'Manager account updated.');
        redirect('?page=users&edit=' . $userId);
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (full_name, email, username, password_hash, role, status)
         VALUES (:full_name, :email, :username, :password_hash, "manager", :status)'
    );
    $statement->execute([
        'full_name' => $fullName,
        'email' => $email,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'status' => $status,
    ]);
    $newUserId = (int) $pdo->lastInsertId();
    save_manager_permissions($pdo, $newUserId, is_array($submittedPermissions) ? $submittedPermissions : []);
    $pdo->commit();

    app_log_activity($pdo, $currentUser, 'user_create', 'Created manager account for ' . $fullName . '.');
    set_flash('success', 'Manager account created.');
    redirect('?page=users');
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($exception->getCode() === '23000') {
        set_flash('error', 'Email or username already exists.');
    } else {
        set_flash('error', 'Manager account could not be saved.');
    }

    redirect($formRedirect);
} catch (RuntimeException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    set_flash('error', $exception->getMessage());
    redirect($formRedirect);
}

function validate_manager_input(string $fullName, string $email, string $username, string $password, string $passwordConfirm, bool $isUpdate): void
{
    if ($fullName === '' || $email === '' || $username === '') {
        throw new RuntimeException('Full name, email, and username are required.');
    }

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    if (! preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $username)) {
        throw new RuntimeException('Username must be 3-80 characters using letters, numbers, dot, dash, or underscore.');
    }

    if (! $isUpdate && strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    if ($isUpdate && $password === '' && $passwordConfirm === '') {
        return;
    }

    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    if ($password !== $passwordConfirm) {
        throw new RuntimeException('Password confirmation does not match.');
    }
}

function save_manager_permissions(PDO $pdo, int $userId, array $submittedPermissions): void
{
    $permissionKeys = auth_permission_keys();
    $statement = $pdo->prepare(
        'INSERT INTO user_permissions (user_id, permission_key, allowed, updated_at)
         VALUES (:user_id, :permission_key, :allowed, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            allowed = VALUES(allowed),
            updated_at = CURRENT_TIMESTAMP'
    );

    foreach ($permissionKeys as $permissionKey) {
        $statement->execute([
            'user_id' => $userId,
            'permission_key' => $permissionKey,
            'allowed' => in_array($permissionKey, $submittedPermissions, true) ? 1 : 0,
        ]);
    }
}
