<?php
/** CSV of the photos a client picked (opens correctly in Excel, Thai included). */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
$me = require_login();

$st = db()->prepare('SELECT * FROM proofs WHERE id = ?');
$st->execute([(int) ($_GET['id'] ?? 0)]);
$proof = $st->fetch();
$album = $proof ? find_my_album((int) $proof['album_id'], $me) : null;
if (!$proof || !$album) {
    flash('ไม่พบรายการคัดรูป', 'err');
    redirect(url('admin/'));
}

$no = [];
$n = 0;
foreach (album_images((int) $album['id']) as $im) $no[(int) $im['id']] = ++$n;

$file = 'คัดรูป-' . preg_replace('~[\\\\/:*?"<>|]+~', '', $album['title'] . '-' . $proof['name']) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="proof-' . (int) $proof['id'] . '.csv"; filename*=UTF-8\'\'' . rawurlencode($file));
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads Thai correctly
fputcsv($out, ['แกลเลอรี', $album['title']]);
fputcsv($out, ['ลูกค้า', $proof['name'], $proof['contact']]);
fputcsv($out, ['ส่งเมื่อ', thai_date($proof['submitted_at'], false, true)]);
if (trim((string) $proof['note']) !== '') fputcsv($out, ['หมายเหตุ', $proof['note']]);
if ((int) $proof['extra_count']) fputcsv($out, ['เกินโควตา', $proof['extra_count'] . ' ภาพ', '฿' . money($proof['extra_amount'])]);
fputcsv($out, []);
fputcsv($out, ['ลำดับ', 'ภาพที่ (ในแกลเลอรี)', 'ชื่อไฟล์']);
foreach (proof_images($proof) as $k => $im) {
    fputcsv($out, [$k + 1, $no[(int) $im['id']] ?? '', $im['name']]);
}
fclose($out);
