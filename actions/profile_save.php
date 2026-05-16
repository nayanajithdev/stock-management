<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('?page=profile');
}

verify_csrf();

if (! $dbReady || $pdo === null || ! is_array($currentUser)) {
    set_flash('error', 'Profile cannot be updated right now.');
    redirect('?page=profile');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$userId = (int) $currentUser['id'];
$newProfileImage = null;
$newProfileImagePath = null;
$oldProfileImage = (string) ($currentUser['profile_image'] ?? '');

try {
    if ($fullName === '' || strlen($fullName) > 120) {
        throw new RuntimeException('Name is required and must be 120 characters or less.');
    }

    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
        throw new RuntimeException('Enter a valid email address.');
    }

    $duplicateStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM users
         WHERE email = :email
           AND id <> :id'
    );
    $duplicateStatement->execute([
        'email' => $email,
        'id' => $userId,
    ]);

    if ((int) $duplicateStatement->fetchColumn() > 0) {
        throw new RuntimeException('That email is already used by another user.');
    }

    if (isset($_FILES['profile_image']) && is_array($_FILES['profile_image']) && (int) ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (! auth_users_have_profile_image_column($pdo)) {
            throw new RuntimeException('Update the database schema before uploading a profile image.');
        }

        [$newProfileImage, $newProfileImagePath] = store_profile_image($_FILES['profile_image'], $userId);
    }

    $sql = 'UPDATE users
            SET full_name = :full_name,
                email = :email,
                updated_at = CURRENT_TIMESTAMP';
    $params = [
        'full_name' => $fullName,
        'email' => $email,
        'id' => $userId,
    ];

    if ($newProfileImage !== null) {
        $sql .= ', profile_image = :profile_image';
        $params['profile_image'] = $newProfileImage;
    }

    $sql .= ' WHERE id = :id';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    if ($newProfileImage !== null) {
        delete_old_profile_image($oldProfileImage, $newProfileImage);
    }

    app_log_activity($pdo, ['id' => $userId, 'full_name' => $fullName], 'profile_update', 'Updated own profile.');
    set_flash('success', 'Profile updated.');
    redirect('?page=profile');
} catch (PDOException $exception) {
    if ($newProfileImagePath !== null && is_file($newProfileImagePath)) {
        @unlink($newProfileImagePath);
    }

    if ($exception->getCode() === '23000') {
        set_flash('error', 'That email is already used by another user.');
    } else {
        set_flash('error', 'Profile could not be updated.');
    }

    redirect('?page=profile');
} catch (RuntimeException $exception) {
    if ($newProfileImagePath !== null && is_file($newProfileImagePath)) {
        @unlink($newProfileImagePath);
    }

    set_flash('error', $exception->getMessage());
    redirect('?page=profile');
}

function store_profile_image(array $upload, int $userId): array
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile image upload failed.');
    }

    $tmpName = (string) ($upload['tmp_name'] ?? '');

    if ($tmpName === '' || ! is_uploaded_file($tmpName)) {
        throw new RuntimeException('Choose a valid profile image.');
    }

    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Profile image must be 5MB or smaller.');
    }

    $imageInfo = @getimagesize($tmpName);
    $mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (! isset($allowed[$mime])) {
        throw new RuntimeException('Upload a JPG, PNG, WEBP, or GIF image.');
    }

    $uploadDir = __DIR__ . '/../uploads/profile-icons';
    if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0775, true) && ! is_dir($uploadDir)) {
        throw new RuntimeException('Profile image folder is not writable.');
    }

    $filename = 'user-' . $userId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (! move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Profile image could not be saved.');
    }

    return ['uploads/profile-icons/' . $filename, $targetPath];
}

function delete_old_profile_image(string $oldProfileImage, string $newProfileImage): void
{
    if ($oldProfileImage === '' || $oldProfileImage === $newProfileImage || ! str_starts_with($oldProfileImage, 'uploads/profile-icons/')) {
        return;
    }

    $basePath = realpath(__DIR__ . '/../uploads/profile-icons');
    $oldPath = realpath(__DIR__ . '/../' . $oldProfileImage);

    if ($basePath === false || $oldPath === false) {
        return;
    }

    if (str_starts_with($oldPath, $basePath . DIRECTORY_SEPARATOR) && is_file($oldPath)) {
        @unlink($oldPath);
    }
}
