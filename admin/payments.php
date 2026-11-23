<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/admin_layout.php';
$admin = require_superadmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pid = (int) ($_POST['id'] ?? 0);
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'approve') {
        $err = approve_payment($pid, (int) $admin['id']);
        if ($err) flash($err, 'err');
        else {
            $p = find_payment($pid);
            $u = find_user((int) $p['user_id']);
            flash('อนุมัติแล้ว · “' . $u['shop_name'] . '” ใช้งานได้ถึง ' . thai_date($u['expires_at'], false, true) . ' น.');
        }
    } elseif ($act === 'reject') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $err = reject_payment($pid, (int) $admin['id'], $reason !== '' ? $reason : 'สลิปไม่ถูกต้อง');
        flash($err ?: 'บันทึกว่าไม่อนุมัติแล้ว · สมาชิกจะเห็นเหตุผลในหน้าแพ็กเกจ', $err ? 'err' : 'ok');
    }
    redirect(url('admin/payments.php?s=' . rawurlencode((string) ($_POST['s'] ?? 'pending'))));
}

$tabs = ['pending' => 'รอตรวจสอบ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ'];
$s = (string) ($_GET['s'] ?? 'pending');
if (!isset($tabs[$s])) $s = 'pending';
$counts = [];
foreach ($tabs as $k => $_) {
    $st = db()->prepare('SELECT COUNT(*) FROM payments WHERE status = ?');
    $st->execute([$k]);
    $counts[$k] = (int) $st->fetchColumn();
}
$st = db()->prepare('SELECT y.*, u.shop_name, u.email, u.expires_at AS user_expires
                     FROM payments y JOIN users u ON u.id = y.user_id
                     WHERE y.status = ? ORDER BY ' . ($s === 'pending' ? 'y.created_at ASC' : 'y.reviewed_at DESC') . ' LIMIT 200');
$st->execute([$s]);
$rows = $st->fetchAll();

admin_header('ชำระเงิน', 'payments');
?>
<section class="a-head">
  <div>
    <p class="crumb"><?= e(platform_name()) ?> <span>/</span> Payments</p>
    <h1 class="display">ตรวจสอบสลิป</h1>
    <p class="muted">เทียบยอดและเวลาโอนกับบัญชีธนาคารก่อนอนุมัติ · อนุมัติแล้วระบบต่ออายุให้อัตโนมัติ</p>
  </div>
</section>

<nav class="tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="<?= e(url('admin/payments.php?s=' . $k)) ?>" class="<?= $s === $k ? 'is-active' : '' ?>"><?= e($label) ?> <span><?= $counts[$k] ?></span></a>
  <?php endforeach; ?>
</nav>

<?php if (!$rows): ?>
  <div class="empty empty--sm"><p class="muted"><?= $s === 'pending' ? 'ไม่มีสลิปรอตรวจสอบ' : 'ยังไม่มีรายการ' ?></p></div>
<?php else: ?>
<section class="slips">
  <?php foreach ($rows as $r): $isPdf = str_ends_with((string) $r['slip_file'], '.pdf'); $slipUrl = url('admin/slip.php?id=' . $r['id']); ?>
    <article class="slip">
      <a class="slip__img" href="<?= e($slipUrl) ?>" target="_blank" rel="noopener">
        <?php if ($isPdf): ?><span class="slip__pdf">PDF<br><small>เปิดดูสลิป</small></span><?php else: ?><img src="<?= e($slipUrl) ?>" alt="สลิป" loading="lazy"><?php endif; ?>
      </a>
      <div class="slip__body">
        <p class="slip__shop"><a href="<?= e(url('admin/member.php?id=' . $r['user_id'])) ?>"><?= e($r['shop_name']) ?></a> <span class="tbl__sub"><?= e($r['email']) ?></span></p>
        <p class="slip__amt">฿<?= e(money($r['amount'])) ?> <small><?= e($r['plan_name']) ?> · <?= (int) $r['days'] ?> วัน</small></p>
        <dl class="slip__dl">
          <div><dt>เวลาโอน</dt><dd><?= e(thai_date($r['transfer_at'], true, true)) ?></dd></div>
          <div><dt>ส่งเมื่อ</dt><dd><?= e(thai_date($r['created_at'], true, true)) ?></dd></div>
          <?php if ($r['note'] !== ''): ?><div><dt>หมายเหตุ</dt><dd><?= e($r['note']) ?></dd></div><?php endif; ?>
          <?php if ($s === 'pending'): ?><div><dt>หมดอายุเดิม</dt><dd><?= $r['user_expires'] ? e(thai_date($r['user_expires'], true)) : '—' ?></dd></div><?php endif; ?>
          <?php if ($s !== 'pending'): ?><div><dt>ตรวจเมื่อ</dt><dd><?= e(thai_date($r['reviewed_at'], true, true)) ?></dd></div><?php endif; ?>
          <?php if ($s === 'rejected' && $r['admin_note'] !== ''): ?><div><dt>เหตุผล</dt><dd><?= e($r['admin_note']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($s === 'pending'): ?>
        <div class="slip__act">
          <form method="post" data-confirm="อนุมัติ ฿<?= e(money($r['amount'])) ?> ของ “<?= e($r['shop_name']) ?>” และต่ออายุ <?= (int) $r['days'] ?> วัน?">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="act" value="approve">
            <button class="btn btn--primary btn--sm" type="submit">✓ อนุมัติ</button>
          </form>
          <form method="post" class="slip__rej">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="act" value="reject">
            <input type="text" name="reason" placeholder="เหตุผล เช่น ยอดไม่ตรง" maxlength="500">
            <button class="btn btn--danger-ghost btn--sm" type="submit">ไม่อนุมัติ</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php admin_footer();
