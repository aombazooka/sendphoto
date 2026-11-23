<?php
/**
 * Client gallery – opened from the share link /s/{token}. No login needed.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

$token = (string) ($_GET['t'] ?? '');
$album = $token !== '' ? find_album_by_token($token) : null;

// Branding comes from the shop that owns the gallery.
$owner    = $album ? album_owner($album) : null;
$ownerId  = $owner ? (int) $owner['id'] : 0;
$shopName = $owner && $owner['shop_name'] !== '' ? $owner['shop_name'] : platform_name();

$fonts = 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=IBM+Plex+Sans+Thai:wght@300;400;500;600&family=Noto+Serif+Thai:wght@400;500;600&display=swap';

/* ---------- unavailable (missing / closed / expired) — checked on the server every time ---------- */
if (!$album || !album_is_public($album)) {
    http_response_code($album ? 410 : 404);
    $contact = user_setting($ownerId, 'contact', '');
    ?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ลิงก์ไม่พร้อมใช้งาน · <?= e($shopName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="<?= e($fonts) ?>" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="auth">
  <main class="auth__card">
    <div class="auth__mark">✦</div>
    <p class="eyebrow"><?= e($shopName) ?></p>
    <h1 class="display"><?= $album && album_expired($album) ? 'ลิงก์นี้หมดอายุแล้ว' : 'ไม่พบแกลเลอรีนี้' ?></h1>
    <p class="muted">ลิงก์อาจหมดอายุ ถูกปิด หรือพิมพ์ไม่ถูกต้อง<br>หากต้องการรับภาพอีกครั้ง กรุณาติดต่อช่างภาพ</p>
    <?php if ($contact): ?><p class="auth__contact"><?= e($contact) ?></p><?php endif; ?>
  </main>
</body>
</html>
<?php
    exit;
}

/* ---------- count a view (once per 12 hours per browser) ---------- */
$cookie = 'v_' . substr(sha1($album['token']), 0, 12);
if (empty($_COOKIE[$cookie])) {
    db()->prepare('UPDATE albums SET views = views + 1 WHERE id = ?')->execute([$album['id']]);
    setcookie($cookie, '1', ['expires' => time() + 43200, 'path' => base_path() . '/', 'samesite' => 'Lax', 'httponly' => true]);
}
if (!viewer_is_owner($album)) {
    notify((int) $album['user_id'], 'view', 'ลูกค้าเปิดลิงก์ “' . $album['title'] . '” แล้ว',
        $album['client'] !== '' ? 'ลูกค้า: ' . $album['client'] : '', (int) $album['id'],
        abs_url('admin/album.php?id=' . $album['id']), 'view:' . $album['id']);
}

$images   = album_images((int) $album['id']);
$count    = count($images);
$bytes    = array_sum(array_map(fn($i) => (int) $i['size_bytes'], $images));
$cover    = album_cover_url($album, true, 'preview');
$logo     = album_logo_url($album);
$allowDl  = (int) $album['allow_download'] === 1;
$contact  = user_setting($ownerId, 'contact', '');
$style    = ($album['cover_style'] ?? 'editorial') === 'full' ? 'full' : 'editorial';
$proofOn  = (int) ($album['proof_enabled'] ?? 0) === 1 && $count > 0 && album_has_feature($album, 'proof');
$proofOpen = $proofOn && proof_open($album);
$pLimit   = (int) ($album['proof_limit'] ?? 0);
$pPrice   = (float) ($album['proof_extra_price'] ?? 0);
$pDead    = !empty($album['proof_deadline']) ? thai_date($album['proof_deadline'], true) : '';
// Subfolders in Drive become sections ("พิธีเช้า", "ฉลองเย็น" …)
$sections = [];
foreach ($images as $n => $img) {
    $key = (string) ($img['section'] ?? '');
    if (!isset($sections[$key])) $sections[$key] = ['name' => $key, 'items' => []];
    $sections[$key]['items'][$n] = $img;
}
$sections = array_values($sections);
$hasSections = count($sections) > 1 || ($sections && $sections[0]['name'] !== '');
$metaBits = [$count . ' ภาพ'];
if ($bytes) $metaBits[] = human_bytes($bytes);
$meta     = implode(' · ', $metaBits);

$data = [
    'token'   => $album['token'],
    'base'    => url(''),
    'allowDl' => $allowDl,
    'batch'   => max(1, (int) config('share_batch_files', 10)),
    'batchMb' => max(5, (int) config('share_batch_mb', 80)),
    'proof'   => $proofOn ? [
        'open'  => $proofOpen,
        'limit' => $pLimit,
        'price' => $pPrice,
    ] : null,
    'images'  => array_map(fn($i) => [
        'id' => (int) $i['id'],
        'w'  => (int) $i['width'],
        'h'  => (int) $i['height'],
        'n'  => (string) $i['name'],
        'b'  => (int) $i['size_bytes'],
    ], $images),
];

$heart = '<svg class="i-heart" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.3s-7.5-4.6-7.5-10.1A4.3 4.3 0 0 1 12 7.6a4.3 4.3 0 0 1 7.5 2.6c0 5.5-7.5 10.1-7.5 10.1z"/></svg>';
$check = '<svg class="i-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5.5 12.5l4.2 4.2 8.8-9.4"/></svg>';
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="theme-color" content="#f5efe6">
<title><?= e($album['title']) ?> — <?= e($shopName) ?></title>
<meta property="og:title" content="<?= e($album['title']) ?>">
<meta property="og:description" content="<?= e($meta) ?> · <?= e($shopName) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="<?= e($fonts) ?>" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="g g--<?= $style ?> <?= $allowDl ? '' : 'g--nodl' ?> <?= $proofOn ? 'g--proof mode-proof' : 'mode-save' ?>">

<div class="inapp" id="inapp" hidden>
  <span>เพื่อบันทึกภาพลงเครื่องได้ แนะนำเปิดใน Safari / Chrome</span>
  <a id="inapp-open" href="#">เปิดในเบราว์เซอร์</a>
</div>

<header class="cover cover--<?= $style ?>">
  <?php if ($style === 'full' && $cover): ?><img class="cover__bg" src="<?= e($cover) ?>" alt="" fetchpriority="high"><div class="cover__shade"></div><?php endif; ?>
  <div class="cover__bar">
    <?php if ($logo && $style === 'full'): ?><img class="cover__logo" src="<?= e($logo) ?>" alt="<?= e($shopName) ?>"><?php else: ?><span class="cover__brand"><?= e($shopName) ?></span><?php endif; ?>
    <span class="cover__count"><?= $count ?> ภาพ</span>
  </div>
  <div class="cover__body">
    <div class="cover__text">
      <?php if ($album['event_date']): ?><p class="cover__date"><?= e(thai_date($album['event_date'])) ?></p><?php endif; ?>
      <h1 class="cover__title"><?= e($album['title']) ?></h1>
      <?php if ($album['tagline']): ?><p class="cover__tag"><?= e($album['tagline']) ?></p><?php endif; ?>
      <p class="cover__meta"><?= e($meta) ?> · โดย <?= e($shopName) ?></p>
      <div class="cover__actions">
        <a class="cbtn cbtn--solid" href="#photos" data-go="save">ดูภาพทั้งหมด</a>
        <?php if ($proofOpen): ?><a class="cbtn" href="#photos" data-go="proof">♡ คัดรูปที่ชอบ</a><?php endif; ?>
      </div>
    </div>
    <?php if ($style === 'editorial' && $cover): ?>
      <figure class="cover__photo">
        <img src="<?= e($cover) ?>" alt="" fetchpriority="high">
        <?php if ($logo): ?><img class="cover__logo" src="<?= e($logo) ?>" alt="<?= e($shopName) ?>"><?php endif; ?>
      </figure>
    <?php endif; ?>
  </div>
</header>

<main class="pg" id="photos">
  <div class="pg__intro">
    <div>
      <h2 class="pg__title">ภาพทั้งหมด <span><?= $count ?></span></h2>
      <?php if ($album['description']): ?><p class="pg__desc"><?= nl2br(e($album['description'])) ?></p><?php endif; ?>
    </div>
    <?php if ($proofOn): ?>
    <aside class="pnote <?= $proofOpen ? '' : 'pnote--closed' ?>">
      <p class="pnote__h"><span class="pnote__ic">♡</span> คัดรูปส่งให้ช่างภาพ</p>
      <p class="pnote__rule">
        <?php if ($pLimit): ?>เลือกได้ <b><?= $pLimit ?> ภาพ</b><?= $pPrice > 0 ? ' · เกินภาพละ ฿' . e(money($pPrice)) : '' ?><?php else: ?>เลือกได้ไม่จำกัดจำนวน<?php endif; ?>
        <?php if ($pDead): ?> · ส่งภายใน <?= e($pDead) ?><?php endif; ?>
      </p>
      <?php if (!$proofOpen): ?><p class="pnote__msg">หมดเวลาส่งรายการคัดรูปแล้ว หากต้องการแก้ไขกรุณาติดต่อช่างภาพ</p>
      <?php elseif (!empty($album['proof_message'])): ?><p class="pnote__msg"><?= nl2br(e($album['proof_message'])) ?></p><?php endif; ?>
    </aside>
    <?php endif; ?>
  </div>

  <?php if ($count): ?>
  <div class="bar" id="bar">
    <?php if ($hasSections): ?>
    <nav class="secnav" id="secnav" aria-label="หมวดภาพ">
      <?php foreach ($sections as $sk => $sec): ?>
        <a href="#sec-<?= $sk ?>" data-sec="<?= $sk ?>"><?= e($sec['name'] !== '' ? $sec['name'] : 'ภาพหลัก') ?> <span><?= count($sec['items']) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php if ($proofOn && $allowDl): ?>
    <div class="modes" role="tablist" aria-label="โหมด">
      <button type="button" role="tab" data-mode="proof"><?= $heart ?> คัดรูป <span class="modes__n" id="pick-badge"></span></button>
      <button type="button" role="tab" data-mode="save"><?= $check ?> บันทึกภาพ</button>
    </div>
    <?php else: ?>
    <p class="bar__hint"><?= $proofOn ? 'แตะ ♡ เพื่อคัดภาพที่ต้องการ' : 'แตะ ✓ เพื่อเลือกภาพที่ต้องการบันทึก' ?></p>
    <?php endif; ?>
    <div class="bar__right">
      <span class="bar__links">
        <button type="button" class="link-btn save-only" id="sel-all">เลือกทั้งหมด</button>
        <?php if ($proofOn && $allowDl): ?><button type="button" class="link-btn save-only" id="sel-picks" hidden>เลือกภาพที่คัดไว้</button><?php endif; ?>
        <?php if ($allowDl): ?><button type="button" class="link-btn save-only" id="zip-all">ดาวน์โหลดทั้งหมด</button><?php endif; ?>
      </span>
      <div class="sizes" id="sizes" role="radiogroup" aria-label="ขนาดภาพ">
        <button type="button" role="radio" data-s="s" title="ภาพเล็ก"><i></i><i></i><i></i><i></i></button>
        <button type="button" role="radio" data-s="m" title="ภาพกลาง"><i></i><i></i></button>
        <button type="button" role="radio" data-s="l" title="ภาพใหญ่"><i></i></button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$count): ?>
    <div class="empty"><div class="empty__mark">✦</div><p class="muted">ภาพกำลังจะมาเร็ว ๆ นี้</p></div>
  <?php else: ?>
  <div class="gw" id="grid" data-size="m">
    <?php foreach ($sections as $sk => $sec): ?>
    <section class="sec" id="sec-<?= $sk ?>">
      <?php if ($hasSections): ?><h3 class="sec__h"><?= e($sec['name'] !== '' ? $sec['name'] : 'ภาพหลัก') ?> <span><?= count($sec['items']) ?> ภาพ</span></h3><?php endif; ?>
      <div class="jg">
    <?php foreach ($sec['items'] as $n => $img):
        $r = ((int) $img['width'] && (int) $img['height']) ? round($img['width'] / $img['height'], 4) : 0; ?>
      <figure class="tile" data-i="<?= $n ?>" data-id="<?= (int) $img['id'] ?>"<?= $r ? ' style="--r:' . $r . '"' : '' ?>>
        <img src="<?= e(media_url($img, 'thumb', $album['token'])) ?>" alt="ภาพที่ <?= $n + 1 ?>" loading="lazy" decoding="async">
        <span class="tile__no"><?= $n + 1 ?></span>
        <span class="tile__pick" aria-hidden="true"><?= $heart ?></span>
        <?php if ($allowDl || $proofOn): ?>
        <button type="button" class="mark" aria-pressed="false" aria-label="เลือกภาพที่ <?= $n + 1 ?>"><?= $heart ?><?= $check ?></button>
        <?php endif; ?>
      </figure>
    <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</main>

