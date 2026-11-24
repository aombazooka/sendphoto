<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/admin_layout.php';
$me  = require_login();
$uid = (int) $me['id'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $section = (string) ($_POST['section'] ?? '');

    if ($section === 'general') {
        $shop = trim((string) ($_POST['shop_name'] ?? ''));
        if ($shop === '' || mb_strlen($shop) > 190) {
            $errors[] = 'กรุณาตั้งชื่อร้าน / สตูดิโอ';
        } else {
            db()->prepare('UPDATE users SET shop_name = ? WHERE id = ?')->execute([$shop, $uid]);
            set_user_setting($uid, 'contact', mb_substr(trim((string) ($_POST['contact'] ?? '')), 0, 300));
            set_user_setting($uid, 'google_api_key', trim((string) ($_POST['google_api_key'] ?? '')));
            try {
                $old = user_setting($uid, 'logo_file');
                if ($new = save_upload('logo', 5)) {
                    set_user_setting($uid, 'logo_file', $new);
                    delete_upload($old);
                } elseif (!empty($_POST['remove_logo'])) {
                    set_user_setting($uid, 'logo_file', null);
                    delete_upload($old);
                }
                flash('บันทึกข้อมูลร้านเรียบร้อย');
                redirect(url('admin/settings.php'));
            } catch (RuntimeException $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($section === 'selling') {
        $pp = preg_replace('/\D/', '', (string) ($_POST['pay_promptpay'] ?? ''));
        if ($pp !== '' && !promptpay_payload($pp)) {
            $errors[] = 'หมายเลขพร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลขบัตรประชาชน/เลขผู้เสียภาษี 13 หลัก';
        } else {
            set_user_setting($uid, 'pay_promptpay', $pp);
            set_user_setting($uid, 'pay_name', mb_substr(trim((string) ($_POST['pay_name'] ?? '')), 0, 120));
            set_user_setting($uid, 'pay_note', mb_substr(trim((string) ($_POST['pay_note'] ?? '')), 0, 500));
            set_user_setting($uid, 'wm_text', mb_substr(trim((string) ($_POST['wm_text'] ?? '')), 0, 60));
            set_user_setting($uid, 'wm_logo', !empty($_POST['wm_logo']) ? '1' : '0');
            flash('บันทึกการตั้งค่าขายรูปแล้ว');
            redirect(url('admin/settings.php#selling'));
        }
    }

    if ($section === 'notify') {
        foreach (array_keys(NOTIFY_EVENTS) as $ev) {
            set_user_setting($uid, 'notify_ev_' . $ev, isset($_POST['ev'][$ev]) ? '1' : '0');
        }
        // LINE Official Account (Messaging API)
        $lt = trim((string) ($_POST['line_token'] ?? ''));
        $lu = trim((string) ($_POST['line_to'] ?? ''));
        if (!empty($_POST['line_clear'])) {
            set_user_setting($uid, 'notify_line_token', null);
            set_user_setting($uid, 'notify_line_to', null);
        } else {
            if ($lt !== '') {
                if (strlen($lt) < 40 || preg_match('/\s/', $lt)) $errors[] = 'Channel access token ของ LINE ดูไม่ถูกต้อง (คัดลอกมาไม่ครบ?)';
                else set_user_setting($uid, 'notify_line_token', $lt);
            }
            if ($lu !== '') {
                if (!preg_match('/^U[0-9a-f]{32}$/', $lu)) $errors[] = 'LINE User ID ต้องขึ้นต้นด้วย U ตามด้วยตัวอักษร 32 ตัว';
                else set_user_setting($uid, 'notify_line_to', $lu);
            }
        }
        // Telegram
        $tt = trim((string) ($_POST['tg_token'] ?? ''));
        $tc = trim((string) ($_POST['tg_chat'] ?? ''));
        if (!empty($_POST['tg_clear'])) {
            set_user_setting($uid, 'notify_tg_token', null);
            set_user_setting($uid, 'notify_tg_chat', null);
        } else {
            if ($tt !== '') {
                if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $tt)) $errors[] = 'Bot token ของ Telegram ไม่ถูกต้อง (รูปแบบ 123456:ABC…)';
                else set_user_setting($uid, 'notify_tg_token', $tt);
            }
            if ($tc !== '') {
                if (!preg_match('/^(-?\d{3,}|@[A-Za-z0-9_]{4,})$/', $tc)) $errors[] = 'Telegram Chat ID ไม่ถูกต้อง';
                else set_user_setting($uid, 'notify_tg_chat', $tc);
            }
        }
        if (!$errors) {
            flash('บันทึกการตั้งค่าการแจ้งเตือนแล้ว — กด “ส่งข้อความทดสอบ” เพื่อตรวจว่าเชื่อมต่อสำเร็จ');
            redirect(url('admin/settings.php#notify'));
        }
    }

    if ($section === 'notify_test') {
        $r = notify_deliver($uid, "✦ ทดสอบการแจ้งเตือนจาก " . $me['shop_name'] . "\nถ้าเห็นข้อความนี้ แปลว่าเชื่อมต่อสำเร็จแล้ว");
        if ($r === '') flash('ยังไม่ได้ตั้งค่า LINE หรือ Telegram ให้ครบ', 'warn');
        else flash('ผลการส่งข้อความทดสอบ: ' . $r, str_contains($r, '✗') ? 'warn' : 'ok');
        redirect(url('admin/settings.php#notify'));
    }

    if ($section === 'tg_detect') {
        $tt = (string) user_setting($uid, 'notify_tg_token', '');
        $chat = $tt !== '' ? telegram_find_chat($tt) : null;
        if ($tt === '') {
            flash('กรุณาใส่และบันทึก Bot token ก่อน', 'warn');
        } elseif (!$chat) {
            flash('ยังไม่พบข้อความ — เปิดแชตกับบอทใน Telegram กด Start หรือพิมพ์อะไรก็ได้ 1 ข้อความ แล้วกดค้นหาอีกครั้ง', 'warn');
        } else {
            set_user_setting($uid, 'notify_tg_chat', $chat['id']);
            flash('พบแชต “' . $chat['name'] . '” (' . $chat['id'] . ') และบันทึกแล้ว');
        }
        redirect(url('admin/settings.php#notify'));
    }

    if ($section === 'account') {
        $cur   = (string) ($_POST['current'] ?? '');
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $new   = (string) ($_POST['new'] ?? '');
        if (!password_verify($cur, $me['password_hash'])) {
            $errors[] = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
        } elseif (!valid_email($email)) {
            $errors[] = 'กรุณาใส่อีเมลให้ถูกต้อง';
        } elseif ($email !== $me['email'] && find_user_by_email($email)) {
            $errors[] = 'อีเมลนี้ถูกใช้กับบัญชีอื่นแล้ว';
        } elseif ($new !== '' && ($p = password_problem($new, (string) ($_POST['confirm'] ?? '')))) {
            $errors[] = $p;
        } else {
            db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $uid]);
            if ($email !== $me['email']) log_membership($uid, 'email_changed', ['detail' => $me['email'] . ' → ' . $email, 'actor' => $me]);
            if ($new !== '') {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
                login_user(find_user($uid)); // keep this session, sign out the others
            }
            flash($new !== '' ? 'บันทึกอีเมลและรหัสผ่านใหม่เรียบร้อย' : 'บันทึกอีเมลเรียบร้อย');
            redirect(url('admin/settings.php'));
        }
    }
}

