<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/admin_layout.php';
require_superadmin();

$filters = [
    'all'       => ['ทั้งหมด', '1=1'],
    'trial'     => ['ทดลองใช้', "u.role = 'member' AND u.status = 'active' AND u.on_trial = 1 AND (u.expires_at IS NULL OR u.expires_at > NOW())"],
    'active'    => ['ใช้งานอยู่', "u.role = 'member' AND u.status = 'active' AND u.on_trial = 0 AND (u.expires_at IS NULL OR u.expires_at > NOW())"],
    'expiring'  => ['ใกล้หมดอายุ (7 วัน)', "u.role = 'member' AND u.status = 'active' AND u.expires_at > NOW() AND u.expires_at <= NOW() + INTERVAL 7 DAY"],
    'expired'   => ['หมดอายุ', "u.role = 'member' AND u.status = 'active' AND u.expires_at <= NOW()"],
    'suspended' => ['ระงับ', "u.status = 'suspended'"],
];
$f = (string) ($_GET['f'] ?? 'all');
if (!isset($filters[$f])) $f = 'all';
$q = trim((string) ($_GET['q'] ?? ''));

$counts = [];
foreach ($filters as $k => [, $where]) {
    $counts[$k] = (int) db()->query("SELECT COUNT(*) FROM users u WHERE $where")->fetchColumn();
}

$sql = 'SELECT u.*, p.name AS plan_name,
               (SELECT COUNT(*) FROM albums a WHERE a.user_id = u.id) AS album_count,
               (SELECT COUNT(*) FROM payments y WHERE y.user_id = u.id AND y.status = \'pending\') AS pending
        FROM users u LEFT JOIN plans p ON p.id = u.plan_id
        WHERE ' . $filters[$f][1];
$params = [];
if ($q !== '') {
    $sql .= ' AND (u.shop_name LIKE ? OR u.email LIKE ?)';
    $params = ['%' . $q . '%', '%' . $q . '%'];
}
$sql .= ' ORDER BY u.created_at DESC, u.id DESC LIMIT 500';
$st = db()->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();

$revenue = db()->query("SELECT COALESCE(SUM(amount),0) AS total,
                               COALESCE(SUM(CASE WHEN reviewed_at >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN amount ELSE 0 END),0) AS month
                        FROM payments WHERE status = 'approved'")->fetch();

admin_header('สมาชิก', 'members');
?>
<section class="a-head">
  <div>
    <p class="crumb"><?= e(platform_name()) ?> <span>/</span> Members</p>
    <h1 class="display">สมาชิกทั้งหมด</h1>
    <p class="muted">จัดการร้านค้า ต่ออายุ ระงับ หรือเพิ่มสมาชิกเอง</p>
  </div>
  <a class="btn btn--primary" href="<?= e(url('admin/member.php')) ?>">＋ เพิ่มสมาชิก</a>
</section>

<section class="stats">
  <div class="stat"><span class="stat__num"><?= number_format($counts['active']) ?></span><span class="stat__lbl">สมาชิกที่จ่ายเงิน</span></div>
  <div class="stat"><span class="stat__num"><?= number_format($counts['trial']) ?></span><span class="stat__lbl">กำลังทดลองใช้</span></div>
  <div class="stat"><span class="stat__num">฿<?= e(money($revenue['month'])) ?></span><span class="stat__lbl">รายได้เดือนนี้ (อนุมัติแล้ว)</span></div>
  <div class="stat"><span class="stat__num">฿<?= e(money($revenue['total'])) ?></span><span class="stat__lbl">รายได้รวม</span></div>
</section>

<nav class="tabs">
  <?php foreach ($filters as $k => [$label]): ?>
    <a href="<?= e(url('admin/members.php?' . http_build_query(['f' => $k, 'q' => $q]))) ?>" class="<?= $f === $k ? 'is-active' : '' ?>"><?= e($label) ?> <span><?= $counts[$k] ?></span></a>
  <?php endforeach; ?>
</nav>

<form class="search" method="get">
  <input type="hidden" name="f" value="<?= e($f) ?>">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาชื่อร้าน หรืออีเมล…">
</form>

<div class="panel panel--flush">
  <?php if (!$users): ?>
    <div class="empty empty--sm"><p class="muted">ไม่พบสมาชิก</p></div>
  <?php else: ?>
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>ร้าน / อีเมล</th><th>สถานะ</th><th>แพ็กเกจ</th><th>ใช้งานได้ถึง</th><th class="num">แกลเลอรี</th><th>สมัครเมื่อ</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): $s = account_state($u); $d = account_days_left($u); ?>
        <tr>
          <td><a class="tbl__main" href="<?= e(url('admin/member.php?id=' . $u['id'])) ?>"><?= e($u['shop_name'] ?: '—') ?></a><span class="tbl__sub"><?= e($u['email']) ?></span></td>
          <td><span class="pill pill--<?= e($s) ?>"><?= e(account_state_label($s)) ?></span><?php if ((int) $u['pending']): ?> <a class="pill pill--pending" href="<?= e(url('admin/payments.php')) ?>">สลิปรอตรวจ</a><?php endif; ?></td>
          <td><?= $u['role'] === 'admin' ? '—' : ((int) $u['on_trial'] ? 'ทดลองใช้' : e($u['plan_name'] ?? '—')) ?></td>
          <td><?= $u['role'] === 'admin' ? '—' : ($u['expires_at'] ? e(thai_date($u['expires_at'], true)) . ($s !== 'expired' && $d !== null ? ' <span class="tbl__sub">เหลือ ' . $d . ' วัน</span>' : '') : 'ไม่มีกำหนด') ?></td>
          <td class="num"><?= (int) $u['album_count'] ?></td>
          <td><?= e(thai_date($u['created_at'], true)) ?></td>
          <td class="tbl__act"><a class="btn btn--sm btn--ghost" href="<?= e(url('admin/member.php?id=' . $u['id'])) ?>">จัดการ</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php admin_footer();