<footer class="g-foot">
  <p class="g-foot__brand"><?= e($shopName) ?></p>
  <?php if ($contact): ?><p class="g-foot__contact"><?= e($contact) ?></p><?php endif; ?>
  <?php if ($album['expires_at']): ?><p class="g-foot__exp">ลิงก์นี้ใช้งานได้ถึง <?= e(thai_date($album['expires_at'], false, true)) ?> น. กรุณาบันทึกภาพก่อนหมดอายุ</p><?php endif; ?>
</footer>

<div class="dock" id="dock" aria-live="polite">
  <div class="dock__thumbs" id="dock-thumbs" aria-hidden="true"></div>
  <div class="dock__info">
    <p class="dock__count" id="dock-count"></p>
    <div class="dock__meter" id="dock-meter" hidden><i id="dock-meter-bar"></i></div>
    <p class="dock__note" id="dock-note"></p>
  </div>
  <div class="dock__btns">
    <button type="button" class="dock__clear" id="dock-cancel">ล้าง</button>
    <button type="button" class="dock__go" id="dock-save">บันทึกภาพ</button>
  </div>
  <button type="button" class="dock__alt" id="dock-zip" hidden>ดาวน์โหลดเป็นไฟล์ ZIP แทน</button>
</div>

<?php if ($allowDl): ?>
<form id="zip-form" method="post" action="<?= e(url('download.php')) ?>" hidden>
  <input type="hidden" name="t" value="<?= e($album['token']) ?>">
  <input type="hidden" name="ids" value="">
  <input type="hidden" name="all" value="">
