<?php
ob_start();

require_once __DIR__ . '/includes/admin_functions.php';
requireAdminLogin();
requireAdminCapability('manage_kyc');

$userId = (int) get('user_id');
$type   = get('type'); // 'nin' | 'poa'

if (!$userId || !in_array($type, ['nin', 'poa'], true)) {
    ob_end_clean();
    http_response_code(400);
    exit('Bad request');
}

$stmt = db()->prepare("SELECT nin_document_path, proof_of_address_path FROM user_kyc WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$row = $stmt->fetch();

if (!$row) {
    ob_end_clean();
    http_response_code(404);
    exit('Not found');
}

$stored = $type === 'nin' ? $row['nin_document_path'] : $row['proof_of_address_path'];
$path   = resolveKycDocumentPath($stored);

if (!$path || !is_file($path)) {
    ob_end_clean();
    http_response_code(404);
    exit('Not found');
}

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'pdf'   => 'application/pdf',
    'png'   => 'image/png',
    default => 'image/jpeg',
};

// Discard any stray output (BOM, whitespace, warnings) that accumulated
// before this point — it would otherwise get prepended to the binary
// data below and corrupt the file the browser receives.
ob_end_clean();

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;