$logo = upload_url(user_setting($uid, 'logo_file'));
admin_header('ตั้งค่าร้าน', 'settings');
?>
<section class="a-head">
  <div>
    <p class="crumb"><?= e(site_name()) ?> <span>/</span> Settings</p>
    <h1 class="display">ตั้งค่าร้าน</h1>
  </div>
</section>

<?php if (!str_contains($me['email'], '@')): ?>
  <div class="notice notice--warn">บัญชีนี้ย้ายมาจากระบบเดิม และเข้าสู่ระบบด้วยชื่อ “<?= e($me['email']) ?>” — แนะนำให้เปลี่ยนเป็นอีเมลจริงในช่อง “บัญชีผู้ใช้”</div>
<?php endif; ?>
<?php if ($errors): ?><div class="notice notice--err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

<div class="layout layout--even">
  <form method="post" enctype="multipart/form-data" class="panel form">
    <?= csrf_field() ?><input type="hidden" name="section" value="general">
    <header class="panel__hd"><p class="step">Studio</p><h2 class="panel__title">ข้อมูลร้าน</h2></header>

    <label class="field">
      <span class="field__lbl">ชื่อร้าน / สตูดิโอ <em>แสดงบนหน้าแกลเลอรีของลูกค้า</em></span>
      <input type="text" name="shop_name" value="<?= e($me['shop_name']) ?>" required maxlength="190">
    </label>

    <div class="field">
      <span class="field__lbl">โลโก้หลัก <em>แสดงมุมซ้ายบนของภาพปกทุกแกลเลอรี</em></span>
      <div class="logo-row">
        <span class="logo-row__pv"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?>—<?php endif; ?></span>
        <input type="file" name="logo" accept="image/png,image/svg+xml,image/webp,image/jpeg">
      </div>
      <small class="hint">แนะนำ PNG/SVG พื้นหลังโปร่งใส สีขาวหรือสีอ่อน (เพราะวางบนภาพปก)</small>
      <?php if ($logo): ?><label class="check-inline"><input type="checkbox" name="remove_logo" value="1"> ลบโลโก้หลัก</label><?php endif; ?>
    </div>

    <label class="field">
      <span class="field__lbl">ช่องทางติดต่อ <em>แสดงท้ายหน้าแกลเลอรี</em></span>
      <input type="text" name="contact" value="<?= e(user_setting($uid, 'contact', '')) ?>" maxlength="300" placeholder="เช่น LINE: @moment · โทร 08x-xxx-xxxx">
    </label>

    <label class="field">
      <span class="field__lbl">Google Drive API Key <em>ไม่บังคับ</em></span>
      <input type="text" name="google_api_key" value="<?= e(user_setting($uid, 'google_api_key', '')) ?>" class="mono" placeholder="AIza…" autocomplete="off">
      <small class="hint">
        ไม่ใส่ก็ใช้งานได้<?= setting('google_api_key') ? ' (ระบบมีคีย์กลางให้แล้ว)' : '' ?> แต่ถ้าใส่จะดึงภาพได้เร็วและเสถียรกว่า รองรับโฟลเดอร์ที่มีภาพจำนวนมาก และแสดงขนาดไฟล์ (MB) ได้
        <br>วิธีสร้าง: Google Cloud Console → สร้างโปรเจกต์ → เปิดใช้ “Google Drive API” → Credentials → Create API key (จำกัดให้ใช้แค่ Drive API)
      </small>
    </label>

    <div class="form__foot"><button class="btn btn--primary" type="submit">บันทึก</button></div>
  </form>

  <div class="side">
    <form method="post" class="panel form">
      <?= csrf_field() ?><input type="hidden" name="section" value="account">
      <header class="panel__hd"><p class="step">Account</p><h2 class="panel__title">บัญชีผู้ใช้</h2></header>
      <label class="field"><span class="field__lbl">อีเมล <em>ใช้เข้าสู่ระบบ</em></span><input type="email" name="email" value="<?= e(str_contains($me['email'], '@') ? $me['email'] : '') ?>" required autocomplete="username"></label>
      <label class="field"><span class="field__lbl">รหัสผ่านใหม่ <em>เว้นว่างถ้าไม่เปลี่ยน</em></span><input type="password" name="new" minlength="8" autocomplete="new-password"></label>
      <label class="field"><span class="field__lbl">ยืนยันรหัสผ่านใหม่</span><input type="password" name="confirm" autocomplete="new-password"></label>
      <label class="field"><span class="field__lbl">รหัสผ่านปัจจุบัน <em class="req">จำเป็น</em></span><input type="password" name="current" required autocomplete="current-password"></label>
      <div class="form__foot"><button class="btn" type="submit">บันทึกบัญชี</button></div>
    </form>
  </div>
