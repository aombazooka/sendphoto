<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/admin_layout.php';
$me  = require_login();
$uid = (int) $me['id'];

$id    = (int) ($_GET['id'] ?? 0);
$album = $id ? find_my_album($id, $me) : null;
if ($id && !$album) {
    flash('ไม่พบอัลบั้มนี้', 'err');
    redirect(url('admin/'));
}
$isNew  = !$album;
if ($isNew) require_active_account($me);
$limit = album_limit($me);
if ($isNew && $limit && count_user_albums($uid) >= $limit) {
    flash('แพ็กเกจของคุณสร้างแกลเลอรีได้สูงสุด ' . $limit . ' แกลเลอรี กรุณาลบแกลเลอรีเก่าหรือเปลี่ยนแพ็กเกจ', 'err');
    redirect(url('admin/'));
}
$errors = [];

$expiryOptions = [
    '7d'    => ['7 วัน', '+7 days'],
    '30d'   => ['30 วัน', '+30 days'],
    '90d'   => ['90 วัน', '+90 days'],
    '180d'  => ['6 เดือน', '+6 months'],
    '1y'    => ['1 ปี', '+1 year'],
    'never' => ['ไม่หมดอายุ', null],
    'custom'=> ['กำหนดเอง', null],
];

$form = $album ?? [
    'title' => '', 'client' => '', 'tagline' => 'ขอบคุณที่ให้เราได้เก็บวันสำคัญของคุณ', 'description' => '',
    'event_date' => date('Y-m-d'), 'sources' => '', 'is_active' => 1, 'allow_download' => 1,
    'expires_at' => null, 'cover_file' => null, 'logo_file' => null, 'cover_style' => 'editorial',
    'proof_enabled' => 0, 'proof_limit' => 0, 'proof_extra_price' => 0, 'proof_deadline' => null, 'proof_message' => '',
];
$expiryMode = $isNew ? '1y' : 'keep';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_active_account($me);
    $form = array_merge($form, [
        'title'          => trim((string) ($_POST['title'] ?? '')),
        'client'         => trim((string) ($_POST['client'] ?? '')),
        'tagline'        => trim((string) ($_POST['tagline'] ?? '')),
        'description'    => trim((string) ($_POST['description'] ?? '')),
        'event_date'     => trim((string) ($_POST['event_date'] ?? '')) ?: null,
        'sources'        => trim(str_replace("\r\n", "\n", (string) ($_POST['sources'] ?? ''))),
        'is_active'      => isset($_POST['is_active']) ? 1 : 0,
        'allow_download' => isset($_POST['allow_download']) ? 1 : 0,
        'cover_style'    => ($_POST['cover_style'] ?? '') === 'full' ? 'full' : 'editorial',
        'proof_enabled'  => isset($_POST['proof_enabled']) && has_feature($me, 'proof') ? 1 : 0,
        'proof_limit'    => max(0, min(100000, (int) ($_POST['proof_limit'] ?? 0))),
        'proof_extra_price' => max(0, min(1000000, round((float) ($_POST['proof_extra_price'] ?? 0), 2))),
        'proof_deadline' => trim((string) ($_POST['proof_deadline'] ?? '')) ?: null,
        'proof_message'  => trim(mb_substr((string) ($_POST['proof_message'] ?? ''), 0, 500)),
    ]);

    // Link lifetime
    $expiryMode = (string) ($_POST['expiry_mode'] ?? 'keep');
    if ($expiryMode === 'never') {
        $form['expires_at'] = null;
    } elseif ($expiryMode === 'custom') {
        $raw = trim((string) ($_POST['expires_at'] ?? ''));
        $ts = $raw !== '' ? strtotime($raw) : false;
        if (!$ts) $errors[] = 'กรุณาระบุวันหมดอายุลิงก์';
        else $form['expires_at'] = date('Y-m-d H:i:s', $ts);
    } elseif (isset($expiryOptions[$expiryMode][1])) {
        $form['expires_at'] = date('Y-m-d H:i:s', strtotime($expiryOptions[$expiryMode][1]));
    }

    if ($form['title'] === '') $errors[] = 'กรุณาใส่ชื่ออัลบั้ม';
    $hasSource = false;
    foreach (preg_split('~\R~', $form['sources']) as $line) {
        $src = parse_source($line);
        if (!$src) continue;
        $hasSource = true;
        if ($src['type'] === 'invalid') $errors[] = 'ลิงก์ไม่ถูกต้อง: ' . $src['url'];
    }
    if (!$hasSource) $errors[] = 'กรุณาวางลิงก์ Google Drive, Google Photos หรือ Immich อย่างน้อย 1 ลิงก์';

    // Cover & logo uploads
    $newCover = $newLogo = null;
    if (!$errors) {
        try {
            $newCover = save_upload('cover', 20);
            $newLogo  = save_upload('logo', 5);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        $oldCover = $form['cover_file'];
        $oldLogo  = $form['logo_file'];
        if ($newCover) {
            $form['cover_file'] = $newCover;
        } elseif (!empty($_POST['remove_cover'])) {
            $form['cover_file'] = null;
        }
        $logoMode = (string) ($_POST['logo_mode'] ?? 'default');
        if ($newLogo)                 $form['logo_file'] = $newLogo;
        elseif ($logoMode === 'none') $form['logo_file'] = '-';
        elseif ($logoMode === 'default') $form['logo_file'] = null;
        if ($oldCover !== $form['cover_file']) delete_upload($oldCover);
        if ($oldLogo !== $form['logo_file'])   delete_upload($oldLogo);

        $vals = [$form['title'], $form['client'], $form['tagline'], $form['description'], $form['event_date'],
                 $form['sources'], $form['cover_file'], $form['logo_file'], $form['is_active'],
                 $form['allow_download'], $form['expires_at'], $form['cover_style'], $form['proof_enabled'],
                 $form['proof_limit'], $form['proof_extra_price'], $form['proof_deadline'], $form['proof_message']];
        $sourcesChanged = $isNew || $form['sources'] !== (string) $album['sources'];

        if ($isNew) {
            do { $token = new_token(); } while (find_album_by_token($token));
            db()->prepare('INSERT INTO albums(user_id, title, client, tagline, description, event_date, sources, cover_file, logo_file,
                           is_active, allow_download, expires_at, cover_style, proof_enabled, proof_limit, proof_extra_price,
                           proof_deadline, proof_message, token) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$uid, ...$vals, $token]);
            $id = (int) db()->lastInsertId();
        } else {
            db()->prepare('UPDATE albums SET title=?, client=?, tagline=?, description=?, event_date=?, sources=?, cover_file=?,
                           logo_file=?, is_active=?, allow_download=?, expires_at=?, cover_style=?, proof_enabled=?, proof_limit=?,
                           proof_extra_price=?, proof_deadline=?, proof_message=? WHERE id=? AND user_id=?')
                ->execute([...$vals, $id, $uid]);
        }

        $msg = $isNew ? 'สร้างลิงก์แกลเลอรีเรียบร้อย' : 'บันทึกเรียบร้อย';
        $type = 'ok';
        if ($sourcesChanged) {
            $r = sync_album($id);
            $msg .= ' · พบ ' . $r['count'] . ' ภาพ';
            if ($r['added'] && !$isNew) $msg .= ' (ใหม่ ' . $r['added'] . ')';
            if ($r['removed']) $msg .= ' (นำออก ' . $r['removed'] . ')';
            if ($r['errors']) { $msg .= "\n" . implode("\n", $r['errors']); $type = 'warn'; }
        }
        flash($msg, $type);
        redirect(url('admin/album.php?id=' . $id . ($isNew ? '&created=1' : '')));
    }
}

