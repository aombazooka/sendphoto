<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$me = require_login($isAjax);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(url('admin/'));
csrf_check();

$action = (string) ($_POST['action'] ?? '');
$id     = (int) ($_POST['id'] ?? 0);

/* ---------- ตรวจลิงก์ (before saving) ---------- */
if ($action === 'check') {
    require_active_account($me, true);
    session_write_close();
    $text = (string) ($_POST['sources'] ?? '');
    $invalid = [];
    foreach (preg_split('~\R~', $text) as $line) {
        $src = parse_source($line);
        if ($src && $src['type'] === 'invalid') $invalid[] = 'ลิงก์ไม่ถูกต้อง: ' . $src['url'];
    }
    ['items' => $items, 'errors' => $errors] = collect_sources($text, google_key_for($me['id']));
    $items = array_values($items);
    $bytes = array_sum(array_column($items, 'size_bytes'));

    // Small previews for the admin only (the photographer owns these links).
    $previews = [];
    foreach (array_slice($items, 0, 8) as $it) {
        $previews[] = $it['provider'] === 'drive'
            ? 'https://lh3.googleusercontent.com/d/' . rawurlencode($it['file_id']) . '=w400'
            : upstream_url($it + ['id' => 0], 'thumb');
    }
    json_out([
        'ok'       => count($items) > 0,
        'count'    => count($items),
        'bytes'    => $bytes,
        'size'     => human_bytes($bytes),
        'previews' => $previews,
        'errors'   => array_values(array_unique(array_merge($invalid, $errors))),
    ]);
}

$album = find_my_album($id, $me);
if (!$album) {
    if ($isAjax) json_out(['ok' => false, 'error' => 'ไม่พบอัลบั้ม'], 404);
    flash('ไม่พบอัลบั้ม', 'err');
    redirect(url('admin/'));
}
$back = url('admin/album.php?id=' . $id);