</div>

<?php
$lineTok = (string) user_setting($uid, 'notify_line_token', '');
$lineTo  = (string) user_setting($uid, 'notify_line_to', '');
$tgTok   = (string) user_setting($uid, 'notify_tg_token', '');
$tgChat  = (string) user_setting($uid, 'notify_tg_chat', '');
$lineOn  = $lineTok !== '' && $lineTo !== '';
$tgOn    = $tgTok !== '' && $tgChat !== '';
?>
<section class="panel" id="selling">
  <header class="panel__hd">
    <p class="step">Selling</p>
    <h2 class="panel__title">ขายรูป · ลายน้ำ · รับเงินจากลูกค้า</h2>
    <p class="hint">ใช้กับแกลเลอรีที่เปิด “ขายภาพ” — ลูกค้าสแกน QR พร้อมเพย์ (ใส่ยอดให้อัตโนมัติ) แล้วแนบสลิป คุณกดอนุมัติ ลูกค้าโหลดต้นฉบับได้ทันที</p>
  </header>
  <?php if (!has_feature($me, 'sell')): ?>
    <?= feature_locked_note('sell') ?>
  <?php else: ?>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="section" value="selling">
    <div class="row">
      <label class="field"><span class="field__lbl">พร้อมเพย์ของร้าน <em>เบอร์มือถือ หรือเลขบัตร/ผู้เสียภาษี</em></span>
        <input type="text" name="pay_promptpay" value="<?= e(user_setting($uid, 'pay_promptpay', '')) ?>" class="mono" inputmode="numeric" placeholder="08xxxxxxxx"></label>
      <label class="field"><span class="field__lbl">ชื่อบัญชี <em>ให้ลูกค้าตรวจก่อนโอน</em></span>
        <input type="text" name="pay_name" value="<?= e(user_setting($uid, 'pay_name', '')) ?>" maxlength="120" placeholder="เช่น นาย ก. ใจดี"></label>
    </div>
    <label class="field"><span class="field__lbl">บัญชีธนาคาร / ข้อความถึงลูกค้า <em>ไม่บังคับ</em></span>
      <textarea name="pay_note" rows="2" maxlength="500" placeholder="เช่น กสิกรไทย 123-4-56789-0 · โอนแล้วแนบสลิป ช่างจะอนุมัติภายใน 1 ชม."><?= e(user_setting($uid, 'pay_note', '')) ?></textarea></label>
    <div class="row">
      <label class="field"><span class="field__lbl">ข้อความลายน้ำ <em>เว้นว่าง = ชื่อร้าน</em></span>
        <input type="text" name="wm_text" value="<?= e(user_setting($uid, 'wm_text', '')) ?>" maxlength="60" placeholder="<?= e($me['shop_name']) ?>"></label>
      <div class="field"><span class="field__lbl">ตัวเลือกลายน้ำ</span>
        <label class="switch"><input type="checkbox" name="wm_logo" value="1" <?= user_setting($uid, 'wm_logo', '0') === '1' ? 'checked' : '' ?>><i></i> ใส่โลโก้กลางภาพด้วย</label>
        <small class="hint">แนะนำข้อความภาษาอังกฤษสั้น ๆ จะคมชัดที่สุด<?= watermark_font() ? '' : ' · <b>ไม่พบฟอนต์ในเซิร์ฟเวอร์ ระบบจะใช้ตัวอักษรพื้นฐาน (เฉพาะภาษาอังกฤษ)</b>' ?></small>
      </div>
    </div>
    <div class="form__foot"><button class="btn btn--primary" type="submit">บันทึก</button></div>
  </form>
  <?php endif; ?>
