<?php
/**
 * Web Push subscriptions for the signed-in shop.
 *   GET  push.php                 → { key }  (VAPID public key)
 *   POST push.php action=subscribe   endpoint, p256dh, auth
 *   POST push.php action=unsubscribe endpoint
 *   POST push.php action=test
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
header('Cache-Control: no-store, private');
$me  = require_login(true);
$uid = (int) $me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    $st = db()->prepare('SELECT COUNT(*) FROM push_subs WHERE user_id = ?');
    $st->execute([$uid]);
    json_out(['ok' => true, 'key' => vapid_keys()['public'], 'devices' => (int) $st->fetchColumn()]);
}

csrf_check();
session_write_close();
$action   = (string) ($_POST['action'] ?? '');
$endpoint = trim((string) ($_POST['endpoint'] ?? ''));

if ($action === 'subscribe') {
    $p256 = (string) ($_POST['p256dh'] ?? '');
    $auth = (string) ($_POST['auth'] ?? '');
    if (!preg_match('~^https://~', $endpoint) || strlen($endpoint) > 700 || strlen(b64u_dec($p256)) !== 65 || strlen(b64u_dec($auth)) !== 16) {
        json_out(['ok' => false, 'error' => 'ข้อมูลการสมัครไม่ถูกต้อง'], 422);
    }
    db()->prepare('INSERT INTO push_subs(user_id, endpoint, ep_hash, p256dh, auth, agent) VALUES(?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), agent = VALUES(agent)')
        ->execute([$uid, $endpoint, sha1($endpoint), $p256, $auth, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190)]);
    json_out(['ok' => true]);
}

if ($action === 'unsubscribe') {
    db()->prepare('DELETE FROM push_subs WHERE ep_hash = ? AND user_id = ?')->execute([sha1($endpoint), $uid]);
    json_out(['ok' => true]);
}

if ($action === 'test') {
    $r = webpush_user($uid, ['title' => '✦ ทดสอบ Web Push', 'body' => 'แจ้งเตือนจะเด้งแบบนี้ แม้ปิดหน้าเว็บไปแล้ว', 'url' => abs_url('admin/notifications.php'), 'tag' => 'sp-test']);
    json_out(['ok' => $r !== null && str_contains($r, '✓'), 'result' => $r ?? 'ยังไม่มีเครื่องที่เปิด Web Push']);
}

json_out(['ok' => false], 400);
