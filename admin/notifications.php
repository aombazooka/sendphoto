<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/admin_layout.php';
$me  = require_login();
$uid = (int) $me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'clear') {
        db()->prepare('DELETE FROM notifications WHERE user_id = ? AND is_read = 1')->execute([$uid]);
        flash('ล้างการแจ้งเตือนที่อ่านแล้ว');
    }
    redirect(url('admin/notifications.php'));
}

$items = user_notifications($uid, 150);
$hasChannel = (user_setting($uid, 'notify_line_token') && user_setting($uid, 'notify_line_to'))
           || (user_setting($uid, 'notify_tg_token') && user_setting($uid, 'notify_tg_chat'));

// Everything shown on this page counts as read (the list above keeps its "new" highlight).
db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$uid]);
admin_header('แจ้งเตือน', 'notifications');

$groups = [];
foreach ($items as $n) {
    $d = date('Y-m-d', strtotime($n['created_at']));
    $label = $d === date('Y-m-d') ? 'วันนี้' : ($d === date('Y-m-d', strtotime('-1 day')) ? 'เมื่อวาน' : thai_date($d));
    $groups[$label][] = $n;
}
?>
<section class="a-head">
  <div>
    <p class="crumb"><?= e(site_name()) ?></p>
    <h1 class="display">แจ้งเตือน</h1>
    <p class="muted">ความเคลื่อนไหวจากลูกค้าในทุกแกลเลอรี</p>
  </div>
  <div class="a-head__actions">
    <a class="btn btn--ghost" href="<?= e(url('admin/settings.php#notify')) ?>">ตั้งค่าการแจ้งเตือน</a>
    <?php if ($items): ?>
    <form method="post" data-confirm="ล้างการแจ้งเตือนที่อ่านแล้วทั้งหมด?">
      <?= csrf_field() ?><input type="hidden" name="action" value="clear">
      <button class="btn btn--ghost" type="submit">ล้างที่อ่านแล้ว</button>
    </form>
    <?php endif; ?>
  </div>
</section>

<?php web_notify_controls(); ?>

<?php if (!$hasChannel): ?>
  <div class="notice notice--warn">ยังไม่ได้เชื่อม LINE หรือ Telegram — ตอนนี้แจ้งเตือนจะเห็นเฉพาะในหน้านี้ <a href="<?= e(url('admin/settings.php#notify')) ?>">เชื่อมต่อเพื่อรับแจ้งเตือนบนมือถือ →</a></div>
<?php endif; ?>

<?php if (!$items): ?>
  <div class="empty">
    <div class="empty__mark">✦</div>
    <h2 class="display-sm">ยังไม่มีการแจ้งเตือน</h2>
    <p class="muted">เมื่อลูกค้าเปิดลิงก์ ส่งรายการคัดรูป หรือบันทึกภาพ จะแจ้งที่นี่</p>
  </div>
<?php else: ?>
  <div class="notifs">
    <?php foreach ($groups as $label => $list): ?>
      <h2 class="notifs__day"><?= e($label) ?></h2>
      <ul class="notifs__list">
        <?php foreach ($list as $n): $ev = NOTIFY_EVENTS[$n['type']] ?? ['•', '', '']; ?>
          <li class="notif notif--<?= e($n['type']) ?> <?= (int) $n['is_read'] ? '' : 'is-new' ?>">
            <span class="notif__ic"><?= e($ev[0]) ?></span>
            <div class="notif__main">
              <?php if ($n['link']): ?><a class="notif__title" href="<?= e($n['link']) ?>"><?= e($n['title']) ?></a><?php else: ?><span class="notif__title"><?= e($n['title']) ?></span><?php endif; ?>
              <?php if (trim((string) $n['body']) !== ''): ?><p class="notif__body"><?= nl2br(e($n['body'])) ?></p><?php endif; ?>
              <p class="notif__meta">
                <?= e(date('H:i', strtotime($n['created_at']))) ?> น.
                <?php if ($n['delivery'] !== ''): ?> · ส่งไปที่ <?= e($n['delivery']) ?><?php endif; ?>
              </p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php admin_footer();
