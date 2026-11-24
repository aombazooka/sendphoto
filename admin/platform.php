<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/admin_layout.php';
require_superadmin();

$errors = [];
$num = fn(string $k, int $min, int $max) => (string) max($min, min($max, (int) ($_POST[$k] ?? 0)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $section = (string) ($_POST['section'] ?? '');

    if ($section === 'general') {
        set_setting('platform_name', mb_substr(trim((string) ($_POST['platform_name'] ?? '')), 0, 190) ?: 'Photo Delivery');
        set_setting('allow_signup', !empty($_POST['allow_signup']) ? '1' : '0');
        set_setting('trial_days', $num('trial_days', 0, 365));
        set_setting('trial_max_albums', $num('trial_max_albums', 0, 100000));
        set_setting('grace_days', $num('grace_days', 0, 365));
        set_setting('trial_features', implode(',', parse_features(implode(',', array_keys((array) ($_POST['trial_feat'] ?? []))))));
        set_setting('google_api_key', trim((string) ($_POST['google_api_key'] ?? '')));
        flash('บันทึกการตั้งค่าระบบแล้ว');
        redirect(url('admin/platform.php'));
    }

    if ($section === 'payment') {
        $pp = preg_replace('/\D/', '', (string) ($_POST['promptpay_id'] ?? ''));
        if ($pp !== '' && !promptpay_payload($pp)) {
            $errors[] = 'หมายเลขพร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก เลขบัตรประชาชน/เลขผู้เสียภาษี 13 หลัก หรือ e-Wallet 15 หลัก';
        } else {
            set_setting('promptpay_id', $pp);
            set_setting('payee_name', mb_substr(trim((string) ($_POST['payee_name'] ?? '')), 0, 190));
            set_setting('bank_info', mb_substr(trim((string) ($_POST['bank_info'] ?? '')), 0, 1000));
            try {
                $old = setting('payment_qr_file');
                if ($new = save_upload('qr', 5)) {
                    set_setting('payment_qr_file', $new);
                    delete_upload($old);
                } elseif (!empty($_POST['remove_qr'])) {
                    set_setting('payment_qr_file', null);
                    delete_upload($old);
                }
                flash('บันทึกบัญชีรับเงินแล้ว');
                redirect(url('admin/platform.php'));
            } catch (RuntimeException $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }

    if ($section === 'plan') {
        $pid   = (int) ($_POST['id'] ?? 0);
        $name  = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $days  = (int) ($_POST['days'] ?? 0);
        $price = round((float) ($_POST['price'] ?? 0), 2);
        $feats = implode(',', parse_features(implode(',', array_keys((array) ($_POST['feat'] ?? [])))));
        $vals  = [$name, $days, $price, max(0, (int) ($_POST['max_albums'] ?? 0)), $feats,
                  mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255), !empty($_POST['is_active']) ? 1 : 0, (int) ($_POST['sort'] ?? 0)];
        if (!empty($_POST['delete']) && $pid) {
            db()->prepare('UPDATE users SET plan_id = NULL WHERE plan_id = ?')->execute([$pid]);
            db()->prepare('DELETE FROM plans WHERE id = ?')->execute([$pid]);
            flash('ลบแพ็กเกจแล้ว');
            redirect(url('admin/platform.php#plans'));
        }
        if ($name === '') $errors[] = 'กรุณาตั้งชื่อแพ็กเกจ';
        elseif ($days < 1 || $days > 3650) $errors[] = 'จำนวนวันต้องอยู่ระหว่าง 1–3650';
        elseif ($price <= 0) $errors[] = 'ราคาต้องมากกว่า 0';
        else {
            if ($pid) {
                db()->prepare('UPDATE plans SET name=?, days=?, price=?, max_albums=?, features=?, description=?, is_active=?, sort=? WHERE id=?')->execute([...$vals, $pid]);
            } else {
                db()->prepare('INSERT INTO plans(name, days, price, max_albums, features, description, is_active, sort) VALUES(?,?,?,?,?,?,?,?)')->execute($vals);
            }
            flash($pid ? 'บันทึกแพ็กเกจแล้ว' : 'เพิ่มแพ็กเกจแล้ว');
            redirect(url('admin/platform.php#plans'));
        }
    }

    if ($section === 'cache') {
        $n = 0;
        foreach (glob(CACHE_DIR . '/*/*') ?: [] as $f) { if (@unlink($f)) $n++; }
        flash('ล้างแคชภาพแล้ว (' . $n . ' ไฟล์)');
        redirect(url('admin/platform.php'));
    }
}

$plans = all_plans(false);
$qr    = upload_url(setting('payment_qr_file'));
admin_header('ตั้งค่าระบบ', 'platform');
?>
<section class="a-head">
  <div>
    <p class="crumb"><?= e(platform_name()) ?> <span>/</span> Platform</p>
    <h1 class="display">ตั้งค่าระบบ</h1>
    <p class="muted">การสมัครสมาชิก ทดลองใช้ แพ็กเกจ และบัญชีรับเงิน</p>
  </div>
</section>

<?php if ($errors): ?><div class="notice notice--err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

<div class="layout layout--even">
  <form method="post" class="panel form">
    <?= csrf_field() ?><input type="hidden" name="section" value="general">
    <header class="panel__hd"><p class="step">Sign-up</p><h2 class="panel__title">การสมัครและทดลองใช้</h2></header>
    <label class="field"><span class="field__lbl">ชื่อระบบ <em>แสดงที่หน้าเข้าสู่ระบบ / สมัครสมาชิก</em></span><input type="text" name="platform_name" value="<?= e(platform_name()) ?>" maxlength="190"></label>
    <label class="switch"><input type="checkbox" name="allow_signup" <?= setting('allow_signup', '1') === '1' ? 'checked' : '' ?>><i></i> เปิดรับสมัครสมาชิกใหม่</label>
    <div class="row">
      <label class="field"><span class="field__lbl">ทดลองใช้ฟรี (วัน)</span><input type="number" name="trial_days" min="0" max="365" value="<?= e(setting('trial_days', '7')) ?>"></label>
      <label class="field"><span class="field__lbl">แกลเลอรีสูงสุดช่วงทดลอง <em>0 = ไม่จำกัด</em></span><input type="number" name="trial_max_albums" min="0" value="<?= e(setting('trial_max_albums', '0')) ?>"></label>
    </div>
    <div class="field"><span class="field__lbl">ฟีเจอร์ช่วงทดลองใช้</span>
      <div class="featchk">
        <?php $tf = parse_features(setting('trial_features', 'proof,sell,face')); foreach (FEATURES as $fk => [$fi, $fn]): ?>
          <label class="check-inline"><input type="checkbox" name="trial_feat[<?= $fk ?>]" value="1" <?= in_array($fk, $tf, true) ? 'checked' : '' ?>> <?= e($fi . ' ' . $fn) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <label class="field"><span class="field__lbl">ลิงก์ลูกค้ายังเปิดได้หลังสมาชิกหมดอายุ (วัน)</span>
      <input type="number" name="grace_days" min="0" max="365" value="<?= e(setting('grace_days', '7')) ?>">
      <small class="hint">ระหว่างนี้ร้านสร้าง/แก้ไขแกลเลอรีไม่ได้ แต่ลูกค้าของร้านยังโหลดภาพได้ · 0 = ปิดลิงก์ทันทีที่หมดอายุ</small>
    </label>
    <label class="field"><span class="field__lbl">Google Drive API Key กลาง <em>ใช้กับร้านที่ไม่ได้ใส่คีย์เอง</em></span><input type="text" name="google_api_key" value="<?= e(setting('google_api_key', '')) ?>" class="mono" placeholder="AIza…" autocomplete="off"></label>
    <div class="form__foot"><button class="btn btn--primary" type="submit">บันทึก</button></div>
  </form>

  <form method="post" enctype="multipart/form-data" class="panel form">
    <?= csrf_field() ?><input type="hidden" name="section" value="payment">
    <header class="panel__hd"><p class="step">Payment</p><h2 class="panel__title">บัญชีรับเงิน</h2>
      <p class="hint">สมาชิกจะเห็นข้อมูลนี้ในหน้า “แพ็กเกจ” พร้อม QR ที่ใส่ยอดเงินตามแพ็กเกจให้อัตโนมัติ</p></header>
    <label class="field"><span class="field__lbl">หมายเลขพร้อมเพย์ <em>เบอร์มือถือ หรือเลขบัตรประชาชน/เลขผู้เสียภาษี</em></span><input type="text" name="promptpay_id" value="<?= e(setting('promptpay_id', '')) ?>" class="mono" inputmode="numeric" placeholder="08xxxxxxxx"></label>
    <label class="field"><span class="field__lbl">ชื่อบัญชี</span><input type="text" name="payee_name" value="<?= e(setting('payee_name', '')) ?>" maxlength="190" placeholder="เช่น บจก. โมเมนต์ สตูดิโอ"></label>
    <label class="field"><span class="field__lbl">บัญชีธนาคาร / ข้อความเพิ่มเติม</span><textarea name="bank_info" rows="3" placeholder="เช่น กสิกรไทย 123-4-56789-0 ชื่อบัญชี …"><?= e(setting('bank_info', '')) ?></textarea></label>
    <div class="field">
      <span class="field__lbl">รูป QR รับเงิน <em>ไม่บังคับ — ใช้แทน QR พร้อมเพย์อัตโนมัติ (เช่น QR ร้านค้าของธนาคาร)</em></span>
      <div class="logo-row">
        <span class="logo-row__pv"><?php if ($qr): ?><img src="<?= e($qr) ?>" alt=""><?php else: ?>—<?php endif; ?></span>
        <input type="file" name="qr" accept="image/png,image/jpeg,image/webp">
      </div>
      <?php if ($qr): ?><label class="check-inline"><input type="checkbox" name="remove_qr" value="1"> ลบรูป QR (กลับไปใช้ QR พร้อมเพย์อัตโนมัติ)</label><?php endif; ?>
    </div>
    <div class="form__foot"><button class="btn btn--primary" type="submit">บันทึก</button></div>
  </form>
</div>

<section class="panel" id="plans">
  <header class="panel__hd"><p class="step">Plans</p><h2 class="panel__title">แพ็กเกจสมาชิก</h2>
    <p class="hint">ปิด “แสดง” เพื่อซ่อนแพ็กเกจจากหน้าสมัคร โดยสมาชิกเดิมยังใช้ต่อได้ · แกลเลอรีสูงสุด 0 = ไม่จำกัด · ฟีเจอร์: ♡ คัดรูป · ฿ ลายน้ำ+ขายรูป · ☺ ค้นหาด้วยใบหน้า</p></header>
  <div class="plan-edit plan-edit--hd"><span>ชื่อ</span><span>วัน</span><span>ราคา (บาท)</span><span>แกลเลอรีสูงสุด</span><span>ฟีเจอร์</span><span>คำอธิบาย</span><span>ลำดับ</span><span>แสดง</span><span></span></div>
  <?php foreach ([...$plans, null] as $p): ?>
    <form method="post" class="plan-edit">
      <?= csrf_field() ?><input type="hidden" name="section" value="plan"><input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
      <input type="text" name="name" value="<?= e($p['name'] ?? '') ?>" placeholder="<?= $p ? '' : 'แพ็กเกจใหม่ เช่น 6 เดือน' ?>" required aria-label="ชื่อแพ็กเกจ">
      <input type="number" name="days" min="1" max="3650" value="<?= e((string) ($p['days'] ?? '')) ?>" placeholder="180" required aria-label="จำนวนวัน">
      <input type="number" name="price" min="1" step="0.01" value="<?= e($p ? (string) (float) $p['price'] : '') ?>" placeholder="1590" required aria-label="ราคา">
      <input type="number" name="max_albums" min="0" value="<?= e((string) ($p['max_albums'] ?? '0')) ?>" aria-label="แกลเลอรีสูงสุด">
      <span class="featchk featchk--row"><?php $pf = $p ? parse_features($p['features'] ?? '') : array_keys(FEATURES); foreach (FEATURES as $fk => [$fi, $fn]): ?><label title="<?= e($fn) ?>"><input type="checkbox" name="feat[<?= $fk ?>]" value="1" <?= in_array($fk, $pf, true) ? 'checked' : '' ?>><span><?= e($fi) ?></span></label><?php endforeach; ?></span>
      <input type="text" name="description" value="<?= e($p['description'] ?? '') ?>" maxlength="255" aria-label="คำอธิบาย">
      <input type="number" name="sort" value="<?= e((string) ($p['sort'] ?? count($plans) + 1)) ?>" aria-label="ลำดับ">
      <label class="check-inline"><input type="checkbox" name="is_active" value="1" <?= !$p || (int) $p['is_active'] ? 'checked' : '' ?>><span class="sr-only">แสดง</span></label>
      <span class="plan-edit__btns">
        <button class="btn btn--sm <?= $p ? '' : 'btn--primary' ?>" type="submit"><?= $p ? 'บันทึก' : '＋ เพิ่ม' ?></button>
        <?php if ($p): ?><button class="btn btn--sm btn--danger-ghost" type="submit" name="delete" value="1" formnovalidate onclick="return confirm('ลบแพ็กเกจ “<?= e($p['name']) ?>”? สมาชิกที่ใช้อยู่จะไม่ถูกตัดวันใช้งาน')">ลบ</button><?php endif; ?>
      </span>
    </form>
  <?php endforeach; ?>
</section>

<form method="post" class="panel form" data-confirm="ล้างแคชภาพตัวอย่างทั้งหมดของทุกร้าน? (ภาพจะถูกโหลดจาก Google ใหม่เมื่อมีคนเปิด)">
  <?= csrf_field() ?><input type="hidden" name="section" value="cache">
  <header class="panel__hd"><p class="step">Cache</p><h2 class="panel__title">แคชภาพตัวอย่าง</h2></header>
  <p class="hint">ระบบเก็บภาพย่อไว้บนเซิร์ฟเวอร์เพื่อให้เปิดเร็ว ล้างได้ถ้าแก้ไขภาพต้นฉบับแล้วภาพยังไม่อัปเดต</p>
  <p class="hint">งานตั้งเวลา (cron.php): <?= setting('cron_last_run') ? 'ทำงานล่าสุด ' . e(thai_date(setting('cron_last_run'), true, true)) . ' น.' : '<b>ยังไม่เคยทำงาน</b> — ตั้งให้รันทุกชั่วโมง (ดูวิธีใน README)' ?></p>
  <div class="form__foot"><button class="btn btn--ghost" type="submit">ล้างแคช</button></div>
</form>
<?php admin_footer();
