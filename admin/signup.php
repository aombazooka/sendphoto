<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth_layout.php';
start_session();

if (needs_setup()) redirect(url('admin/login.php'));
if (current_user()) redirect(url('admin/'));

$open      = setting('allow_signup', '1') === '1';
$trialDays = max(0, (int) setting('trial_days', '7'));
$error     = '';

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $shop  = trim((string) ($_POST['shop_name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');

    if (!empty($_POST['website'])) {               // honeypot for bots
        $error = 'สมัครไม่สำเร็จ';
    } elseif ($shop === '' || mb_strlen($shop) > 190) {
        $error = 'กรุณาตั้งชื่อร้าน / สตูดิโอ';
    } elseif (!valid_email($email)) {
        $error = 'กรุณาใส่อีเมลให้ถูกต้อง';
    } elseif ($p = password_problem($pass, (string) ($_POST['confirm'] ?? ''))) {
        $error = $p;
    } elseif (find_user_by_email($email)) {
        auth_throttle();
        $error = 'อีเมลนี้ถูกใช้สมัครแล้ว กรุณาเข้าสู่ระบบ';
    } else {
        $until = date('Y-m-d H:i:s', strtotime('+' . $trialDays . ' days'));
        try {
            $uid = create_user($email, $pass, $shop, 'member', $until, true);
        } catch (PDOException $ex) {
            if ($ex->getCode() !== '23000') throw $ex;
            $uid = 0;
            $error = 'อีเมลนี้ถูกใช้สมัครแล้ว กรุณาเข้าสู่ระบบ';
        }
        if ($uid) {
            $nu = find_user($uid);
            log_membership($uid, 'trial_started', ['detail' => 'สมัครสมาชิก · ทดลองใช้ฟรี ' . $trialDays . ' วัน', 'after' => $until, 'actor' => $nu]);
            login_user($nu);
            flash('สมัครสมาชิกเรียบร้อย! ทดลองใช้ฟรีถึง ' . thai_date($until, false, true) . ' น. เริ่มสร้างแกลเลอรีแรกได้เลย');
            redirect(url('admin/'));
        }
    }
}

auth_header('สมัครสมาชิก');
?>
    <h1 class="display">สมัครสมาชิก</h1>
    <?php if (!$open): ?>
      <p class="muted">ขณะนี้ปิดรับสมัครสมาชิกใหม่ชั่วคราว</p>
    <?php else: ?>
      <p class="muted">ทดลองใช้ฟรี <?= $trialDays ?> วัน ไม่ต้องใช้บัตรเครดิต<br>ต่อจากนั้นเลือกแพ็กเกจรายเดือนหรือรายปีได้</p>

      <?php if ($error): ?><div class="notice notice--err"><?= e($error) ?></div><?php endif; ?>

      <form method="post" class="form">
        <?= csrf_field() ?>
        <label class="field">
          <span>ชื่อร้าน / สตูดิโอ</span>
          <input type="text" name="shop_name" required autofocus maxlength="190" placeholder="เช่น Moment Studio" value="<?= e($_POST['shop_name'] ?? '') ?>">
        </label>
        <label class="field">
          <span>อีเมล <small class="muted">ใช้เข้าสู่ระบบ</small></span>
          <input type="email" name="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>">
        </label>
        <label class="field">
          <span>รหัสผ่าน <small class="muted">อย่างน้อย 8 ตัวอักษร</small></span>
          <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </label>
        <label class="field">
          <span>ยืนยันรหัสผ่าน</span>
          <input type="password" name="confirm" required minlength="8" autocomplete="new-password">
        </label>
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="hp" aria-hidden="true">
        <button class="btn btn--primary btn--block" type="submit">สมัครและเริ่มทดลองใช้</button>
      </form>
    <?php endif; ?>
    <p class="auth__alt">มีบัญชีแล้ว? <a href="<?= e(url('admin/login.php')) ?>">เข้าสู่ระบบ</a></p>
<?php auth_footer();
