<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    send_json(['ok' => false, 'error' => 'Nem támogatott művelet.'], 405);
}

require_admin();
validate_csrf_token();
enforce_rate_limit('product_upload', 20, 900);

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    send_json(['ok' => false, 'error' => 'Hiányzó fájl feltöltés.'], 422);
}

$file = $_FILES['image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    send_json(['ok' => false, 'error' => 'A fájlfeltöltés sikertelen.'], 422);
}

$tmpName = (string) ($file['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    send_json(['ok' => false, 'error' => 'Érvénytelen feltöltési forrás.'], 422);
}

$maxSize = 5 * 1024 * 1024;
$size = (int) ($file['size'] ?? 0);
if ($size <= 0 || $size > $maxSize) {
    send_json(['ok' => false, 'error' => 'A fájl mérete legfeljebb 5MB lehet.'], 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($tmpName);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/avif' => 'avif',
];
if (!isset($allowed[$mime])) {
    send_json(['ok' => false, 'error' => 'Csak JPEG, PNG, WebP vagy AVIF kép tölthető fel.'], 422);
}

$imageInfo = @getimagesize($tmpName);
if ($imageInfo === false) {
    send_json(['ok' => false, 'error' => 'A feltöltött fájl nem érvényes kép.'], 422);
}

$rootDir = dirname(__DIR__);
$uploadDir = $rootDir . '/uploads/products';
if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
    send_json(['ok' => false, 'error' => 'A feltöltési mappa nem hozható létre.'], 500);
}
$htaccessPath = $uploadDir . '/.htaccess';
if (!is_file($htaccessPath)) {
    @file_put_contents($htaccessPath, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
}

$ext = $allowed[$mime];
$filename = 'product_' . bin2hex(random_bytes(16)) . '.' . $ext;
$target = $uploadDir . '/' . $filename;
if (!move_uploaded_file($tmpName, $target)) {
    send_json(['ok' => false, 'error' => 'A fájl mentése sikertelen.'], 500);
}

@chmod($target, 0644);

$publicPath = '/uploads/products/' . $filename;
send_json([
    'ok' => true,
    'image_url' => $publicPath,
    'mime' => $mime,
    'size' => $size,
    'width' => (int) ($imageInfo[0] ?? 0),
    'height' => (int) ($imageInfo[1] ?? 0),
]);
