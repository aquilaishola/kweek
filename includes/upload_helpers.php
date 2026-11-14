<?php
/**
 * Image upload helpers for checkout logos and menu-item photos.
 * Plain move_uploaded_file() — no Composer/CLI needed, works on
 * any standard cPanel/shared-hosting PHP setup.
 */

const KWEEK_UPLOAD_MAX_BYTES = 2 * 1024 * 1024; // 2MB
const KWEEK_UPLOAD_MIME_MAP  = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/**
 * Validates and moves an uploaded image into $subdir under /uploads,
 * returning the public URL, or false if the file failed validation.
 */
function kweekHandleImageUpload(array $file, int $ownerId, string $subdir): string|false {
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > KWEEK_UPLOAD_MAX_BYTES) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset(KWEEK_UPLOAD_MIME_MAP[$mime])) return false;

    $ext      = KWEEK_UPLOAD_MIME_MAP[$mime];
    $filename = $ownerId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destDir  = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/uploads/' . $subdir . '/';

    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
    }

    $destPath = $destDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) return false;

    return rtrim(SITE_URL, '/') . '/uploads/' . $subdir . '/' . $filename;
}

function handleLogoUpload(array $file, int $userId): string|false {
    return kweekHandleImageUpload($file, $userId, 'logos');
}

function handleItemImageUpload(array $file, int $userId): string|false {
    return kweekHandleImageUpload($file, $userId, 'items');
}
