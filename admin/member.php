<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/admin_layout.php';
$admin = require_superadmin();

$id = (int) ($_GET['id'] ?? 0);
$u  = $id ? find_user($id) : null;
if ($id && !$u) {
    flash('ไม่พบสมาชิก', 'err');
    redirect(url('admin/members.php'));
}
$isSelf = $u && (int) $u['id'] === (int) $admin['id'];
$plans  = all_plans(false);
$errors = [];
$back   = fn() => redirect(url('admin/member.php?id=' . $id));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $section = (string) ($_POST['section'] ?? '');

    /* ---------- add a member by hand ---------- */
    if ($section === 'create' && !$u) {
        $shop  = trim((string) ($_POST['shop_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass  = (string) ($_POST['password'] ?? '');
        $plan  = find_plan((int) ($_POST['plan_id'] ?? 0));
        $days  = (int) ($_POST['days'] ?? 0);
        if ($shop === '') $errors[] = 'กรุณาใส่ชื่อร้าน';
        if (!valid_email($email)) $errors[] = 'กรุณาใส่อีเมลให้ถูกต้อง';
        elseif (find_user_by_email($email)) $errors[] = 'อีเมลนี้มีบัญชีอยู่แล้ว';
        if ($p = password_problem($pass)) $errors[] = $p;
        if ($days < 1 || $days > 3650) $errors[] = 'จำนวนวันใช้งานต้องอยู่ระหว่าง 1–3650 วัน';
        if (!$errors) {
            $trial = !$plan;
            $newId = create_user($email, $pass, mb_substr($shop, 0, 190), 'member', date('Y-m-d H:i:s', strtotime('+' . $days . ' days')), $trial);
            if ($plan) db()->prepare('UPDATE users SET plan_id = ? WHERE id = ?')->execute([$plan['id'], $newId]);
            log_membership($newId, 'created_by_admin', [
                'detail' => ($plan ? 'แพ็กเกจ ' . $plan['name'] : 'ทดลองใช้') . ' · ' . $days . ' วัน',
                'after' => find_user($newId)['expires_at'], 'actor' => $admin,
            ]);
            flash('เพิ่มสมาชิก “' . $shop . '” แล้ว · แจ้งอีเมลและรหัสผ่านให้สมาชิกเพื่อเข้าสู่ระบบ');
            redirect(url('admin/member.php?id=' . $newId));
        }
    }

    if ($u) {
        switch ($section) {
            case 'profile':
                $shop  = trim((string) ($_POST['shop_name'] ?? ''));
                $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
                $status = ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';
                if ($shop === '') $errors[] = 'กรุณาใส่ชื่อร้าน';
                if ($email !== $u['email'] && (!valid_email($email) || find_user_by_email($email))) $errors[] = 'อีเมลไม่ถูกต้อง หรือถูกใช้กับบัญชีอื่นแล้ว';
                if ($isSelf && $status === 'suspended') $errors[] = 'ระงับบัญชีของตัวเองไม่ได้';
                if (!$errors) {
                    db()->prepare('UPDATE users SET shop_name = ?, email = ?, status = ?, note = ? WHERE id = ?')
                        ->execute([mb_substr($shop, 0, 190), $email, $status, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000), $id]);
                    if ($status !== $u['status']) {
                        log_membership($id, $status === 'suspended' ? 'suspended' : 'unsuspended', ['actor' => $admin]);
                    }
                    if ($email !== $u['email']) {
                        log_membership($id, 'email_changed', ['detail' => $u['email'] . ' → ' . $email, 'actor' => $admin]);
                    }
                    flash($status === 'suspended' && $u['status'] !== 'suspended'
                        ? 'ระงับบัญชีแล้ว · สมาชิกจะเข้าสู่ระบบไม่ได้และลิงก์ลูกค้าทั้งหมดของร้านนี้ถูกปิด'
                        : 'บันทึกข้อมูลสมาชิกแล้ว');
                    $back();
                }
                break;

            case 'membership':
                if ($u['role'] === 'admin') break;
                $mode = (string) ($_POST['mode'] ?? 'add');
                $plan = find_plan((int) ($_POST['plan_id'] ?? 0));
                if ($mode === 'add') {
                    $days = (int) ($_POST['days'] ?? 0);
                    if ($days < 1 || $days > 3650) { $errors[] = 'จำนวนวันต้องอยู่ระหว่าง 1–3650'; break; }
                    $exp = extended_expiry($u, $days);
                } elseif ($mode === 'date') {
                    $ts = strtotime((string) ($_POST['expires_at'] ?? ''));
                    if (!$ts) { $errors[] = 'กรุณาระบุวันหมดอายุ'; break; }
                    $exp = date('Y-m-d H:i:s', $ts);
                } else {
                    $exp = null; // never expires
                }
                $trial = !empty($_POST['on_trial']) ? 1 : 0;
                db()->prepare('UPDATE users SET expires_at = ?, plan_id = ?, on_trial = ? WHERE id = ?')
                    ->execute([$exp, $plan['id'] ?? null, $trial, $id]);

                // Timeline: what actually changed.
                $notes = [];
                $type  = 'admin_update';
                if ($exp !== $u['expires_at']) {
                    if ($mode === 'add')       { $type = 'admin_extend'; $notes[] = '+' . $days . ' วัน'; }
                    elseif ($mode === 'date')  { $type = 'admin_set_date'; }
                    else                       { $type = 'admin_unlimited'; }
                }
                $oldPlan = $u['plan_id'] ? find_plan((int) $u['plan_id']) : null;
                if ((int) ($plan['id'] ?? 0) !== (int) $u['plan_id']) {
                    $notes[] = 'แพ็กเกจ: ' . ($oldPlan['name'] ?? '—') . ' → ' . ($plan['name'] ?? '—');
                }
                if ($trial !== (int) $u['on_trial']) $notes[] = $trial ? 'กลับเป็นช่วงทดลองใช้' : 'สิ้นสุดช่วงทดลองใช้';
                if ($exp !== $u['expires_at'] || $notes) {
                    log_membership($id, $type, ['detail' => implode(' · ', $notes), 'before' => $u['expires_at'], 'after' => $exp, 'actor' => $admin]);
                }
                flash('อัปเดตสมาชิกภาพแล้ว · ใช้งานได้ถึง ' . ($exp ? thai_date($exp, false, true) . ' น.' : 'ไม่มีกำหนด'));
                $back();

            case 'password':
                $pass = (string) ($_POST['password'] ?? '');
                if ($p = password_problem($pass)) { $errors[] = $p; break; }
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                if (!$isSelf) log_membership($id, 'password_reset', ['actor' => $admin]);
                if ($isSelf) login_user(find_user($id));
                flash('ตั้งรหัสผ่านใหม่แล้ว · สมาชิกจะถูกออกจากระบบทุกเครื่อง');
                $back();

            case 'delete':
                if ($isSelf || $u['role'] === 'admin') { $errors[] = 'ลบบัญชีผู้ดูแลระบบไม่ได้'; break; }
                if (trim((string) ($_POST['confirm_email'] ?? '')) !== $u['email']) { $errors[] = 'พิมพ์อีเมลให้ตรงเพื่อยืนยันการลบ'; break; }
                delete_user($id);
                flash('ลบบัญชี “' . $u['shop_name'] . '” พร้อมแกลเลอรีทั้งหมดแล้ว');
                redirect(url('admin/members.php'));
        }
    }
}

if ($u) {
    $st = db()->prepare('SELECT id, user_id, title, token, is_active, expires_at, views, created_at FROM albums WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $st->execute([$id]);
    $albums = $st->fetchAll();
    $state = account_state($u);
}

admin_header($u ? $u['shop_name'] : 'เพิ่มสมาชิก', 'members');
?>
<section class="a-head">
  <div>
    <p class="crumb"><a href="<?= e(url('admin/members.php')) ?>">สมาชิก</a> <span>/</span> <?= $u ? 'Member' : 'New' ?></p>
    <h1 class="display"><?= $u ? e($u['shop_name'] ?: $u['email']) : 'เพิ่มสมาชิก' ?></h1>
    <?php if ($u): ?><p class="muted"><?= e($u['email']) ?> · สมัครเมื่อ <?= e(thai_date($u['created_at'], true)) ?><?= $u['last_login_at'] ? ' · เข้าใช้ล่าสุด ' . e(thai_date($u['last_login_at'], true, true)) : '' ?></p><?php endif; ?>
  </div>
  <?php if ($u): ?><span class="pill pill--<?= e($state) ?> pill--lg"><?= e(account_state_label($state)) ?></span><?php endif; ?>
</section>

<?php if ($errors): ?><div class="notice notice--err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

<?php if (!$u): ?>
  <form method="post" class="panel form narrow">
    <?= csrf_field() ?><input type="hidden" name="section" value="create">
    <label class="field"><span class="field__lbl">ชื่อร้าน / สตูดิโอ</span><input type="text" name="shop_name" required maxlength="190" value="<?= e($_POST['shop_name'] ?? '') ?>"></label>
    <label class="field"><span class="field__lbl">อีเมล</span><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label class="field"><span class="field__lbl">รหัสผ่านเริ่มต้น <em>อย่างน้อย 8 ตัวอักษร</em></span><input type="text" name="password" required minlength="8" autocomplete="off" class="mono"></label>
    <div class="row">
      <label class="field"><span class="field__lbl">แพ็กเกจ</span>
        <select name="plan_id"><option value="0">ทดลองใช้</option><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
      </label>
      <label class="field"><span class="field__lbl">ใช้งานได้ (วัน)</span><input type="number" name="days" min="1" max="3650" value="<?= e($_POST['days'] ?? setting('trial_days', '7')) ?>" required></label>
    </div>
    <div class="form__foot"><button class="btn btn--primary" type="submit">เพิ่มสมาชิก</button></div>
  </form>
<?php else: ?>

<div class="layout layout--even">
  <form method="post" class="panel form">
    <?= csrf_field() ?><input type="hidden" name="section" value="profile">
    <header class="panel__hd"><p class="step">Profile</p><h2 class="panel__title">ข้อมูลร้าน</h2></header>
    <label class="field"><span class="field__lbl">ชื่อร้าน</span><input type="text" name="shop_name" value="<?= e($u['shop_name']) ?>" required maxlength="190"></label>
    <label class="field"><span class="field__lbl">อีเมล</span><input type="text" name="email" value="<?= e($u['email']) ?>" required></label>
    <?php if (!$isSelf): ?>
    <div class="field">
      <span class="field__lbl">สถานะบัญชี</span>
      <div class="seg" role="radiogroup">
        <label><input type="radio" name="status" value="active" <?= $u['status'] === 'active' ? 'checked' : '' ?>><span>ใช้งาน</span></label>
        <label><input type="radio" name="status" value="suspended" <?= $u['status'] === 'suspended' ? 'checked' : '' ?>><span>ระงับ</span></label>
      </div>
      <small class="hint">ระงับ = เข้าสู่ระบบไม่ได้ และลิงก์ลูกค้าของร้านนี้จะปิดทันที</small>
    </div>
    <?php endif; ?>
    <label class="field"><span class="field__lbl">บันทึกภายใน <em>สมาชิกไม่เห็น</em></span><textarea name="note" rows="2"><?= e((string) $u['note']) ?></textarea></label>
    <div class="form__foot"><button class="btn btn--primary" type="submit">บันทึก</button></div>
  </form>

  <div class="side">
    <?php if ($u['role'] !== 'admin'): ?>
    <form method="post" class="panel form">
      <?= csrf_field() ?><input type="hidden" name="section" value="membership">
      <header class="panel__hd"><p class="step">Membership</p><h2 class="panel__title">สมาชิกภาพ</h2>
        <p class="hint">ปัจจุบัน: <?= $u['expires_at'] ? 'ใช้งานได้ถึง ' . e(thai_date($u['expires_at'], false, true)) . ' น.' : 'ไม่มีวันหมดอายุ' ?></p></header>
      <label class="field"><span class="field__lbl">แพ็กเกจ</span>
        <select name="plan_id"><option value="0">— ไม่ระบุ —</option><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $u['plan_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
      </label>
      <div class="field">
        <span class="field__lbl">ปรับวันใช้งาน</span>
        <label class="check-inline"><input type="radio" name="mode" value="add" checked> เพิ่ม <input type="number" name="days" min="1" max="3650" value="30" class="inline-num"> วัน (ต่อจากวันหมดอายุเดิม)</label>
        <label class="check-inline"><input type="radio" name="mode" value="date"> กำหนดวันหมดอายุ <input type="datetime-local" name="expires_at" value="<?= $u['expires_at'] ? e(date('Y-m-d\TH:i', strtotime($u['expires_at']))) : '' ?>"></label>
        <label class="check-inline"><input type="radio" name="mode" value="never"> ไม่มีวันหมดอายุ</label>
      </div>
      <label class="check-inline"><input type="checkbox" name="on_trial" value="1" <?= (int) $u['on_trial'] ? 'checked' : '' ?>> ยังอยู่ในช่วงทดลองใช้</label>
      <div class="form__foot"><button class="btn btn--primary" type="submit">อัปเดตสมาชิกภาพ</button></div>
    </form>
    <?php endif; ?>

    <form method="post" class="panel form">
      <?= csrf_field() ?><input type="hidden" name="section" value="password">
      <header class="panel__hd"><p class="step">Security</p><h2 class="panel__title">ตั้งรหัสผ่านใหม่</h2></header>
      <label class="field"><span class="field__lbl">รหัสผ่านใหม่ <em>อย่างน้อย 8 ตัวอักษร</em></span><input type="text" name="password" required minlength="8" autocomplete="off" class="mono"></label>
      <div class="form__foot"><button class="btn" type="submit">ตั้งรหัสผ่าน</button></div>
    </form>
  </div>
</div>

<div class="layout layout--even">
  <section class="panel">
    <header class="panel__hd"><p class="step">Galleries</p><h2 class="panel__title">แกลเลอรีของร้าน <span class="muted">(<?= count_user_albums($id) ?>)</span></h2></header>
    <?php if (!$albums): ?><p class="hint">ยังไม่มีแกลเลอรี</p><?php else: ?>
      <ul class="recent">
        <?php foreach ($albums as $a): [$k, $lbl] = album_status($a); ?>
          <li>
            <a href="<?= e(share_url($a['token'])) ?>" target="_blank" rel="noopener" class="recent__t"><?= e($a['title']) ?> ↗</a>
            <span class="recent__u"><?= (int) $a['views'] ?> เข้าชม · สร้าง <?= e(thai_date($a['created_at'], true)) ?></span>
            <span class="recent__s recent__s--<?= $k ?>"><?= e($lbl) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <section class="panel">
    <header class="panel__hd"><p class="step">History</p><h2 class="panel__title">ประวัติสมาชิก</h2>
      <p class="hint">การสมัคร ชำระเงิน ต่ออายุ ระงับ และการแก้ไขโดยผู้ดูแลระบบ</p></header>
    <?php render_timeline(membership_timeline($u), true); ?>
  </section>
</div>

<?php if (!$isSelf && $u['role'] !== 'admin'): ?>
<form method="post" class="panel form danger-zone" data-confirm="ลบบัญชี “<?= e($u['shop_name']) ?>” พร้อมแกลเลอรี ภาพ และสลิปทั้งหมด? ย้อนกลับไม่ได้">
  <?= csrf_field() ?><input type="hidden" name="section" value="delete">
  <header class="panel__hd"><p class="step">Danger zone</p><h2 class="panel__title">ลบบัญชีถาวร</h2>
    <p class="hint">ลบบัญชี แกลเลอรีทั้งหมด (ลิงก์ลูกค้าใช้ไม่ได้ทันที) และประวัติการชำระเงิน · ไฟล์ต้นฉบับใน Google Drive / Immich ไม่ถูกลบ · ถ้าแค่ต้องการหยุดใช้งาน ให้ใช้ “ระงับ” แทน</p></header>
  <label class="field"><span class="field__lbl">พิมพ์อีเมล <b><?= e($u['email']) ?></b> เพื่อยืนยัน</span><input type="text" name="confirm_email" required autocomplete="off"></label>
  <div class="form__foot"><button class="btn btn--danger-ghost" type="submit">ลบบัญชีถาวร</button></div>
</form>
<?php endif; ?>
<?php endif; ?>
<?php admin_footer();