$images   = $album ? album_images((int) $album['id'], true) : [];
$counts   = $album ? count_images((int) $album['id']) : ['total' => 0, 'visible' => 0, 'bytes' => 0];
$coverImg = $album ? album_cover_image($album) : null;
$uncached = 0;
if ($album) {
    require_once __DIR__ . '/../inc/proxy.php';
    if (backfill_ratios($images)) $images = album_images((int) $album['id'], true);
    foreach ($images as $im) {
        if (!(int) $im['hidden'] && !is_file(cache_path($im, 'thumb'))) $uncached++;
    }
}
$coverUrl = $album ? album_cover_url($album) : '';
$logoMode = ($form['logo_file'] ?? null) === '-' ? 'none' : (!empty($form['logo_file']) ? 'custom' : 'default');
$defaultLogo = upload_url(user_setting($uid, 'logo_file'));
$logoUrl  = $logoMode === 'custom' ? upload_url($form['logo_file']) : ($logoMode === 'default' ? $defaultLogo : '');
$st = db()->prepare('SELECT id, user_id, title, token, is_active, expires_at FROM albums WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 6');
$st->execute([$uid]);
$recent = $st->fetchAll();

// Client proofing lists
$proofs = $album ? album_proofs((int) $album['id']) : [];
$imgNo = [];
if ($proofs) {
    $n = 0;
    foreach ($images as $im) if (!(int) $im['hidden']) $imgNo[(int) $im['id']] = ++$n;
    db()->prepare('UPDATE proofs SET seen = 1 WHERE album_id = ? AND seen = 0')->execute([(int) $album['id']]);
}