switch ($action) {
    case 'sync':
        require_active_account($me);
        $r = sync_album($id);
        $msg = 'ดึงภาพเรียบร้อย · พบ ' . $r['count'] . ' ภาพ';
        if ($r['added'])   $msg .= ' · ใหม่ ' . $r['added'];
        if ($r['removed']) $msg .= ' · นำออก ' . $r['removed'];
        if ($r['errors'])  $msg .= "\n" . implode("\n", $r['errors']);
        flash($msg, $r['errors'] ? 'warn' : 'ok');
        redirect($back);

    case 'delete':
        delete_upload($album['cover_file']);
        delete_upload($album['logo_file']);
        db()->prepare('DELETE FROM albums WHERE id = ?')->execute([$id]);
        flash('ลบอัลบั้ม “' . $album['title'] . '” แล้ว');
        redirect(url('admin/'));

    case 'toggle_active':
        $new = (int) $album['is_active'] ? 0 : 1;
        db()->prepare('UPDATE albums SET is_active = ? WHERE id = ?')->execute([$new, $id]);
        if ($isAjax) json_out(['ok' => true, 'active' => $new]);
        flash($new ? 'เปิดลิงก์ให้ลูกค้าเข้าดูแล้ว' : 'ปิดลิงก์แล้ว ลูกค้าจะเปิดอัลบั้มนี้ไม่ได้ทันที');
        redirect((string) ($_POST['back'] ?? '') === 'list' ? url('admin/') : $back);

    case 'new_token':
        require_active_account($me);
        db()->prepare('UPDATE albums SET token = ? WHERE id = ?')->execute([new_token(), $id]);
        flash('สร้างลิงก์ใหม่แล้ว ลิงก์เดิมจะใช้ไม่ได้อีก');
        redirect($back);

    case 'toggle_hidden':
        $imgId = (int) ($_POST['image'] ?? 0);
        db()->prepare('UPDATE images SET hidden = 1 - hidden WHERE id = ? AND album_id = ?')->execute([$imgId, $id]);
        $c = count_images($id);
        db()->prepare('UPDATE albums SET total_bytes = ? WHERE id = ?')->execute([$c['bytes'], $id]);
        $h = db()->prepare('SELECT hidden FROM images WHERE id = ? AND album_id = ?');
        $h->execute([$imgId, $id]);
        json_out(['ok' => true, 'hidden' => (int) $h->fetchColumn(), 'visible' => $c['visible'], 'total' => $c['total']]);

    case 'warm':
        // Pre-load thumbnails into the server cache, a chunk per request (called repeatedly by admin.js).
        session_write_close();
        require_once __DIR__ . '/../inc/proxy.php';
        @set_time_limit(180);
        $imgs = album_images($id);
        backfill_ratios($imgs);
        if ($cover = album_cover_image($album)) warm_cache([$cover], 'preview');
        $pending = array_values(array_filter($imgs, fn($im) => !is_file(cache_path($im, 'thumb'))));
        $r = warm_cache(array_slice($pending, 0, 60), 'thumb', 12);
        $left = max(0, count($pending) - $r['ok']);
        json_out(['ok' => true, 'total' => count($imgs), 'cached' => count($imgs) - $left,
                  'left' => $left, 'done' => $r['ok'], 'failed' => $r['failed']]);

    case 'proof_lock':
    case 'proof_delete':
        $pid = (int) ($_POST['proof'] ?? 0);
        $st = db()->prepare('SELECT * FROM proofs WHERE id = ? AND album_id = ?');
        $st->execute([$pid, $id]);
        $proof = $st->fetch();
        if (!$proof) { flash('ไม่พบรายการคัดรูป', 'err'); redirect($back . '#proofs'); }
        if ($action === 'proof_delete') {
            db()->prepare('DELETE FROM proofs WHERE id = ?')->execute([$pid]);
            flash('ลบรายการของ “' . $proof['name'] . '” แล้ว');
        } else {
            $new = $proof['status'] === 'locked' ? 'submitted' : 'locked';
            db()->prepare('UPDATE proofs SET status = ? WHERE id = ?')->execute([$new, $pid]);
            flash($new === 'locked' ? 'ล็อกรายการแล้ว ลูกค้าจะเห็นว่ากำลังรีทัช และแก้ไขไม่ได้' : 'ปลดล็อกแล้ว ลูกค้าแก้ไขและส่งใหม่ได้');
        }
        redirect($back . '#proofs');

    case 'order_approve':
    case 'order_reject':
    case 'order_revoke':
        $oid = (int) ($_POST['order'] ?? 0);
        $st = db()->prepare('SELECT * FROM orders WHERE id = ? AND album_id = ?');
        $st->execute([$oid, $id]);
        $order = $st->fetch();
        if (!$order) { flash('ไม่พบคำสั่งซื้อ', 'err'); redirect($back . '#orders'); }
        if ($action === 'order_approve') {
            db()->prepare("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$oid]);
            flash('อนุมัติแล้ว — ' . $order['name'] . ' โหลดไฟล์ต้นฉบับได้ทันที');
        } elseif ($action === 'order_reject') {
            $reason = trim(mb_substr((string) ($_POST['reason'] ?? ''), 0, 200)) ?: 'สลิปไม่ถูกต้อง';
            db()->prepare("UPDATE orders SET status = 'rejected', reject_reason = ? WHERE id = ?")->execute([$reason, $oid]);
            flash('ไม่อนุมัติคำสั่งซื้อของ ' . $order['name'] . ' — ลูกค้าจะเห็นเหตุผลและแนบสลิปใหม่ได้', 'warn');
        } else {
            db()->prepare("UPDATE orders SET status = 'review', paid_at = NULL WHERE id = ?")->execute([$oid]);
            flash('ยกเลิกการอนุมัติแล้ว', 'warn');
        }
        redirect($back . '#orders');

    case 'set_cover':
        $imgId = (int) ($_POST['image'] ?? 0);
        if (!find_image($id, $imgId)) json_out(['ok' => false], 404);
        db()->prepare('UPDATE albums SET cover_image_id = ? WHERE id = ?')->execute([$imgId, $id]);
        json_out(['ok' => true, 'uploaded_cover' => !empty($album['cover_file'])]);
}

redirect($back);
