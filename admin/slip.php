<?php
/** Shows a transfer slip to its owner or to the central admin only. */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
$me = require_login();

$p = find_payment((int) ($_GET['id'] ?? 0));
if (!$p || ((int) $p['user_id'] !== (int) $me['id'] && $me['role'] !== 'admin')) {
    http_response_code(404);
    exit('ไม่พบสลิป');
}
$path = slip_path($p['slip_file']);
if (!$path) {
    http_response_code(404);
    exit('ไม่พบไฟล์สลิป');
}
session_write_close();
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf'], true)) $mime = 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="slip-' . (int) $p['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if ($mime !== 'application/pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
readfile($path);