admin_header($isNew ? 'สร้างลิงก์แกลเลอรี' : $album['title'], 'albums');
?>

<?php if (!$isNew): [$stKey, $stLabel] = album_status($album); ?>
<div class="strip">
  <span><?= e(site_name()) ?> Owner</span>
  <span>เริ่ม <?= e(thai_date($album['created_at'], true, true)) ?></span>
  <span>หมดอายุ <?= $album['expires_at'] ? e(thai_date($album['expires_at'], true, true)) : 'ไม่มีกำหนด' ?></span>
  <span class="strip__st strip__st--<?= $stKey ?>"><?= e($stLabel) ?></span>
  <?php if ($album['expires_at'] && $stKey === 'on'): ?>
    <span data-countdown="<?= e(date('c', strtotime($album['expires_at']))) ?>"></span>
  <?php endif; ?>
</div>
<?php endif; ?>

<section class="a-head">
  <div>
    <p class="crumb"><a href="<?= e(url('admin/')) ?>">แกลเลอรีทั้งหมด</a> <span>/</span> <?= $isNew ? 'สร้างใหม่' : 'แก้ไข' ?></p>
    <h1 class="display"><?= $isNew ? 'สร้างลิงก์แกลเลอรี' : e($album['title']) ?></h1>
    <p class="muted">พื้นที่เล็ก ๆ สำหรับส่งมอบความทรงจำที่ยิ่งใหญ่</p>
  </div>
  <?php if (!$isNew): ?>
  <div class="a-head__actions">
    <form method="post" action="<?= e(url('admin/action.php')) ?>" data-loading="กำลังดึงภาพจากลิงก์…">
      <?= csrf_field() ?><input type="hidden" name="action" value="sync"><input type="hidden" name="id" value="<?= (int) $album['id'] ?>">
      <button class="btn btn--ghost" type="submit">↻ ดึงภาพใหม่</button>
    </form>
    <a class="btn" href="<?= e(share_url($album['token'])) ?>" target="_blank" rel="noopener">เปิดหน้าลูกค้า ↗</a>
  </div>
  <?php endif; ?>
</section>

<?php if ($errors): ?>
  <div class="notice notice--err"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>
<?php if (!$isNew && $uncached > 0): ?>
  <div class="warm" id="warm" data-album="<?= (int) $album['id'] ?>" data-total="<?= $counts['visible'] ?>" data-cached="<?= $counts['visible'] - $uncached ?>">
    <div class="warm__txt">
      <span class="spinner spinner--sm"></span>
      <span><b>กำลังเตรียมภาพตัวอย่างให้ลูกค้า</b> <span id="warm-n"><?= $counts['visible'] - $uncached ?></span> / <?= $counts['visible'] ?> ภาพ — เปิดหน้านี้ค้างไว้สักครู่ เพื่อให้ลูกค้าเห็นภาพขึ้นเร็วตั้งแต่ครั้งแรก</span>
    </div>
    <div class="warm__bar"><i id="warm-bar" style="width: <?= $counts['visible'] ? round(($counts['visible'] - $uncached) / $counts['visible'] * 100) : 0 ?>%"></i></div>
  </div>
<?php endif; ?>
<?php if (!$isNew && $album['sync_note']): ?>
  <div class="notice notice--warn"><strong>การดึงภาพล่าสุดมีปัญหา:</strong><br><?= nl2br(e($album['sync_note'])) ?></div>
<?php endif; ?>

