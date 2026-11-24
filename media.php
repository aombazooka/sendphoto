<?php
/**
 * Image proxy: the browser never sees Drive file IDs or Immich keys.
 *   media.php?t=TOKEN&i=IMAGE_ID&s=thumb|preview   (client pages)
 *   media.php?i=IMAGE_ID&s=thumb                   (admin, needs login)
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/proxy.php';

function fail(int $code): never
{
    http_response_code($code);
    header('Cache-Control: no-store, private');
    exit;
}

$imageId = (int) ($_GET['i'] ?? 0);
$size    = ($_GET['s'] ?? 'thumb') === 'preview' ? 'preview' : 'thumb';
$token   = (string) ($_GET['t'] ?? '');

if ($token !== '') {
    $album = find_album_by_token($token);
    if (!$album || !album_is_public($album)) fail(404);   // expiry is checked on every request
    $img = find_image((int) $album['id'], $imageId);
    if (!$img || (int) $img['hidden']) fail(404);
} else {
    $me = current_user();
    if (!$me) fail(403);
    session_write_close();
    // Only photos from galleries of the signed-in account.
    $st = db()->prepare('SELECT i.* FROM images i JOIN albums a ON a.id = i.album_id WHERE i.id = ? AND a.user_id = ?');
    $st->execute([$imageId, $me['id']]);
    $img = $st->fetch() ?: null;
    if (!$img) fail(404);
}

$file  = cache_path($img, $size);
$etag  = '"' . basename($file) . '"';

if (!is_file($file)) {
    $got = fetch_image_bytes(upstream_url($img, $size), 60);
    if (!$got && ($fb = upstream_fallback($img, $size))) $got = fetch_image_bytes($fb, 60);
    if (!$got) $got = preview_from_original($img, $size);
    if (!$got) fail(502);
    cache_store($img, $size, $got[0], $got[1]);
}

// Clients of a watermarked / selling gallery only ever receive watermarked previews.
if ($token !== '' && watermark_on($album)) {
    $wmSize = $size . '-wm' . watermark_version((int) $album['user_id']);
    $wmFile = cache_path($img, $wmSize);
    if (!is_file($wmFile)) {
        $wm = apply_watermark((string) file_get_contents($file), (int) $album['user_id']);
        if (!$wm) fail(500);
        cache_store($img, $wmSize, $wm[0], $wm[1]);
    }
    $file = $wmFile;
    $etag = '"' . basename($file) . '"';
}

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

$mime = is_file($file . '.type') ? trim((string) file_get_contents($file . '.type')) : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
// Revalidate every time (cheap 304) so closing / expiring a link takes effect immediately.
header('Cache-Control: private, no-cache');
header('ETag: ' . $etag);
header('X-Robots-Tag: noindex, nofollow');
readfile($file);