</form>
<?php endif; ?>

<?php if ($proofOn): ?>
<dialog class="dlg" id="send-dlg">
  <form method="dialog" class="dlg__form" id="send-form">
    <p class="dlg__eyebrow">ส่งรายการคัดรูป</p>
    <h3 class="dlg__title">ส่งให้ <?= e($shopName) ?></h3>
    <p class="dlg__sum" id="send-sum"></p>
    <label class="field"><span class="field__lbl">ชื่อของคุณ <em class="req">จำเป็น</em></span><input type="text" name="name" maxlength="120" required autocomplete="name"></label>
    <label class="field"><span class="field__lbl">เบอร์โทร / LINE <em>ไม่บังคับ</em></span><input type="text" name="contact" maxlength="160" autocomplete="tel"></label>
    <label class="field"><span class="field__lbl">ฝากถึงช่างภาพ <em>ไม่บังคับ</em></span><textarea name="note" rows="3" maxlength="2000" placeholder="เช่น ขอปรับโทนให้สว่างขึ้น, ภาพที่ 12 ขอครอปแนวตั้ง"></textarea></label>
    <p class="dlg__err" id="send-err" hidden></p>
    <div class="dlg__btns">
      <button type="button" class="cbtn" id="send-cancel">ยกเลิก</button>
      <button type="submit" class="cbtn cbtn--solid" id="send-go">ส่งรายการ</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<div class="lb" id="lb" hidden role="dialog" aria-modal="true" aria-label="ดูภาพ">
  <div class="lb__top">
    <span class="lb__count" id="lb-count"></span>
    <div class="lb__actions">
      <?php if ($proofOn): ?>
      <button type="button" class="lb__btn lb__pick" id="lb-pick" aria-pressed="false"><?= $heart ?><span>คัดรูปนี้</span></button>
      <?php endif; ?>
      <?php if ($allowDl): ?><a class="lb__btn" id="lb-dl" href="#" download>ดาวน์โหลด</a><?php endif; ?>
      <button type="button" class="lb__btn lb__close" id="lb-close" aria-label="ปิด">✕</button>
    </div>
  </div>
  <div class="lb__stage" id="lb-stage">
    <img id="lb-img" alt="">
    <span class="spinner lb__spin" id="lb-spin"></span>
  </div>
  <button type="button" class="lb__nav lb__prev" id="lb-prev" aria-label="ภาพก่อนหน้า">‹</button>
  <button type="button" class="lb__nav lb__next" id="lb-next" aria-label="ภาพถัดไป">›</button>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>window.GALLERY = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(asset('js/gallery.js')) ?>"></script>
</body>
</html>