</section>

<section class="panel" id="notify">
  <header class="panel__hd">
    <p class="step">Notifications</p>
    <h2 class="panel__title">การแจ้งเตือน</h2>
    <p class="hint">รับแจ้งเตือนในระบบเสมอ และส่งเข้า LINE หรือ Telegram บนมือถือได้ด้วย</p>
  </header>

  <?php web_notify_controls(); ?>

  <form method="post" class="form" id="notify-form">
    <?= csrf_field() ?><input type="hidden" name="section" value="notify">

    <div class="field">
      <span class="field__lbl">แจ้งเตือนเมื่อ</span>
      <div class="evs">
        <?php foreach (NOTIFY_EVENTS as $k => [$ic, $label, $desc]): ?>
          <label class="ev"><input type="checkbox" name="ev[<?= $k ?>]" value="1" <?= notify_enabled($uid, $k) ? 'checked' : '' ?>>
            <span class="ev__box"><span class="ev__ic"><?= e($ic) ?></span><span><b><?= e($label) ?></b><small><?= e($desc) ?></small></span></span></label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="chans">
      <div class="chan <?= $lineOn ? 'is-on' : '' ?>">
        <div class="chan__hd">
          <span class="chan__logo chan__logo--line">LINE</span>
          <div><b>LINE Official Account</b><small><?= $lineOn ? 'เชื่อมต่อแล้ว · ส่งถึง ' . e(substr($lineTo, 0, 6)) . '…' : 'ยังไม่ได้เชื่อมต่อ' ?></small></div>
        </div>
        <label class="field">
          <span class="field__lbl">Channel access token</span>
          <input type="password" name="line_token" autocomplete="off" class="mono" placeholder="<?= $lineTok ? e(notify_mask($lineTok)) . ' (เว้นว่าง = ใช้ค่าเดิม)' : 'วาง token ยาว ๆ จาก LINE Developers' ?>">
        </label>
        <label class="field">
          <span class="field__lbl">Your user ID</span>
          <input type="text" name="line_to" autocomplete="off" class="mono" value="<?= e($lineTo) ?>" placeholder="U1234…(33 ตัวอักษร)">
        </label>
        <details class="howto">
          <summary>วิธีตั้งค่า LINE (ประมาณ 5 นาที)</summary>
          <ol>
            <li>เข้า <b>developers.line.biz</b> แล้วล็อกอินด้วยบัญชี LINE ของคุณ</li>
            <li>สร้าง Provider แล้วกด <b>Create a Messaging API channel</b> (จะได้ LINE OA ของร้านไปด้วย)</li>
            <li>แท็บ <b>Basic settings</b> → เลื่อนลงไปคัดลอก <b>Your user ID</b> (ขึ้นต้นด้วย U)</li>
            <li>แท็บ <b>Messaging API</b> → สแกน QR เพิ่ม OA เป็นเพื่อน → ล่างสุดกด <b>Issue</b> ที่ Channel access token แล้วคัดลอก</li>
            <li>วางทั้งสองค่าที่นี่ กดบันทึก แล้วกด “ส่งข้อความทดสอบ”</li>
          </ol>
          <p class="hint">ข้อความจะส่งถึงคุณคนเดียว (ไม่ใช่ broadcast ถึงลูกค้า) · LINE OA มีโควตาข้อความฟรีต่อเดือน ตรวจสอบได้ในหน้า LINE Official Account Manager</p>
        </details>
        <?php if ($lineTok || $lineTo): ?><label class="check-inline"><input type="checkbox" name="line_clear" value="1"> ยกเลิกการเชื่อมต่อ LINE</label><?php endif; ?>
      </div>

      <div class="chan <?= $tgOn ? 'is-on' : '' ?>">
        <div class="chan__hd">
          <span class="chan__logo chan__logo--tg">✈</span>
          <div><b>Telegram</b><small><?= $tgOn ? 'เชื่อมต่อแล้ว · แชต ' . e($tgChat) : 'ยังไม่ได้เชื่อมต่อ · ฟรี ไม่จำกัดข้อความ' ?></small></div>
        </div>
        <label class="field">
          <span class="field__lbl">Bot token</span>
          <input type="password" name="tg_token" autocomplete="off" class="mono" placeholder="<?= $tgTok ? e(notify_mask($tgTok)) . ' (เว้นว่าง = ใช้ค่าเดิม)' : '123456789:AA…' ?>">
        </label>
        <label class="field">
          <span class="field__lbl">Chat ID</span>
          <div class="inline-btn">
            <input type="text" name="tg_chat" autocomplete="off" class="mono" value="<?= e($tgChat) ?>" placeholder="กด “ค้นหาอัตโนมัติ” ได้">
            <button type="submit" class="btn btn--sm" form="tg-detect" <?= $tgTok ? '' : 'disabled title="บันทึก Bot token ก่อน"' ?>>ค้นหาอัตโนมัติ</button>
          </div>
        </label>
        <details class="howto">
          <summary>วิธีตั้งค่า Telegram (ประมาณ 2 นาที)</summary>
          <ol>
            <li>ใน Telegram ค้นหา <b>@BotFather</b> → พิมพ์ <b>/newbot</b> → ตั้งชื่อบอท → จะได้ <b>Bot token</b></li>
            <li>วาง token ที่นี่แล้วกดบันทึก</li>
            <li>เปิดแชตกับบอทที่เพิ่งสร้าง กด <b>Start</b> แล้วกลับมากด <b>ค้นหาอัตโนมัติ</b></li>
          </ol>
        </details>
        <?php if ($tgTok || $tgChat): ?><label class="check-inline"><input type="checkbox" name="tg_clear" value="1"> ยกเลิกการเชื่อมต่อ Telegram</label><?php endif; ?>
      </div>
    </div>

    <div class="form__foot">
      <button class="btn btn--primary" type="submit">บันทึกการแจ้งเตือน</button>
      <button class="btn btn--ghost" type="submit" form="notify-test" <?= $lineOn || $tgOn ? '' : 'disabled' ?>>ส่งข้อความทดสอบ</button>
    </div>
  </form>
  <form method="post" id="notify-test"><?= csrf_field() ?><input type="hidden" name="section" value="notify_test"></form>
  <form method="post" id="tg-detect"><?= csrf_field() ?><input type="hidden" name="section" value="tg_detect"></form>
</section>
<?php admin_footer();
