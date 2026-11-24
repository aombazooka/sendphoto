<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/admin_layout.php';
$me = require_login();
$uid = (int) $me['id'];
$newProofs = unseen_proof_counts($uid);
$toReview  = orders_to_review($uid);

$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT a.*, (SELECT COUNT(*) FROM images i WHERE i.album_id = a.id AND i.hidden = 0) AS img_count FROM albums a WHERE a.user_id = ?';
$params = [$uid];
if ($q !== '') {
    $sql .= ' AND (a.title LIKE ? OR a.client LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$sql .= ' ORDER BY a.created_at DESC, a.id DESC';
$st = db()->prepare($sql);
$st->execute($params);
$albums = $st->fetchAll();

$st = db()->prepare('SELECT COUNT(*) AS albums, COALESCE(SUM(views),0) AS views, COALESCE(SUM(downloads),0) AS downloads,
                      COALESCE(SUM(is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())),0) AS live FROM albums WHERE user_id = ?');
$st->execute([$uid]);
$stats = $st->fetch();
$st = db()->prepare('SELECT COUNT(*) FROM images i JOIN albums a ON a.id = i.album_id WHERE a.user_id = ? AND i.hidden = 0');
$st->execute([$uid]);
$totalImages = (int) $st->fetchColumn();
$canWrite = account_can_write($me);
$limit = album_limit($me);

admin_header('อัลบั้ม', 'albums');
?>
<section class="a-head">
  <div>
    <p class="crumb"><?= e(site_name()) ?></p>
    <h1 class="display">แกลเลอรีของลูกค้า</h1>
    <p class="muted">พื้นที่เล็ก ๆ สำหรับส่งมอบความทรงจำที่ยิ่งใหญ่</p>
  </div>
  <div class="a-head__actions">
    <?php if ($limit): ?><span class="muted small">ใช้ไป <?= (int) $stats['albums'] ?> / <?= $limit ?> แกลเลอรี</span><?php endif; ?>
    <?php if ($canWrite): ?>
      <a class="btn btn--primary" href="<?= e(url('admin/album.php')) ?>">＋ สร้างลิงก์แกลเลอรี</a>
    <?php else: ?>
      <a class="btn btn--primary" href="<?= e(url('admin/billing.php')) ?>">ต่ออายุสมาชิก</a>
    <?php endif; ?>
  </div>
</section>

<section class="stats">
  <div class="stat"><span class="stat__num"><?= number_format((int) $stats['albums']) ?></span><span class="stat__lbl">แกลเลอรีทั้งหมด</span></div>
  <div class="stat"><span class="stat__num"><?= number_format((int) $stats['live']) ?></span><span class="stat__lbl">ลิงก์ที่เปิดอยู่</span></div>
  <div class="stat"><span class="stat__num"><?= number_format($totalImages) ?></span><span class="stat__lbl">ภาพ</span></div>
  <div class="stat"><span class="stat__num"><?= number_format((int) $stats['views']) ?></span><span class="stat__lbl">การเข้าชม</span></div>
</section>

<form class="search" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="ค้นหาชื่ออัลบั้ม หรือชื่อลูกค้า…">
</form>

<?php if (!$albums): ?>
  <div class="empty">
    <div class="empty__mark">✦</div>
    <h2 class="display-sm"><?= $q !== '' ? 'ไม่พบอัลบั้มที่ค้นหา' : 'ยังไม่มีแกลเลอรี' ?></h2>
    <p class="muted">สร้างแกลเลอรีแล้ววางลิงก์โฟลเดอร์ Google Drive, อัลบั้ม Google Photos หรือลิงก์แชร์ Immich</p>
    <?php if ($q === ''): ?><a class="btn btn--primary" href="<?= e(url('admin/album.php')) ?>">สร้างแกลเลอรีแรก</a><?php endif; ?>
  </div>
<?php else: ?>
  <section class="cards">
    <?php foreach ($albums as $a):
        $cover = album_cover_url($a, false, 'thumb');
        [$stKey, $stLabel] = album_status($a);
        $link = share_url($a['token']);
    ?>
    <article class="card">
      <a class="card__img" href="<?= e(url('admin/album.php?id=' . $a['id'])) ?>">
        <?php if ($cover): ?><img src="<?= e($cover) ?>" alt="" loading="lazy"><?php else: ?><span class="card__ph">ยังไม่มีภาพ</span><?php endif; ?>
        <span class="badge badge--<?= $stKey ?>"><?= e($stLabel) ?></span>
      </a>
      <div class="card__body">
        <h3 class="card__title"><a href="<?= e(url('admin/album.php?id=' . $a['id'])) ?>"><?= e($a['title']) ?></a></h3>
        <p class="card__meta">
          <?= e($a['client'] ?: '—') ?><?php if ($a['event_date']): ?> · <?= e(thai_date($a['event_date'], true)) ?><?php endif; ?>
        </p>
        <p class="card__nums">
          <span><?= (int) $a['img_count'] ?> ภาพ<?= (int) $a['total_bytes'] ? ' · ' . human_bytes((int) $a['total_bytes']) : '' ?></span>
          <span><?= (int) $a['views'] ?> เข้าชม</span>
          <span><?= (int) $a['downloads'] ?> บันทึก</span>
        </p>
        <p class="card__exp">
          <?php if ($a['expires_at'] && $stKey === 'on'): ?>
            หมดอายุใน <span data-countdown="<?= e(date('c', strtotime($a['expires_at']))) ?>" data-short></span>
          <?php elseif ($a['expires_at']): ?>
            หมดอายุ <?= e(thai_date($a['expires_at'], true)) ?>
          <?php else: ?>
            ไม่มีวันหมดอายุ
          <?php endif; ?>
        </p>
        <?php if (!empty($newProofs[(int) $a['id']])): ?><a class="card__proof" href="<?= e(url('admin/album.php?id=' . $a['id'] . '#proofs')) ?>">♡ ลูกค้าส่งรายการคัดรูปใหม่ <?= $newProofs[(int) $a['id']] ?> รายการ</a><?php endif; ?>
        <?php if (!empty($toReview[(int) $a['id']])): ?><a class="card__proof" href="<?= e(url('admin/album.php?id=' . $a['id'] . '#orders')) ?>">฿ มีสลิปรอตรวจ <?= $toReview[(int) $a['id']] ?> รายการ</a><?php endif; ?>
        <?php if ($a['sync_note']): ?><p class="card__warn">⚠ มีลิงก์ที่อ่านไม่ได้ เปิดแก้ไขเพื่อดูรายละเอียด</p><?php endif; ?>
        <div class="card__actions">
          <button type="button" class="btn btn--sm" data-copy="<?= e($link) ?>">คัดลอกลิงก์</button>
          <a class="btn btn--sm btn--ghost" href="<?= e($link) ?>" target="_blank" rel="noopener">เปิดดู ↗</a>
          <form method="post" action="<?= e(url('admin/action.php')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="back" value="list">
            <button class="btn btn--sm btn--ghost" type="submit"><?= (int) $a['is_active'] ? 'ปิดลิงก์' : 'เปิดลิงก์' ?></button>
          </form>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </section>
<?php endif; ?>
<?php admin_footer();
