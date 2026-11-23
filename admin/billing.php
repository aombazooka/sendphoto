<?php
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/admin_layout.php';
$me  = require_login();
$uid = (int) $me['id'];
if ($me['role'] === 'admin') redirect(url('admin/platform.php'));

$plans   = all_plans();
$pending = pending_payment_count($uid);
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $plan = find_plan((int) ($_POST['plan_id'] ?? 0));
    $raw  = trim((string) ($_POST['transfer_at'] ?? ''));
    $ts   = $raw !== '' ? strtotime($raw) : false;
    if ($me['status'] === 'suspended') $errors[] = 'บัญชีถูกระงับ กรุณาติดต่อผู้ดูแลระบบ';
    elseif ($pending) $errors[] = 'มีสลิปที่รอตรวจสอบอยู่แล้ว กรุณารอผู้ดูแลระบบตรวจสอบก่อน';
    elseif (!$plan || !(int) $plan['is_active']) $errors[] = 'กรุณาเลือกแพ็กเกจ';
    elseif (!$ts || $ts > time() + 600 || $ts < strtotime('-60 days')) $errors[] = 'กรุณาระบุวัน-เวลาที่โอนให้ถูกต้อง';

    if (!$errors) {
        try {
            $slip = save_slip('slip');
            db()->prepare('INSERT INTO payments(user_id, plan_id, plan_name, days, amount, slip_file, transfer_at, note) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([$uid, $plan['id'], $plan['name'], $plan['days'], $plan['price'], $slip, date('Y-m-d H:i:s', $ts),
                           mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500)]);
            log_membership($uid, 'payment_submitted', [
                'detail' => $plan['name'] . ' · ฿' . money($plan['price']),
                'payment_id' => (int) db()->lastInsertId(), 'actor' => $me,
            ]);
            flash('ส่งสลิปเรียบร้อย ผู้ดูแลระบบจะตรวจสอบและต่ออายุให้โดยเร็วที่สุด');
            redirect(url('admin/billing.php'));
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}

$state    = account_state($me);
$days     = account_days_left($me);
$curPlan  = $me['plan_id'] ? find_plan((int) $me['plan_id']) : null;
$ppId     = trim((string) setting('promptpay_id', ''));
$qrFile   = setting('payment_qr_file');
$bankText = trim((string) setting('bank_info', ''));
$payName  = trim((string) setting('payee_name', ''));
$selected = (int) ($_POST['plan_id'] ?? ($curPlan['id'] ?? ($plans[0]['id'] ?? 0)));
$limit    = album_limit($me);

admin_header('แพ็กเกจ', 'billing');
?>
<section class="a-head">
  <div>
    <p class="crumb"><?= e(site_name()) ?> <span>/</span> Membership</p>
    <h1 class="display">แพ็กเกจสมาชิก</h1>
    <p class="muted">เลือกแพ็กเกจ โอนเงิน แล้วแนบสลิป — ผู้ดูแลระบบจะตรวจสอบและต่ออายุให้</p>
  </div>
</section>

<?php if ($errors): ?><div class="notice notice--err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

<section class="stats stats--3">
  <div class="stat"><span class="stat__num"><span class="pill pill--<?= e($state) ?>"><?= e(account_state_label($state)) ?></span></span><span class="stat__lbl">สถานะ<?= $curPlan && $state !== 'trial' ? ' · ' . e($curPlan['name']) : '' ?></span></div>
  <div class="stat"><span class="stat__num"><?= $me['expires_at'] ? e(thai_date($me['expires_at'], true)) : 'ไม่มีกำหนด' ?></span><span class="stat__lbl"><?= $state === 'expired' ? 'หมดอายุเมื่อ' : 'ใช้งานได้ถึง' ?><?= $days !== null && $state !== 'expired' ? ' · เหลือ ' . $days . ' วัน' : '' ?></span></div>
  <div class="stat"><span class="stat__num"><?= count_user_albums($uid) ?><?= $limit ? ' <small>/ ' . $limit . '</small>' : '' ?></span><span class="stat__lbl">แกลเลอรี<?= $limit ? '' : ' (ไม่จำกัด)' ?></span></div>
</section>

