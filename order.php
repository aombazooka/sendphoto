<?php
/**
 * Buying photos (no login). The client is identified by their browser key.
 *   POST order.php action=list    t, key
 *   POST order.php action=create  t, key, name, contact, ids=1,2,3 | all=1
 *   POST order.php action=slip    t, key, order, slip=<file>
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);

$album = find_album_by_token((string) ($_POST['t'] ?? ''));
if (!$album || !album_is_public($album)) json_out(['ok' => false, 'error' => 'ลิงก์นี้หมดอายุหรือถูกปิดแล้ว'], 404);
if (!sell_on($album)) json_out(['ok' => false, 'error' => 'แกลเลอรีนี้ไม่ได้เปิดขายภาพ'], 403);

$key = (string) ($_POST['key'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $key)) json_out(['ok' => false, 'error' => 'ข้อมูลไม่ถูกต้อง'], 400);

$albumId = (int) $album['id'];
$ownerId = (int) $album['user_id'];
$action  = (string) ($_POST['action'] ?? 'list');

$pub = fn(array $o) => [
    'id'     => (int) $o['id'],
    'status' => $o['status'],
    'label'  => order_status_label($o['status']),
    'count'  => (int) $o['photo_count'],
    'is_all' => (bool) $o['is_all'],
    'amount' => (float) $o['amount'],
    'when'   => thai_date($o['created_at'], true, true),
    'reason' => $o['reject_reason'],
    // QR with the amount filled in, so an unpaid order can be paid later
    'pay'    => in_array($o['status'], ['pending', 'rejected'], true) ? shop_payment($ownerId, (float) $o['amount']) : null,
];
$state = function () use ($albumId, $key, $pub) {
    $owned = client_owned($albumId, $key);
    return [
        'orders' => array_map($pub, client_orders($albumId, $key)),
        'owned'  => ['all' => $owned['all'], 'ids' => array_keys($owned['ids'])],
    ];
};

if ($action === 'list') {
    json_out(['ok' => true] + $state());
}

if ($action === 'create') {
    $name    = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 120));
    $contact = trim(mb_substr((string) ($_POST['contact'] ?? ''), 0, 160));
    if ($name === '') json_out(['ok' => false, 'error' => 'กรุณาใส่ชื่อของคุณ'], 422);

    $images = album_images($albumId);
    $owned  = client_owned($albumId, $key);
    if ($owned['all']) json_out(['ok' => false, 'error' => 'คุณซื้อภาพทั้งหมดในแกลเลอรีนี้แล้ว'], 409);

    $wantAll = !empty($_POST['all']);
    $want = array_flip(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
    $ids = [];
    foreach ($images as $im) {
        $id = (int) $im['id'];
        if (($wantAll || isset($want[$id])) && !isset($owned['ids'][$id])) $ids[] = $id;
    }
    if (!$ids) json_out(['ok' => false, 'error' => 'ยังไม่ได้เลือกภาพที่ต้องการซื้อ'], 422);

    $q = sell_quote($album, count($ids), count($images));
    if ($wantAll && (float) $album['sell_price_all'] > 0) $q = ['count' => count($images), 'is_all' => true, 'amount' => (float) $album['sell_price_all']];
    if ($q['amount'] <= 0) json_out(['ok' => false, 'error' => 'ช่างภาพยังไม่ได้ตั้งราคา'], 409);

    $c = db()->prepare("SELECT COUNT(*) FROM orders WHERE album_id = ? AND client_key = ? AND status = 'pending'");
    $c->execute([$albumId, $key]);
    if ((int) $c->fetchColumn() >= 20) json_out(['ok' => false, 'error' => 'มีคำสั่งซื้อที่ยังไม่ชำระมากเกินไป'], 429);

    db()->prepare('INSERT INTO orders(album_id, client_key, name, contact, image_ids, is_all, photo_count, amount) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$albumId, $key, $name, $contact, implode(',', $q['is_all'] ? [] : $ids), $q['is_all'] ? 1 : 0, $q['count'], $q['amount']]);
    $orderId = (int) db()->lastInsertId();

    json_out(['ok' => true, 'order' => $orderId, 'amount' => $q['amount'], 'count' => $q['count'], 'is_all' => $q['is_all'],
              'pay' => shop_payment($ownerId, (float) $q['amount'])] + $state());
}

if ($action === 'slip') {
    $st = db()->prepare('SELECT * FROM orders WHERE id = ? AND album_id = ? AND client_key = ?');
    $st->execute([(int) ($_POST['order'] ?? 0), $albumId, $key]);
    $order = $st->fetch();
    if (!$order) json_out(['ok' => false, 'error' => 'ไม่พบคำสั่งซื้อ'], 404);
    if (!in_array($order['status'], ['pending', 'rejected'], true)) json_out(['ok' => false, 'error' => 'คำสั่งซื้อนี้ส่งสลิปแล้ว'], 409);
    try {
        $file = save_slip('slip');
    } catch (RuntimeException $ex) {
        json_out(['ok' => false, 'error' => $ex->getMessage()], 422);
    }
    if ($order['slip_file']) delete_slip($order['slip_file']);
    db()->prepare("UPDATE orders SET status = 'review', slip_file = ?, slip_at = NOW(), reject_reason = '', seen = 0 WHERE id = ?")
        ->execute([$file, (int) $order['id']]);

    notify($ownerId, 'order', $order['name'] . ' ส่งสลิปซื้อภาพ ฿' . money($order['amount']),
        'แกลเลอรี: ' . $album['title'] . "\n" . ((int) $order['is_all'] ? 'ซื้อภาพทั้งหมด' : 'ซื้อ ' . (int) $order['photo_count'] . ' ภาพ')
        . ($order['contact'] !== '' ? "\nติดต่อ: " . $order['contact'] : ''),
        $albumId, abs_url('admin/album.php?id=' . $albumId . '#orders'), 'order:' . $order['id'] . ':' . time());

    json_out(['ok' => true] + $state());
}

json_out(['ok' => false], 400);
