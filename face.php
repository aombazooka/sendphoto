<?php
/**
 * Face search for clients (no login).
 *   POST face.php  t=TOKEN, d=<base64 of 128 float32 – made from the selfie in the client's own browser>
 * The selfie itself never reaches the server, and the probe descriptor is not stored.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);

$album = find_album_by_token((string) ($_POST['t'] ?? ''));
if (!$album || !album_is_public($album)) json_out(['ok' => false, 'error' => 'ลิงก์นี้หมดอายุหรือถูกปิดแล้ว'], 404);
if (!face_on($album)) json_out(['ok' => false, 'error' => 'แกลเลอรีนี้ไม่ได้เปิดการค้นหาด้วยใบหน้า'], 403);

$probe = face_descriptor_from_b64((string) ($_POST['d'] ?? ''));
if ($probe === null) json_out(['ok' => false, 'error' => 'ข้อมูลใบหน้าไม่ถูกต้อง'], 422);

json_out(['ok' => true, 'ids' => face_match((int) $album['id'], $probe)]);