<div class="layout">
  <form method="post" enctype="multipart/form-data" class="panel form" id="album-form" data-loading="กำลังบันทึกและดึงภาพ…">
    <?= csrf_field() ?>
    <header class="panel__hd">
      <p class="step">ข้อมูลงาน</p>
      <h2 class="panel__title">รายละเอียดแกลเลอรี</h2>
      <p class="hint">ใส่ลิงก์และรายละเอียดงาน เพื่อเตรียมแกลเลอรีสำหรับลูกค้าของคุณ</p>
    </header>

    <div class="field">
      <span class="field__lbl">ลิงก์อัลบั้ม <em class="req">จำเป็น</em>
        <span class="src-icons" title="Google Drive · Google Photos · Immich">
          <i class="ic ic--drive"></i><i class="ic ic--photos"></i><i class="ic ic--immich"></i>
        </span>
      </span>
      <small class="hint">วางลิงก์แชร์จาก Google Drive, Google Photos หรือ Immich — 1 บรรทัดต่อ 1 ลิงก์</small>
      <div class="linkbox">
        <span class="linkbox__ic">🔗</span>
        <textarea name="sources" id="sources" rows="2" class="mono" placeholder="https://drive.google.com/drive/folders/…" required><?= e($form['sources']) ?></textarea>
        <button type="button" class="btn btn--sm" id="check-btn">ตรวจลิงก์</button>
      </div>
      <div class="check" id="check-result" hidden></div>
      <small class="hint">ตั้งค่าแชร์เป็น <b>“ทุกคนที่มีลิงก์”</b> ก่อน · โฟลเดอร์ Drive จะรวมโฟลเดอร์ย่อยให้อัตโนมัติ</small>
    </div>

    <label class="field">
      <span class="field__lbl">ชื่ออัลบั้ม <em class="req">จำเป็น</em></span>
      <small class="hint">ตั้งชื่อที่จะแสดงบนหน้าปกแกลเลอรี</small>
      <input type="text" name="title" id="f-title" value="<?= e($form['title']) ?>" placeholder="เช่น งานแต่งงาน คุณเอ & คุณบี" required maxlength="200">
    </label>

    <label class="field">
      <span class="field__lbl">ข้อความใต้ชื่อ <em>ไม่บังคับ</em></span>
      <input type="text" name="tagline" id="f-tagline" value="<?= e($form['tagline']) ?>" placeholder="เช่น ขอบคุณที่ให้เราได้เก็บวันสำคัญของคุณ" maxlength="200">
    </label>

    <div class="row">
      <label class="field">
        <span class="field__lbl">ชื่อลูกค้า <em>ไม่บังคับ</em></span>
        <input type="text" name="client" value="<?= e($form['client']) ?>" placeholder="ใช้ค้นหาในหน้าจัดการ">
      </label>
      <label class="field">
        <span class="field__lbl">วันที่ถ่าย</span>
        <input type="date" name="event_date" id="f-date" value="<?= e($form['event_date']) ?>">
      </label>
    </div>

    <label class="field">
      <span class="field__lbl">ข้อความถึงลูกค้า <em>ไม่บังคับ</em></span>
      <textarea name="description" rows="2" placeholder="ขอบคุณที่ไว้วางใจให้เราเก็บช่วงเวลาสำคัญ…"><?= e($form['description']) ?></textarea>
    </label>

    <div class="field">
      <span class="field__lbl">ภาพปก <em>ไม่บังคับ</em></span>
      <label class="drop" id="cover-drop">
        <input type="file" name="cover" id="f-cover" accept="image/jpeg,image/png,image/webp">
        <span class="drop__ic">⇪</span>
        <span class="drop__txt"><b>เลือกภาพปกของคุณ</b><small>คลิกเพื่อเลือกไฟล์ หรือลากภาพมาวางที่นี่ · ถ้าไม่เลือก จะใช้ภาพแรกในอัลบั้ม</small></span>
        <span class="btn btn--sm">เลือกรูปภาพ</span>
      </label>
      <?php if (!empty($form['cover_file'])): ?>
        <label class="check-inline"><input type="checkbox" name="remove_cover" value="1"> ลบภาพปกที่อัปโหลด (กลับไปใช้ภาพจากอัลบั้ม)</label>
      <?php endif; ?>
      <div class="style-pick" role="radiogroup" aria-label="รูปแบบหน้าปก">
        <label><input type="radio" name="cover_style" value="editorial" <?= ($form['cover_style'] ?? 'editorial') !== 'full' ? 'checked' : '' ?>>
          <span class="style-pick__box"><span class="style-pick__ill style-pick__ill--ed"><i></i><b></b></span><span>แบบนิตยสาร<small>ภาพในกรอบ ข้อความบนพื้นครีม</small></span></span></label>
        <label><input type="radio" name="cover_style" value="full" <?= ($form['cover_style'] ?? '') === 'full' ? 'checked' : '' ?>>
          <span class="style-pick__box"><span class="style-pick__ill style-pick__ill--full"><i></i></span><span>ภาพเต็มจอ<small>ข้อความมุมซ้ายล่าง</small></span></span></label>
      </div>
    </div>

    <div class="field">
      <span class="field__lbl">โลโก้บนภาพปก <em>ไม่บังคับ</em></span>
      <small class="hint">โลโก้ที่จะแสดงมุมซ้ายบนของภาพปก</small>
      <div class="logo-row">
        <span class="logo-row__pv" id="logo-pv"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?>—<?php endif; ?></span>
        <div class="seg" role="radiogroup">
          <label><input type="radio" name="logo_mode" value="default" <?= $logoMode === 'default' ? 'checked' : '' ?>><span>โลโก้หลัก</span></label>
          <label><input type="radio" name="logo_mode" value="custom" <?= $logoMode === 'custom' ? 'checked' : '' ?>><span>อัปโหลดใหม่</span></label>
          <label><input type="radio" name="logo_mode" value="none" <?= $logoMode === 'none' ? 'checked' : '' ?>><span>ไม่แสดง</span></label>
        </div>
        <input type="file" name="logo" id="f-logo" accept="image/png,image/svg+xml,image/webp,image/jpeg" class="logo-row__file">
      </div>
      <?php if (!$defaultLogo): ?><small class="hint">ยังไม่มีโลโก้หลัก — ตั้งได้ที่หน้า <a href="<?= e(url('admin/settings.php')) ?>">ตั้งค่า</a></small><?php endif; ?>
    </div>

    <div class="row">
      <div class="field">
        <span class="field__lbl">อายุลิงก์</span>
        <select name="expiry_mode" id="expiry-mode">
          <?php if (!$isNew): ?><option value="keep" selected>คงเดิม (<?= $album['expires_at'] ? e(thai_date($album['expires_at'], true)) : 'ไม่หมดอายุ' ?>)</option><?php endif; ?>
          <?php foreach ($expiryOptions as $k => [$label]): ?>
            <option value="<?= $k ?>" <?= $expiryMode === $k ? 'selected' : '' ?>><?= $isNew || $k === 'never' || $k === 'custom' ? '' : 'ต่ออายุ ' ?><?= e($label) ?><?= $k !== 'never' && $k !== 'custom' && !$isNew ? ' จากวันนี้' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <input type="datetime-local" name="expires_at" id="expires-at" value="<?= $form['expires_at'] ? e(date('Y-m-d\TH:i', strtotime($form['expires_at']))) : '' ?>" <?= $expiryMode === 'custom' ? '' : 'hidden' ?>>
      </div>
      <div class="field">
        <span class="field__lbl">ตัวเลือก</span>
        <label class="switch"><input type="checkbox" name="is_active" <?= (int) $form['is_active'] ? 'checked' : '' ?>><i></i> เปิดลิงก์ให้ลูกค้า</label>
        <label class="switch"><input type="checkbox" name="allow_download" <?= (int) $form['allow_download'] ? 'checked' : '' ?>><i></i> อนุญาตให้บันทึก/ดาวน์โหลด</label>
      </div>
    </div>

    <section class="fset <?= (int) $form['proof_enabled'] ? 'is-on' : '' ?>" id="proof-set">
      <div class="fset__hd">
        <span class="field__lbl">♡ ระบบคัดรูป <em>ลูกค้าเลือกภาพสำหรับรีทัช / ทำอัลบั้ม แล้วส่งรายการให้คุณ</em></span>
        <?php if (has_feature($me, 'proof')): ?>
        <label class="switch"><input type="checkbox" name="proof_enabled" id="f-proof" <?= (int) $form['proof_enabled'] ? 'checked' : '' ?>><i></i> เปิดใช้</label>
        <?php endif; ?>
      </div>
      <?php if (!has_feature($me, 'proof')): ?><div class="fset__lock"><?= feature_locked_note('proof') ?></div><?php endif; ?>
      <div class="fset__body">
        <div class="row row--3">
          <label class="field">
            <span class="field__lbl">เลือกได้กี่ภาพ</span>
            <input type="number" name="proof_limit" min="0" step="1" value="<?= (int) $form['proof_limit'] ?>" inputmode="numeric">
            <small class="hint">0 = ไม่จำกัด</small>
          </label>
          <label class="field">
            <span class="field__lbl">ภาพที่เกิน (บาท/ภาพ)</span>
            <input type="number" name="proof_extra_price" min="0" step="1" value="<?= e(rtrim(rtrim(number_format((float) $form['proof_extra_price'], 2, '.', ''), '0'), '.')) ?>" inputmode="decimal">
            <small class="hint">0 = ห้ามเลือกเกิน</small>
          </label>
          <label class="field">
            <span class="field__lbl">ส่งรายการภายใน</span>
            <input type="date" name="proof_deadline" value="<?= e($form['proof_deadline'] ?? '') ?>">
            <small class="hint">เว้นว่าง = ไม่กำหนด</small>
          </label>
        </div>
        <label class="field">
          <span class="field__lbl">คำแนะนำถึงลูกค้า <em>ไม่บังคับ</em></span>
          <input type="text" name="proof_message" maxlength="500" value="<?= e($form['proof_message'] ?? '') ?>" placeholder="เช่น เลือกภาพที่ชอบที่สุด 30 ภาพ เพื่อรีทัชและทำอัลบั้ม">
        </label>
      </div>
    </section>

    <div class="form__foot">
      <button class="btn btn--primary btn--wide" type="submit"><?= $isNew ? 'สร้างลิงก์แกลเลอรี' : 'บันทึกการเปลี่ยนแปลง' ?></button>
      <?php if (!$isNew): ?><button class="btn btn--danger-ghost" type="submit" form="del-form">ลบอัลบั้ม</button><?php endif; ?>
    </div>
  </form>

  <aside class="side">
    <section class="panel">
      <header class="panel__hd">
        <p class="step">หน้าปกที่ลูกค้าจะเห็น</p>
        <h2 class="panel__title">ตัวอย่างหน้าปก</h2>
      </header>
      <div class="pv pv--<?= ($form['cover_style'] ?? 'editorial') === 'full' ? 'full' : 'editorial' ?>" id="pv">
        <img class="pv__bg" id="pv-bg" src="<?= e($coverUrl) ?>" alt="" <?= $coverUrl ? '' : 'hidden' ?>>
        <img class="pv__logo" id="pv-logo" src="<?= e($logoUrl) ?>" alt="" <?= $logoUrl ? '' : 'hidden' ?> data-default="<?= e($defaultLogo) ?>" data-current="<?= e($logoMode === 'custom' ? $logoUrl : '') ?>">
        <div class="pv__txt">
          <p class="pv__eyebrow" id="pv-date"><?= e(thai_date($form['event_date'] ?? null)) ?></p>
          <h3 class="pv__title" id="pv-title"><?= e($form['title'] ?: 'ชื่ออัลบั้ม') ?></h3>
          <p class="pv__tag" id="pv-tag"><?= e($form['tagline']) ?></p>
          <p class="pv__meta" id="pv-meta"><?= $counts['visible'] ? $counts['visible'] . ' ภาพ' . ($counts['bytes'] ? ' · ' . human_bytes($counts['bytes']) : '') : '' ?></p>
        </div>
      </div>
      <p class="hint center">ตัวอย่างการแสดงผลเมื่อลูกค้าเปิดลิงก์</p>
    </section>

    <?php if (!$isNew): ?>
    <section class="panel share">
      <header class="panel__hd">
        <p class="step">การแชร์</p>
        <h2 class="panel__title">ส่งให้ลูกค้า</h2>
      </header>
      <input type="text" readonly value="<?= e(share_url($album['token'])) ?>" class="mono share__input" onclick="this.select()">
      <button type="button" class="btn btn--primary btn--block" data-copy="<?= e(share_url($album['token'])) ?>">คัดลอกลิงก์</button>
      <dl class="share__dl">
        <div><dt>ภาพที่แสดง</dt><dd><span id="vis-count"><?= $counts['visible'] ?></span> / <?= $counts['total'] ?><?= $counts['bytes'] ? ' · ' . human_bytes($counts['bytes']) : '' ?></dd></div>
        <div><dt>เข้าชม</dt><dd><?= number_format((int) $album['views']) ?> ครั้ง</dd></div>
        <div><dt>บันทึก/ดาวน์โหลด</dt><dd><?= number_format((int) $album['downloads']) ?> ครั้ง</dd></div>
        <div><dt>ดึงภาพล่าสุด</dt><dd><?= $album['synced_at'] ? e(thai_date($album['synced_at'], true, true)) : '—' ?></dd></div>
      </dl>
      <div class="share__btns">
        <form method="post" action="<?= e(url('admin/action.php')) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= (int) $album['id'] ?>">
          <button class="btn btn--sm <?= (int) $album['is_active'] ? 'btn--danger-ghost' : '' ?>" type="submit"><?= (int) $album['is_active'] ? 'ปิดลิงก์ทันที' : 'เปิดลิงก์อีกครั้ง' ?></button>
        </form>
        <form method="post" action="<?= e(url('admin/action.php')) ?>" data-confirm="สร้างลิงก์ใหม่? ลิงก์เดิมที่ส่งให้ลูกค้าไปแล้วจะใช้ไม่ได้">
          <?= csrf_field() ?><input type="hidden" name="action" value="new_token"><input type="hidden" name="id" value="<?= (int) $album['id'] ?>">
          <button class="btn btn--sm btn--ghost" type="submit">สร้างลิงก์ใหม่</button>
        </form>
      </div>
      <p class="hint">ลูกค้าเปิดลิงก์ได้ทันที ไม่ต้องสมัครหรือเข้าสู่ระบบ</p>
    </section>
    <?php endif; ?>

    <section class="panel">
      <header class="panel__hd">
        <p class="step">ล่าสุด</p>
        <h2 class="panel__title">ลิงก์ที่สร้างแล้ว</h2>
      </header>
      <?php if (!$recent): ?>
        <p class="hint">ยังไม่มีลิงก์</p>
      <?php else: ?>
        <ul class="recent">
          <?php foreach ($recent as $r): [$k, $lbl] = album_status($r); ?>
            <li>
              <a href="<?= e(url('admin/album.php?id=' . $r['id'])) ?>" class="recent__t"><?= e($r['title']) ?></a>
              <span class="recent__u mono"><?= e(share_url($r['token'])) ?></span>
              <span class="recent__s recent__s--<?= $k ?>"><?= e($lbl) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </aside>
