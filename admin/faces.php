<?php
/**
 * Face index for one gallery (built in the photographer's browser).
 *   GET  faces.php?id=ALBUM                    → photos still to scan
 *   POST faces.php action=save  id, image, descs=["base64", …]
 *   POST faces.php action=clear id
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
header('Cache-Control: no-store, private');
$me = require_login(true);

$album = find_my_album((int) ($_REQUEST['id'] ?? 0), $me);
if (!$album) json_out(['ok' => false, 'error' => 'ไม่พบอัลบั้ม'], 404);
if (!has_feature($me, 'face')) json_out(['ok' => false, 'error' => 'แพ็กเกจปัจจุบันไม่มีฟีเจอร์ค้นหาด้วยใบหน้า'], 403);
$albumId = (int) $album['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    $st = db()->prepare('SELECT * FROM images WHERE album_id = ? AND hidden = 0 AND face_indexed = 0 ORDER BY sort, id');
    $st->execute([$albumId]);
    json_out(['ok' => true, 'stats' => face_index_stats($albumId),
              'pending' => array_map(fn($im) => ['id' => (int) $im['id'], 'src' => media_url($im, 'thumb')], $st->fetchAll())]);
}

csrf_check();
session_write_close();
$action = (string) ($_POST['action'] ?? '');

if ($action === 'save') {
    $img = find_image($albumId, (int) ($_POST['image'] ?? 0));
    if (!$img) json_out(['ok' => false, 'error' => 'ไม่พบภาพ'], 404);
    $descs = json_decode((string) ($_POST['descs'] ?? '[]'), true);
    if (!is_array($descs) || count($descs) > 40) json_out(['ok' => false, 'error' => 'ข้อมูลไม่ถูกต้อง'], 422);
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM faces WHERE image_id = ?')->execute([(int) $img['id']]);
    $ins = $pdo->prepare('INSERT INTO faces(album_id, image_id, descriptor) VALUES(?,?,?)');
    $n = 0;
    foreach ($descs as $b64) {
        if (is_string($b64) && ($bin = face_descriptor_from_b64($b64)) !== null) {
            $ins->execute([$albumId, (int) $img['id'], $bin]);
            $n++;
        }
    }
    $pdo->prepare('UPDATE images SET face_indexed = 1 WHERE id = ?')->execute([(int) $img['id']]);
    $pdo->commit();
    json_out(['ok' => true, 'faces' => $n]);
}

if ($action === 'clear') {
    db()->prepare('DELETE FROM faces WHERE album_id = ?')->execute([$albumId]);
    db()->prepare('UPDATE images SET face_indexed = 0 WHERE album_id = ?')->execute([$albumId]);
    json_out(['ok' => true]);
}

json_out(['ok' => false], 400);
