<?php
declare(strict_types=1);

function admin_header(string $title, string $active = ''): void
{
    $flash = flash();
    $u     = current_user();
    $isSa  = $u && $u['role'] === 'admin';
    $pendingAll = $isSa ? pending_payment_count() : 0;
    $nav = [
        ['albums', 'admin/', 'อัลบั้ม'],
    ];
    if ($u && !$isSa) $nav[] = ['billing', 'admin/billing.php', 'แพ็กเกจ'];
    if ($u) {
        check_expiring_links((int) $u['id']);
        $unread = unread_notification_count((int) $u['id']);
        $lastNotif = last_notification_id((int) $u['id']);
        $nav[] = ['notifications', 'admin/notifications.php', 'แจ้งเตือน'];
    }
    $nav[] = ['settings', 'admin/settings.php', 'ตั้งค่าร้าน'];
    if ($isSa) {
        $nav[] = ['members', 'admin/members.php', 'สมาชิก'];
        $nav[] = ['payments', 'admin/payments.php', 'ชำระเงิน'];
        $nav[] = ['platform', 'admin/platform.php', 'ระบบ'];
    }
    ?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · <?= e(site_name()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=IBM+Plex+Sans+Thai:wght@300;400;500;600&family=Noto+Serif+Thai:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="admin"<?php if ($u): ?> data-notif-last="<?= (int) $lastNotif ?>" data-notif-page="<?= $active === 'notifications' ? '1' : '0' ?>"<?php endif; ?>>
<header class="a-top">
  <div class="a-top__in">
    <a class="a-brand" href="<?= e(url('admin/')) ?>">
      <span class="a-brand__mark">✦</span>
      <span><?= e(site_name()) ?></span>
    </a>
    <nav class="a-nav">
      <?php foreach ($nav as [$key, $href, $label]): ?>
        <a href="<?= e(url($href)) ?>" class="<?= $active === $key ? 'is-active' : '' ?>"><?= e($label) ?><?php if ($key === 'payments' && $pendingAll): ?> <b class="a-nav__badge"><?= $pendingAll ?></b><?php endif; ?><?php if ($key === 'notifications'): ?> <b class="a-nav__badge a-nav__badge--soft" id="notif-badge"<?= empty($unread) ? ' hidden' : '' ?>><?= ($unread ?? 0) > 99 ? '99+' : (int) ($unread ?? 0) ?></b><?php endif; ?></a>
      <?php endforeach; ?>
      <a href="<?= e(url('admin/logout.php')) ?>" class="a-nav__out">ออกจากระบบ</a>
    </nav>
  </div>
</header>
<main class="a-main">
<?php if ($u && !$isSa) membership_bar($u, $active); ?>
<?php if ($flash): ?>
  <div class="notice notice--<?= e($flash['type']) ?>" data-autohide><?= nl2br(e($flash['msg'])) ?></div>
<?php endif;
}

/** Trial / expiry reminder shown to shop owners on every admin page. */
function membership_bar(array $u, string $active): void
{
    $state   = account_state($u);
    $days    = account_days_left($u);
    $pending = pending_payment_count((int) $u['id']);
    $link    = $active === 'billing' ? '' : ' <a href="' . e(url('admin/billing.php')) . '">ดูแพ็กเกจ / ต่ออายุ →</a>';

    if ($pending) {
        echo '<div class="sub-bar sub-bar--info">ส่งสลิปแล้ว กำลังรอผู้ดูแลระบบตรวจสอบ · ระบบจะต่ออายุให้อัตโนมัติเมื่ออนุมัติ</div>';
        return;
    }
    if ($state === 'trial') {
        echo '<div class="sub-bar">ทดลองใช้ฟรี เหลืออีก <b>' . (int) $days . ' วัน</b> (ถึง ' . e(thai_date($u['expires_at'], true, true)) . ')' . $link . '</div>';
    } elseif ($state === 'active' && $days !== null && $days <= 7) {
        echo '<div class="sub-bar sub-bar--warn">สมาชิกจะหมดอายุใน <b>' . (int) $days . ' วัน</b> (' . e(thai_date($u['expires_at'], true, true)) . ')' . $link . '</div>';
    } elseif ($state === 'expired') {
        $close = account_links_close_at($u);
        $msg = $close && $close > time()
            ? 'ลิงก์ที่ส่งให้ลูกค้าจะยังเปิดได้ถึง <b>' . e(thai_date(date('Y-m-d H:i:s', $close), true, true)) . '</b>'
            : 'ลิงก์ที่ส่งให้ลูกค้าถูกปิดชั่วคราวจนกว่าจะต่ออายุ';
        echo '<div class="sub-bar sub-bar--err"><b>สมาชิกหมดอายุแล้ว</b> — สร้าง/แก้ไขแกลเลอรีไม่ได้ · ' . $msg . $link . '</div>';
    }
}

/** Membership timeline (newest first). $forAdmin shows who did what. */
function render_timeline(array $events, bool $forAdmin): void
{
    if (!$events) {
        echo '<p class="hint">ยังไม่มีประวัติ</p>';
        return;
    }
    $fmt = fn($d) => $d ? thai_date($d, true, true) : 'ไม่มีกำหนด';
    echo '<ol class="tl">';
    foreach ($events as $ev) {
        [$label, $tone] = membership_event_meta($ev['type']);
        $showExpiry = $ev['expires_after'] !== null || $ev['expires_before'] !== null || $ev['type'] === 'admin_unlimited';
        $actor = '';
        if ($ev['actor_role'] === 'admin') $actor = $forAdmin ? 'โดย ' . ($ev['actor_label'] ?: 'ผู้ดูแลระบบ') : 'โดย ผู้ดูแลระบบ';
        elseif ($ev['actor_role'] === 'self') $actor = $forAdmin ? 'โดยสมาชิก' : 'โดยคุณ';
        ?>
      <li class="tl__item tl__item--<?= e($tone) ?>">
        <span class="tl__dot" aria-hidden="true"></span>
        <div class="tl__body">
          <p class="tl__head"><b><?= e($label) ?></b><time><?= e(thai_date($ev['created_at'], true, true)) ?></time></p>
          <?php if ($ev['detail'] !== ''): ?><p class="tl__detail"><?= e($ev['detail']) ?></p><?php endif; ?>
          <?php if ($showExpiry && empty($ev['virtual'])): ?>
            <p class="tl__exp">
              <?php if ($ev['type'] === 'trial_started' || $ev['type'] === 'created_by_admin'): ?>
                ใช้งานได้ถึง <?= e($fmt($ev['expires_after'])) ?>
              <?php else: ?>
                หมดอายุ <?= e($ev['expires_before'] ? thai_date($ev['expires_before'], true) : '—') ?> → <b><?= e($fmt($ev['expires_after'])) ?></b>
              <?php endif; ?>
            </p>
          <?php endif; ?>
          <?php if ($ev['payment_id'] || $actor !== ''): ?>
            <p class="tl__meta">
              <?php if ($actor !== ''): ?><span><?= e($actor) ?></span><?php endif; ?>
              <?php if ($ev['payment_id'] && !empty($ev['slip_file'])): ?>
                <a href="<?= e(url('admin/slip.php?id=' . (int) $ev['payment_id'])) ?>" target="_blank" rel="noopener">ดูสลิป</a>
              <?php endif; ?>
              <?php if ($ev['type'] === 'payment_submitted' && $ev['payment_status'] === 'pending'): ?>
                <?php if ($forAdmin): ?><a class="pill pill--pending" href="<?= e(url('admin/payments.php')) ?>">รอตรวจสอบ →</a><?php else: ?><span class="pill pill--pending">รอตรวจสอบ</span><?php endif; ?>
              <?php endif; ?>
            </p>
          <?php endif; ?>
        </div>
      </li>
        <?php
    }
    echo '</ol>';
}

function admin_footer(): void
{
    ?>
</main>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<div class="ntoasts" id="ntoasts" aria-live="polite"></div>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
<?php
}

/** On/off controls for live notifications in this browser (pop-up permission + sound). */
function web_notify_controls(): void
{
    ?>
<div class="np" id="np-box">
  <div class="np__txt">
    <b>แจ้งเตือนบนเว็บ (เครื่องนี้)</b>
    <small id="np-state">กำลังตรวจสอบ…</small>
  </div>
  <div class="np__acts">
    <label class="switch"><input type="checkbox" id="np-sound" checked><i></i> เสียง</label>
    <button type="button" class="btn btn--sm" id="np-enable">เปิดป๊อปอัปบนเบราว์เซอร์</button>
    <button type="button" class="btn btn--sm btn--ghost" id="np-test">ลองแจ้งเตือน</button>
  </div>
</div>
<?php
}
