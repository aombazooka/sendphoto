<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/auth_layout.php';
start_session();

$setup = needs_setup();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');

    if ($setup) {
        // First run: create the central admin account.
        $name = trim((string) ($_POST['site_name'] ?? ''));
        if (!valid_email($email)) {
            $error = 'กรุณาใส่อีเมลให้ถูกต้อง';
        } elseif ($p = password_problem($pass, (string) ($_POST['confirm'] ?? ''))) {
            $error = $p;
        } else {
            $name = $name !== '' ? mb_substr($name, 0, 190) : 'Photo Delivery';
            set_setting('platform_name', $name);
            $uid = create_user($email, $pass, $name, 'admin');
            log_membership($uid, 'admin_account', ['detail' => 'บัญชีผู้ดูแลระบบ']);
            login_user(find_user($uid));
            flash('ตั้งค่าเรียบร้อย ยินดีต้อนรับ! คุณคือผู้ดูแลระบบกลาง ตั้งราคาแพ็กเกจและบัญชีรับเงินได้ที่เมนู “ระบบ”');
            redirect(url('admin/platform.php'));
        }
    } else {
        auth_throttle();
        $u = $email !== '' ? find_user_by_email($email) : null;
        if ($u && password_verify($pass, $u['password_hash'])) {
            if ($u['status'] === 'suspended') {
                $error = 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
            } else {
                if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                    $u = find_user((int) $u['id']);
                }
                login_user($u);
                redirect(url('admin/'));
            }
        } else {
            $error = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}

if (!$setup && current_user()) redirect(url('admin/'));
$allowSignup = setting('allow_signup', '1') === '1';

auth_header($setup ? 'ตั้งค่าครั้งแรก' : 'เข้าสู่ระบบ');
?>
    <h1 class="display"><?= $setup ? 'ตั้งค่าครั้งแรก' : 'ยินดีต้อนรับกลับ' ?></h1>
    <p class="muted"><?= $setup ? 'สร้างบัญชีผู้ดูแลระบบกลาง สำหรับจัดการสมาชิกทุกร้าน' : 'เข้าสู่ระบบเพื่อจัดการแกลเลอรีส่งงาน' ?></p>

    <?php if ($error): ?><div class="notice notice--err"><?= e($error) ?></div><?php endif; ?>

    <form method="post" class="form">
      <?= csrf_field() ?>
      <?php if ($setup): ?>
        <label class="field">
          <span>ชื่อระบบ / แบรนด์ของคุณ</span>
          <input type="text" name="site_name" placeholder="เช่น Moment Studio" value="<?= e($_POST['site_name'] ?? '') ?>" maxlength="190">
        </label>
      <?php endif; ?>
      <label class="field">
        <span>อีเมล</span>
        <input type="<?= $setup ? 'email' : 'text' ?>" name="email" required autofocus autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>">
      </label>
      <label class="field">
        <span>รหัสผ่าน</span>
        <input type="password" name="password" required autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>">
      </label>
      <?php if ($setup): ?>
        <label class="field">
          <span>ยืนยันรหัสผ่าน</span>
          <input type="password" name="confirm" required autocomplete="new-password">
        </label>
      <?php endif; ?>
      <button class="btn btn--primary btn--block" type="submit"><?= $setup ? 'เริ่มใช้งาน' : 'เข้าสู่ระบบ' ?></button>
    </form>

    <?php if (!$setup && $allowSignup): ?>
      <p class="auth__alt">ยังไม่มีบัญชี? <a href="<?= e(url('admin/signup.php')) ?>">สมัครสมาชิก · ทดลองใช้ฟรี <?= (int) setting('trial_days', '7') ?> วัน</a></p>
    <?php endif; ?>
<?php auth_footer();
