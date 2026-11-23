<?php
/**
 * Client proofing API (no login) – the client sends the list of photos they picked.
 *   POST proof.php  action=get     t, key
 *   POST proof.php  action=submit  t, key, name, contact, note, ids=1,2,3
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false], 405);

$album = find_album_by_token((string) ($_POST['t'] ?? ''));
if (!$album || !album_is_public($album)) json_out(['ok' => false, 'error' => 'ลิงก์นี้หมดอายุหรือถูกปิดแล้ว'], 404);
if (!(int) $album['proof_enabled']) json_out(['ok' => false, 'error' => 'แกลเลอรีนี้ไม่ได้เปิดระบบคัดรูป'], 403);

$key = (string) ($_POST['key'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $key)) json_out(['ok' => false, 'error' => 'ข้อมูลไม่ถูกต้อง'], 400);

$albumId = (int) $album['id'];
$proof   = find_proof_by_key($albumId, $key);
$action  = (string) ($_POST['action'] ?? 'get');

$public = fn(?array $p) => $p ? [
    'name'      => $p['name'],
    'contact'   => $p['contact'],
    'note'      => (string) $p['note'],
    'ids'       => proof_ids($p),
    'status'    => $p['status'],
    'submitted' => thai_date($p['submitted_at'], true, true),
] : null;

if ($action === 'get') {
    json_out(['ok' => true, 'proof' => $public($proof)]);
}

if ($action !== 'submit') json_out(['ok' => false], 400);

if ($proof && $proof['status'] === 'locked') {
    json_out(['ok' => false, 'error' => 'ช่างภาพล็อกรายการนี้แล้ว หากต้องการแก้ไขกรุณาติดต่อช่างภาพ'], 409);
}
if (!proof_open($album)) {
    json_out(['ok' => false, 'error' => 'หมดเวลาส่งรายการคัดรูปแล้ว กรุณาติดต่อช่างภาพ'], 409);
}

$name    = trim(mb_substr((string) ($_POST['name'] ?? ''), 0, 120));
$contact = trim(mb_substr((string) ($_POST['contact'] ?? ''), 0, 160));
$note    = trim(mb_substr((string) ($_POST['note'] ?? ''), 0, 2000));
if ($name === '') json_out(['ok' => false, 'error' => 'กรุณาใส่ชื่อของคุณ'], 422);

// Only visible photos of this album count.
$want = array_flip(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
$ids = [];
foreach (album_images($albumId) as $im) {
    if (isset($want[(int) $im['id']])) $ids[] = (int) $im['id'];
}
if (!$ids) json_out(['ok' => false, 'error' => 'ยังไม่ได้เลือกภาพ'], 422);

$q = proof_quote($album, count($ids));
if ($q['over']) {
    json_out(['ok' => false, 'error' => 'เลือกได้สูงสุด ' . (int) $album['proof_limit'] . ' ภาพ กรุณาลดจำนวนภาพที่เลือก'], 422);
}

// Light spam guard: at most 60 different senders per gallery.
if (!$proof) {
    $c = db()->prepare('SELECT COUNT(*) FROM proofs WHERE album_id = ?');
    $c->execute([$albumId]);
    if ((int) $c->fetchColumn() >= 60) json_out(['ok' => false, 'error' => 'ส่งรายการไม่ได้ กรุณาติดต่อช่างภาพ'], 429);
}

db()->prepare('INSERT INTO proofs(album_id, client_key, name, contact, note, image_ids, pick_count, extra_count, extra_amount, status, seen, submitted_at)
               VALUES(?,?,?,?,?,?,?,?,?,\'submitted\',0,NOW())
               ON DUPLICATE KEY UPDATE name = VALUES(name), contact = VALUES(contact), note = VALUES(note),
                 image_ids = VALUES(image_ids), pick_count = VALUES(pick_count), extra_count = VALUES(extra_count),
                 extra_amount = VALUES(extra_amount), seen = 0, submitted_at = NOW()')
    ->execute([$albumId, $key, $name, $contact, $note, implode(',', $ids), $q['count'], $q['extra'], $q['amount']]);

$saved = find_proof_by_key($albumId, $key);

// Tell the photographer (resubmits within 10 minutes count as one notification).
$lines = ['แกลเลอรี: ' . $album['title'], 'เลือก ' . $q['count'] . ' ภาพ' . ($q['extra'] ? ' · เกินโควตา ' . $q['extra'] . ' ภาพ (฿' . money($q['amount']) . ')' : '')];
if ($contact !== '') $lines[] = 'ติดต่อ: ' . $contact;
if ($note !== '')    $lines[] = 'ฝากถึงช่าง: ' . mb_substr($note, 0, 300);
notify((int) $album['user_id'], 'proof',
    $name . ($proof ? ' แก้ไขรายการคัดรูป' : ' ส่งรายการคัดรูปแล้ว'),
    implode("\n", $lines), $albumId, abs_url('admin/album.php?id=' . $albumId . '#proofs'),
    'proof:' . $saved['id'] . ':' . intdiv(time(), 600));

json_out(['ok' => true, 'proof' => $public($saved), 'quote' => $q]);
