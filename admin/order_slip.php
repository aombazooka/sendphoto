<?php
/** Shows a client's transfer slip to the shop that owns the gallery (or the central admin). */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
$me = require_login();

$st = db()->prepare('SELECT o.*, a.user_id FROM orders o JOIN albums a ON a.id = o.album_id WHERE o.id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$o = $st->fetch();
if (!$o || ((int) $o['user_id'] !== (int) $me['id'] && $me['role'] !== 'admin')) {
    http_response_code(404);
    exit('ไม่พบสลิป');
}
$path = slip_path($o['slip_file']);
if (!$path) {
    http_response_code(404);
    exit('ไม่พบไฟล์สลิป');
}
session_write_close();
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf'], true)) $mime = 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="order-slip-' . (int) $o['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if ($mime !== 'application/pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
readfile($path);