<div class="layout">
  <form method="post" enctype="multipart/form-data" class="panel form" id="pay-form">
    <?= csrf_field() ?>
    <header class="panel__hd">
      <p class="step">01 / Choose a plan</p>
      <h2 class="panel__title">เลือกแพ็กเกจ</h2>
      <p class="hint">วันใช้งานจะต่อจากวันหมดอายุเดิม (ถ้ายังไม่หมด) ไม่เสียวันที่เหลืออยู่</p>
    </header>

    <?php if (!$plans): ?>
      <p class="muted">ยังไม่มีแพ็กเกจให้เลือก กรุณาติดต่อผู้ดูแลระบบ</p>
    <?php else: ?>
    <div class="plans">
      <?php foreach ($plans as $p): $payload = $ppId ? promptpay_payload($ppId, (float) $p['price']) : null; ?>
        <label class="plan">
          <input type="radio" name="plan_id" value="<?= (int) $p['id'] ?>" <?= $selected === (int) $p['id'] ? 'checked' : '' ?> data-qr="<?= e((string) $payload) ?>" data-amount="<?= e(money($p['price'])) ?>">
          <span class="plan__box">
            <span class="plan__name"><?= e($p['name']) ?></span>
            <span class="plan__price">฿<?= e(money($p['price'])) ?> <small>/ <?= e(plan_period_label($p)) ?></small></span>
            <span class="plan__desc"><?= e($p['description'] ?: $p['days'] . ' วัน') ?><?= (int) $p['max_albums'] ? ' · สูงสุด ' . (int) $p['max_albums'] . ' แกลเลอรี' : ' · แกลเลอรีไม่จำกัด' ?></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>

    <header class="panel__hd panel__hd--mid">
      <p class="step">02 / Transfer</p>
      <h2 class="panel__title">โอนเงิน</h2>
    </header>
    <div class="payto">
      <div class="payto__qr" id="qr">
        <?php if ($qrFile): ?>
          <img src="<?= e(upload_url($qrFile)) ?>" alt="QR สำหรับชำระเงิน">
        <?php elseif ($ppId): ?>
          <span class="muted small">กำลังสร้าง QR…</span>
        <?php else: ?>
          <span class="muted small">—</span>
        <?php endif; ?>
      </div>
      <div class="payto__txt">
        <p class="payto__amt">ยอดที่ต้องโอน <b id="pay-amount">฿—</b></p>
        <?php if ($ppId && !$qrFile): ?><p>สแกน QR PromptPay ด้วยแอปธนาคาร (ใส่ยอดเงินให้อัตโนมัติ)</p><?php endif; ?>
        <?php if ($ppId): ?><p>พร้อมเพย์: <b class="mono"><?= e($ppId) ?></b></p><?php endif; ?>
        <?php if ($payName): ?><p>ชื่อบัญชี: <b><?= e($payName) ?></b></p><?php endif; ?>
        <?php if ($bankText): ?><p class="payto__bank"><?= nl2br(e($bankText)) ?></p><?php endif; ?>
        <?php if (!$ppId && !$qrFile && !$bankText): ?><p class="muted">ผู้ดูแลระบบยังไม่ได้ตั้งค่าบัญชีรับเงิน</p><?php endif; ?>
      </div>
    </div>

    <header class="panel__hd panel__hd--mid">
      <p class="step">03 / Upload slip</p>
      <h2 class="panel__title">แนบสลิป</h2>
    </header>
    <?php if ($pending): ?>
      <div class="notice notice--warn">มีสลิปที่รอตรวจสอบอยู่ — ส่งเพิ่มได้หลังจากผู้ดูแลระบบตรวจสอบแล้ว</div>
    <?php elseif ($me['status'] === 'suspended'): ?>
      <div class="notice notice--err">บัญชีถูกระงับ กรุณาติดต่อผู้ดูแลระบบ</div>
    <?php else: ?>
      <div class="row">
        <label class="field">
          <span class="field__lbl">ไฟล์สลิป <em class="req">จำเป็น</em></span>
          <input type="file" name="slip" accept="image/jpeg,image/png,image/webp,image/heic,application/pdf" required>
        </label>
        <label class="field">
          <span class="field__lbl">วัน-เวลาที่โอน <em class="req">จำเป็น</em></span>
          <input type="datetime-local" name="transfer_at" required value="<?= e($_POST['transfer_at'] ?? date('Y-m-d\TH:i')) ?>" max="<?= e(date('Y-m-d\TH:i', time() + 600)) ?>">
        </label>
      </div>
      <label class="field">
        <span class="field__lbl">หมายเหตุ <em>ไม่บังคับ</em></span>
        <input type="text" name="note" maxlength="500" value="<?= e($_POST['note'] ?? '') ?>" placeholder="เช่น โอนจากบัญชีชื่อ …">
      </label>
      <div class="form__foot"><button class="btn btn--primary btn--wide" type="submit">ส่งสลิป</button></div>
    <?php endif; ?>
    <?php endif; ?>
  </form>

  <aside class="side">
    <section class="panel">
      <header class="panel__hd"><p class="step">History</p><h2 class="panel__title">ประวัติสมาชิก</h2>
        <p class="hint">การสมัคร ชำระเงิน และการต่ออายุทั้งหมดของร้านคุณ</p></header>
      <?php render_timeline(membership_timeline($me), false); ?>
    </section>
  </aside>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script>
(() => {
  const box = document.getElementById('qr'), amt = document.getElementById('pay-amount');
  const hasImage = !!box.querySelector('img');
  const draw = () => {
    const r = document.querySelector('input[name=plan_id]:checked');
    if (!r) return;
    amt.textContent = '฿' + r.dataset.amount;
    if (hasImage || !r.dataset.qr || typeof qrcode !== 'function') return;
    const q = qrcode(0, 'M'); q.addData(r.dataset.qr); q.make();
    box.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
  };
  document.querySelectorAll('input[name=plan_id]').forEach((r) => r.addEventListener('change', draw));
  window.addEventListener('load', draw);
  draw();
})();
</script>
<?php admin_footer();