</div>

<?php if (!$isNew): ?>
<form id="del-form" method="post" action="<?= e(url('admin/action.php')) ?>" data-confirm="ลบอัลบั้ม “<?= e($album['title']) ?>” ? (ไฟล์ต้นฉบับใน Google Drive / Immich จะไม่ถูกลบ)">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $album['id'] ?>">
</form>

<?php if ((int) $album['proof_enabled'] || $proofs): ?>
<section class="panel" id="proofs">
  <div class="panel__head">
    <div>
      <p class="step">ระบบคัดรูป</p>
      <h2 class="panel__title">รายการที่ลูกค้าคัดมา <span class="muted">(<?= count($proofs) ?>)</span></h2>
    </div>
    <p class="hint">
      <?php if ((int) $album['proof_limit']): ?>โควตา <?= (int) $album['proof_limit'] ?> ภาพ<?= (float) $album['proof_extra_price'] > 0 ? ' · เกินภาพละ ฿' . e(money($album['proof_extra_price'])) : ' · ห้ามเกิน' ?><?php else: ?>ไม่จำกัดจำนวน<?php endif; ?>
      <?php if ($album['proof_deadline']): ?> · ส่งภายใน <?= e(thai_date($album['proof_deadline'], true)) ?><?php endif; ?>
      <?= (int) $album['proof_enabled'] ? '' : ' · <b>ปิดระบบคัดรูปอยู่</b>' ?>
    </p>
  </div>

  <?php if (!$proofs): ?>
    <div class="empty empty--sm">
      <p class="muted">ยังไม่มีลูกค้าส่งรายการ — เมื่อลูกค้ากด ♡ เลือกภาพแล้วกด “ส่งรายการให้ช่างภาพ” รายการจะขึ้นที่นี่</p>
    </div>
  <?php else: ?>
    <div class="proofs">
    <?php foreach ($proofs as $p):
        $pImgs  = proof_images($p);
        $names  = array_map(fn($im) => $im['name'] !== '' ? $im['name'] : ('ภาพที่ ' . ($imgNo[(int) $im['id']] ?? '?')), $pImgs);
        $bases  = array_map(fn($nm) => preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', $nm), $names);
        $nums   = array_map(fn($im) => $imgNo[(int) $im['id']] ?? '?', $pImgs);
        $locked = $p['status'] === 'locked';
    ?>
      <article class="proof <?= $locked ? 'is-locked' : '' ?>">
        <header class="proof__hd">
          <div>
            <h3 class="proof__name"><?= e($p['name']) ?><?php if (!(int) $p['seen']): ?> <span class="pill pill--pending">ใหม่</span><?php endif; ?></h3>
            <p class="proof__meta">
              <?php if ($p['contact'] !== ''): ?><?= e($p['contact']) ?> · <?php endif; ?>
              ส่งเมื่อ <?= e(thai_date($p['submitted_at'], true, true)) ?> น.
            </p>
          </div>
          <div class="proof__sum">
            <b><?= (int) $p['pick_count'] ?></b> ภาพ
            <?php if ((int) $p['extra_count']): ?><span class="proof__extra">เกิน <?= (int) $p['extra_count'] ?> ภาพ · ฿<?= e(money($p['extra_amount'])) ?></span><?php endif; ?>
            <span class="pill <?= $locked ? 'pill--admin' : 'pill--active' ?>"><?= e(proof_status_label($p['status'])) ?></span>
          </div>
        </header>
        <?php if (trim((string) $p['note']) !== ''): ?><p class="proof__note">“<?= nl2br(e($p['note'])) ?>”</p><?php endif; ?>

        <details class="proof__pics">
          <summary>
            <span class="proof__strip">
              <?php foreach (array_slice($pImgs, 0, 10) as $im): ?><img src="<?= e(media_url($im, 'thumb')) ?>" alt="" loading="lazy"><?php endforeach; ?>
              <?php if (count($pImgs) > 10): ?><span class="proof__more">+<?= count($pImgs) - 10 ?></span><?php endif; ?>
            </span>
            <span class="proof__toggle">ดูทั้งหมด</span>
          </summary>
          <div class="proof__grid">
            <?php foreach ($pImgs as $k => $im): ?>
              <figure><img src="<?= e(media_url($im, 'thumb')) ?>" alt="" loading="lazy"><figcaption>#<?= e((string) $nums[$k]) ?> · <?= e($names[$k]) ?></figcaption></figure>
            <?php endforeach; ?>
          </div>
        </details>

        <div class="proof__acts">
          <button type="button" class="btn btn--sm btn--primary" data-copy="<?= e(implode(', ', $bases)) ?>" data-copy-msg="คัดลอกแล้ว — ใน Lightroom ไปที่ Library Filter › Text › Filename › Contains แล้ววาง">คัดลอกชื่อไฟล์ (Lightroom)</button>
          <button type="button" class="btn btn--sm" data-copy="<?= e(implode("\n", $names)) ?>" data-copy-msg="คัดลอกรายชื่อไฟล์แล้ว (บรรทัดละไฟล์)">คัดลอกแบบบรรทัดละไฟล์</button>
          <a class="btn btn--sm btn--ghost" href="<?= e(url('admin/proof_export.php?id=' . (int) $p['id'])) ?>">ดาวน์โหลด CSV</a>
          <span class="proof__sp"></span>
          <form method="post" action="<?= e(url('admin/action.php')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="proof_lock"><input type="hidden" name="id" value="<?= (int) $album['id'] ?>"><input type="hidden" name="proof" value="<?= (int) $p['id'] ?>">
            <button class="btn btn--sm btn--ghost" type="submit" title="<?= $locked ? 'ให้ลูกค้าแก้ไขรายการได้อีกครั้ง' : 'ล็อกรายการ ลูกค้าจะแก้ไขไม่ได้' ?>"><?= $locked ? 'ปลดล็อกให้แก้ไข' : 'ล็อกรายการ' ?></button>
          </form>
          <form method="post" action="<?= e(url('admin/action.php')) ?>" data-confirm="ลบรายการคัดรูปของ “<?= e($p['name']) ?>” ?">
            <?= csrf_field() ?><input type="hidden" name="action" value="proof_delete"><input type="hidden" name="id" value="<?= (int) $album['id'] ?>"><input type="hidden" name="proof" value="<?= (int) $p['id'] ?>">
            <button class="btn btn--sm btn--danger-ghost" type="submit">ลบ</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
  <div class="panel__head">
    <div>
      <p class="step">จัดการภาพ</p>
      <h2 class="panel__title">ภาพในอัลบั้ม <span class="muted">(<?= $counts['total'] ?>)</span></h2>
    </div>
    <p class="hint">“ซ่อน” = ลูกค้าจะไม่เห็นภาพนั้น · “ตั้งเป็นปก” = ใช้เป็นภาพหน้าปก (ถ้าไม่ได้อัปโหลดภาพปก)</p>
  </div>
  <?php if (!$images): ?>
    <div class="empty empty--sm"><p class="muted">ยังไม่พบภาพ — ตรวจสอบว่าลิงก์แชร์เป็น “ทุกคนที่มีลิงก์” แล้วกด “ดึงภาพใหม่”</p></div>
  <?php else: ?>
    <div class="a-grid" data-album="<?= (int) $album['id'] ?>">
      <?php foreach ($images as $img): ?>
        <figure class="a-tile <?= (int) $img['hidden'] ? 'is-hidden' : '' ?> <?= $coverImg && (int) $coverImg['id'] === (int) $img['id'] ? 'is-cover' : '' ?>" data-id="<?= (int) $img['id'] ?>">
          <img src="<?= e(media_url($img, 'thumb')) ?>" alt="<?= e($img['name']) ?>" loading="lazy">
          <span class="a-tile__cover">ปก</span>
          <figcaption>
            <span class="a-tile__name" title="<?= e(trim(($img['section'] ?? '') . ' / ' . $img['name'], ' /')) ?>"><?php if (($img['section'] ?? '') !== ''): ?><b class="a-tile__sec"><?= e($img['section']) ?></b> <?php endif; ?><?= e($img['name'] ?: '#' . $img['sort']) ?></span>
            <span class="a-tile__btns">
              <button type="button" data-act="toggle_hidden"><?= (int) $img['hidden'] ? 'แสดง' : 'ซ่อน' ?></button>
              <button type="button" data-act="set_cover">ตั้งเป็นปก</button>
            </span>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="loading" id="loading" hidden><div class="loading__box"><span class="spinner"></span><p id="loading-text">กำลังทำงาน…</p></div></div>
<?php admin_footer();
