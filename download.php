<?php
/**
 * Original-file downloads for clients.
 *   GET  download.php?t=TOKEN&i=IMAGE_ID          → one original (also used by "save to Photos")
 *   POST download.php  t=TOKEN, ids=1,2,3 | all=1 → ZIP of the originals
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/proxy.php';
require __DIR__ . '/inc/zipstream.php';

function deny(int $code, string $msg): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

$token = (string) ($_REQUEST['t'] ?? '');
$album = $token !== '' ? find_album_by_token($token) : null;
if (!$album || !album_is_public($album)) deny(404, 'ลิงก์นี้หมดอายุหรือถูกปิดแล้ว');
$selling = sell_on($album);
if (!$selling && !(int) $album['allow_download']) deny(403, 'อัลบั้มนี้ไม่ได้เปิดให้ดาวน์โหลด');
// Selling galleries: originals only for photos this client (k = their browser key) has paid for.
$owned = $selling ? client_owned((int) $album['id'], (string) ($_REQUEST['k'] ?? '')) : null;
$mayHave = fn(array $img) => !$owned || $owned['all'] || isset($owned['ids'][(int) $img['id']]);

$albumId = (int) $album['id'];
$count = function () use ($albumId, $album) {
    db()->prepare('UPDATE albums SET downloads = downloads + 1 WHERE id = ?')->execute([$albumId]);
    if (!viewer_is_owner($album)) {
        notify((int) $album['user_id'], 'download', 'ลูกค้าเริ่มบันทึกภาพจาก “' . $album['title'] . '”', '',
            $albumId, abs_url('admin/album.php?id=' . $albumId), 'dl:' . $albumId);
    }
};

/* ---------- single original ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $img = find_image($albumId, (int) ($_GET['i'] ?? 0));
    if (!$img || (int) $img['hidden']) deny(404, 'ไม่พบภาพ');
    if (!$mayHave($img)) deny(402, 'ภาพนี้ต้องซื้อก่อนจึงจะดาวน์โหลดไฟล์ต้นฉบับได้');
    if (($_GET['c'] ?? '1') === '1') $count();

    $name = download_name($img, (int) $img['sort'], (string) $img['mime']);
    if (!stream_remote(upstream_url($img, 'full'), $name)) {
        // Original not reachable → fall back to the large preview so the client still gets a photo.
        $got = fetch_image_bytes(upstream_url($img, 'preview'));
        if (!$got) deny(502, 'ดาวน์โหลดภาพไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        header('Content-Type: ' . $got[1]);
        header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . strlen($got[0]));
        echo $got[0];
    }
    exit;
}

/* ---------- ZIP of many originals ---------- */
$all = album_images($albumId);
if (empty($_POST['all'])) {
    $want = array_flip(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
    $all = array_values(array_filter($all, fn($im) => isset($want[(int) $im['id']])));
}
$all = array_values(array_filter($all, $mayHave));
if (!$all) deny($selling ? 402 : 400, $selling ? 'ยังไม่มีภาพที่ซื้อแล้วในรายการนี้' : 'ยังไม่ได้เลือกภาพ');

@set_time_limit(0);
@ini_set('memory_limit', '1024M');
ignore_user_abort(false);
$count();

$zipName = preg_replace('~[\\\\/:*?"<>|]+~', '', $album['title']) ?: 'photos';
$zip = new ZipStream($zipName . '.zip');
$failed = [];
foreach ($all as $n => $img) {
    if (connection_aborted()) exit;
    $got = fetch_image_bytes(upstream_url($img, 'full'), 300) ?? fetch_image_bytes(upstream_url($img, 'preview'), 120);
    $name = download_name($img, $n + 1, $got[1] ?? (string) $img['mime']);
    if (!$got) {
        $failed[] = $name;
        continue;
    }
    $zip->addFile($zip->uniqueName($name, (string) ($img['section'] ?? '')), $got[0]);
    unset($got);
}
if ($failed) {
    $zip->addFile('_ภาพที่ดาวน์โหลดไม่สำเร็จ.txt', "ภาพต่อไปนี้ดาวน์โหลดไม่สำเร็จ กรุณาลองดาวน์โหลดทีละภาพ:\r\n" . implode("\r\n", $failed));
}
$zip->finish();
