<?php
/**
 * Accounts (shops), membership plans, payments by bank transfer + slip.
 *
 *   users.role      admin  = central admin (manages every account, never expires)
 *                   member = a shop / photographer
 *   users.status    active | suspended
 *   users.expires_at  membership valid until (NULL = no expiry)
 *   users.on_trial  1 while in the free trial
 */
declare(strict_types=1);

define('SLIP_DIR', DATA_DIR . '/slips');

/* ---------------------------------------------------------------
 * Schema v2 – safe to run more than once
 * ------------------------------------------------------------- */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

function migrate_accounts(PDO $pdo): void
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email         VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        shop_name     VARCHAR(190) NOT NULL DEFAULT '',
        role          VARCHAR(16)  NOT NULL DEFAULT 'member',
        status        VARCHAR(16)  NOT NULL DEFAULT 'active',
        plan_id       INT UNSIGNED NULL,
        on_trial      TINYINT(1)   NOT NULL DEFAULT 0,
        expires_at    DATETIME NULL,
        note          TEXT NULL,
        last_login_at DATETIME NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_email (email),
        KEY idx_role (role, created_at)
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id INT UNSIGNED NOT NULL,
        `key`   VARCHAR(64) NOT NULL,
        `value` MEDIUMTEXT NULL,
        PRIMARY KEY (user_id, `key`),
        CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS plans (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120) NOT NULL,
        days        INT UNSIGNED NOT NULL,
        price       DECIMAL(10,2) NOT NULL DEFAULT 0,
        max_albums  INT UNSIGNED NOT NULL DEFAULT 0,
        description VARCHAR(255) NOT NULL DEFAULT '',
        is_active   TINYINT(1) NOT NULL DEFAULT 1,
        sort        INT NOT NULL DEFAULT 0
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        plan_id     INT UNSIGNED NULL,
        plan_name   VARCHAR(120) NOT NULL DEFAULT '',
        days        INT UNSIGNED NOT NULL DEFAULT 0,
        amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
        slip_file   VARCHAR(255) NULL,
        transfer_at DATETIME NULL,
        note        VARCHAR(500) NOT NULL DEFAULT '',
        status      VARCHAR(16) NOT NULL DEFAULT 'pending',
        admin_note  VARCHAR(500) NOT NULL DEFAULT '',
        reviewed_by INT UNSIGNED NULL,
        reviewed_at DATETIME NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status (status, created_at),
        KEY idx_user (user_id, created_at),
        CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opts");

    if (!column_exists($pdo, 'albums', 'user_id')) {
        $pdo->exec('ALTER TABLE albums ADD COLUMN user_id INT UNSIGNED NULL AFTER id, ADD KEY idx_user (user_id, created_at)');
    }

    $get = function (string $k) use ($pdo): ?string {
        $st = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    };
    $put = function (string $k, ?string $v) use ($pdo): void {
        $pdo->prepare('INSERT IGNORE INTO settings(`key`, `value`) VALUES(?, ?)')->execute([$k, $v]);
    };

    // Single-studio install → its owner becomes the central admin and keeps every album.
    $hasUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    if (!$hasUsers && ($oldPass = $get('admin_password'))) {
        $name = $get('site_name') ?: 'Photo Delivery';
        $pdo->prepare("INSERT INTO users(email, password_hash, shop_name, role) VALUES('admin', ?, ?, 'admin')")
            ->execute([$oldPass, $name]);
        $uid = (int) $pdo->lastInsertId();
        foreach (['contact', 'google_api_key', 'logo_file'] as $k) {
            if (($v = $get($k)) !== null && $v !== '') {
                $pdo->prepare('INSERT IGNORE INTO user_settings(user_id, `key`, `value`) VALUES(?, ?, ?)')->execute([$uid, $k, $v]);
            }
        }
        $put('platform_name', $name);
        $pdo->exec('DELETE FROM settings WHERE `key` IN (\'admin_password\', \'contact\', \'logo_file\')');
        $pdo->prepare('UPDATE albums SET user_id = ? WHERE user_id IS NULL')->execute([$uid]);
    }

    if ((int) $pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO plans(name, days, price, description, sort) VALUES
            ('รายเดือน', 30, 299, 'ใช้งานได้ 30 วัน', 1),
            ('รายปี', 365, 2990, 'ใช้งานได้ 365 วัน · ประหยัดกว่ารายเดือน', 2)");
    }
    $put('trial_days', '7');
    $put('grace_days', '7');
    $put('allow_signup', '1');
    $put('trial_max_albums', '0');

    migrate_membership_events($pdo, $opts);
}

/** Schema v3: membership timeline (+ rebuilt from existing data on first run). */
function migrate_membership_events(PDO $pdo, string $opts): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS membership_events (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id        INT UNSIGNED NOT NULL,
        type           VARCHAR(24)  NOT NULL,
        detail         VARCHAR(500) NOT NULL DEFAULT '',
        expires_before DATETIME NULL,
        expires_after  DATETIME NULL,
        payment_id     INT UNSIGNED NULL,
        actor_id       INT UNSIGNED NULL,
        actor_role     VARCHAR(16)  NOT NULL DEFAULT 'system',
        actor_label    VARCHAR(190) NOT NULL DEFAULT '',
        created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id, created_at),
        CONSTRAINT fk_me_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) $opts");

    if ((int) $pdo->query('SELECT COUNT(*) FROM membership_events')->fetchColumn() > 0) return;

    // Backfill: account creation + every slip already in the system.
    $pdo->exec("INSERT INTO membership_events(user_id, type, detail, expires_after, actor_role, created_at)
                SELECT id, IF(role = 'admin', 'admin_account', IF(on_trial = 1, 'trial_started', 'account_created')),
                       IF(role = 'admin', 'บัญชีผู้ดูแลระบบ', ''), IF(on_trial = 1, expires_at, NULL), 'system', created_at
                FROM users");
    $pdo->exec("INSERT INTO membership_events(user_id, type, detail, payment_id, actor_id, actor_role, created_at)
                SELECT user_id, 'payment_submitted', CONCAT(plan_name, ' · ฿', FORMAT(amount, 0)), id, user_id, 'self', created_at
                FROM payments");
    $pdo->exec("INSERT INTO membership_events(user_id, type, detail, payment_id, actor_id, actor_role, created_at)
                SELECT user_id, IF(status = 'approved', 'payment_approved', 'payment_rejected'),
                       IF(status = 'approved', CONCAT(plan_name, ' · +', days, ' วัน'), admin_note), id, reviewed_by, 'admin', reviewed_at
                FROM payments WHERE status <> 'pending' AND reviewed_at IS NOT NULL");
}

/* ---------------------------------------------------------------
 * Membership timeline
 * ------------------------------------------------------------- */

/**
 * Record something that changed an account's membership.
 * $o: detail, before, after (expiry strings / null), payment_id, actor (user array | null = system)
 */
function log_membership(int $userId, string $type, array $o = []): void
{
    $actor = $o['actor'] ?? null;
    $role  = 'system';
    if ($actor) $role = (int) $actor['id'] === $userId ? 'self' : ($actor['role'] === 'admin' ? 'admin' : 'self');
    db()->prepare('INSERT INTO membership_events(user_id, type, detail, expires_before, expires_after, payment_id, actor_id, actor_role, actor_label)
                   VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([
            $userId, $type, mb_substr((string) ($o['detail'] ?? ''), 0, 500),
            $o['before'] ?? null, $o['after'] ?? null, $o['payment_id'] ?? null,
            $actor['id'] ?? null, $role, $actor ? mb_substr((string) ($actor['email'] ?? ''), 0, 190) : '',
        ]);
}

/** Timeline newest first, with "expired" moments added where the membership lapsed. */
function membership_timeline(array $u, int $limit = 200): array
{
    $st = db()->prepare('SELECT e.*, p.status AS payment_status, p.slip_file
                         FROM membership_events e LEFT JOIN payments p ON p.id = e.payment_id
                         WHERE e.user_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT ' . (int) $limit);
    $st->execute([$u['id']]);
    $rows = $st->fetchAll();

    $virtual = [];
    $seen = [];
    $addExpiry = function (string $at, bool $trial) use (&$virtual, &$seen) {
        if (isset($seen[$at])) return;
        $seen[$at] = true;
        $virtual[] = ['type' => $trial ? 'trial_ended' : 'expired', 'created_at' => $at, 'virtual' => true,
                      'detail' => '', 'expires_before' => null, 'expires_after' => null, 'actor_role' => 'system',
                      'actor_label' => '', 'payment_id' => null, 'payment_status' => null];
    };
    // A renewal that happened after the old expiry means the membership had lapsed in between.
    // Walk oldest → newest to know whether the lapsed period was the free trial.
    $onTrial = false;
    foreach (array_reverse($rows) as $r) {
        if ($r['expires_before'] && strtotime($r['expires_before']) < strtotime($r['created_at'])) {
            $addExpiry($r['expires_before'], $onTrial);
        }
        if ($r['type'] === 'trial_started' || ($r['type'] === 'created_by_admin' && str_starts_with($r['detail'], 'ทดลองใช้'))) {
            $onTrial = true;
        } elseif (in_array($r['type'], ['payment_approved', 'admin_extend', 'admin_set_date', 'admin_unlimited', 'created_by_admin'], true)) {
            $onTrial = false;
        }
    }
    if ($u['role'] !== 'admin' && $u['expires_at'] && strtotime($u['expires_at']) <= time()) {
        $addExpiry($u['expires_at'], (bool) (int) $u['on_trial']);
    }
    $all = array_merge($rows, $virtual);
    usort($all, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    return $all;
}

/** [label, tone] for a timeline entry (tone: ok | warn | err | info). */
function membership_event_meta(string $type): array
{
    return [
        'trial_started'     => ['เริ่มทดลองใช้ฟรี', 'info'],
        'account_created'   => ['สร้างบัญชี', 'info'],
        'admin_account'     => ['สร้างบัญชี', 'info'],
        'created_by_admin'  => ['ผู้ดูแลระบบสร้างบัญชีให้', 'info'],
        'payment_submitted' => ['ส่งสลิปชำระเงิน', 'warn'],
        'payment_approved'  => ['ชำระเงินสำเร็จ · ต่ออายุแล้ว', 'ok'],
        'payment_rejected'  => ['สลิปไม่ได้รับการอนุมัติ', 'err'],
        'admin_extend'      => ['ผู้ดูแลระบบเพิ่มวันใช้งาน', 'ok'],
        'admin_set_date'    => ['ผู้ดูแลระบบกำหนดวันหมดอายุ', 'info'],
        'admin_unlimited'   => ['ตั้งเป็นไม่มีวันหมดอายุ', 'ok'],
        'admin_update'      => ['ผู้ดูแลระบบปรับสมาชิกภาพ', 'info'],
        'suspended'         => ['ระงับบัญชี', 'err'],
        'unsuspended'       => ['ยกเลิกการระงับบัญชี', 'ok'],
        'email_changed'     => ['เปลี่ยนอีเมลเข้าสู่ระบบ', 'info'],
        'password_reset'    => ['ผู้ดูแลระบบตั้งรหัสผ่านใหม่', 'info'],
        'trial_ended'       => ['ทดลองใช้หมดแล้ว', 'err'],
        'expired'           => ['สมาชิกหมดอายุ', 'err'],
    ][$type] ?? [$type, 'info'];
}

/* ---------------------------------------------------------------
 * Users
 * ------------------------------------------------------------- */
function find_user(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function find_user_by_email(string $email): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([mb_strtolower(trim($email))]);
    return $st->fetch() ?: null;
}

function needs_setup(): bool
{
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() === 0;
}

function valid_email(string $email): bool
{
    return mb_strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Returns an error message, or null when the password is acceptable. */
function password_problem(string $pass, ?string $confirm = null): ?string
{
    if (mb_strlen($pass) < 8) return 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    if ($confirm !== null && $pass !== $confirm) return 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
    return null;
}

function create_user(string $email, string $password, string $shopName, string $role = 'member', ?string $expiresAt = null, bool $trial = false): int
{
    db()->prepare('INSERT INTO users(email, password_hash, shop_name, role, on_trial, expires_at) VALUES(?,?,?,?,?,?)')
        ->execute([mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), $shopName, $role, $trial ? 1 : 0, $expiresAt]);
    return (int) db()->lastInsertId();
}

/** Delete an account with its galleries and every file it uploaded. */
function delete_user(int $id): void
{
    $st = db()->prepare('SELECT cover_file, logo_file FROM albums WHERE user_id = ?');
    $st->execute([$id]);
    foreach ($st as $a) { delete_upload($a['cover_file']); delete_upload($a['logo_file']); }
    delete_upload(user_setting($id, 'logo_file'));
    $st = db()->prepare('SELECT slip_file FROM payments WHERE user_id = ?');
    $st->execute([$id]);
    foreach ($st as $p) delete_slip($p['slip_file']);
    db()->prepare('DELETE FROM albums WHERE user_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}

/* ---------------------------------------------------------------
 * Per-shop settings (contact, logo, Google key)
 * ------------------------------------------------------------- */
function user_setting(int $userId, string $key, ?string $default = null): ?string
{
    global $__user_settings;
    if ($userId <= 0) return $default;
    if (!isset($__user_settings[$userId])) {
        $__user_settings[$userId] = [];
        $st = db()->prepare('SELECT `key`, `value` FROM user_settings WHERE user_id = ?');
        $st->execute([$userId]);
        foreach ($st as $r) $__user_settings[$userId][$r['key']] = $r['value'];
    }
    return $__user_settings[$userId][$key] ?? $default;
}

function set_user_setting(int $userId, string $key, ?string $value): void
{
    global $__user_settings;
    db()->prepare('INSERT INTO user_settings(user_id, `key`, `value`) VALUES(?,?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute([$userId, $key, $value]);
    unset($__user_settings[$userId]);
}

/** Shop's own Google Drive key, else the service-wide default key. */
function google_key_for($userId): ?string
{
    $k = trim((string) user_setting((int) $userId, 'google_api_key', ''));
    if ($k === '') $k = trim((string) setting('google_api_key', ''));
    return $k !== '' ? $k : null;
}

function album_owner(array $album): ?array
{
    static $cache = [];
    $uid = (int) ($album['user_id'] ?? 0);
    if (!$uid) return null;
    return $cache[$uid] ??= find_user($uid);
}

/* ---------------------------------------------------------------
 * Membership state
 * ------------------------------------------------------------- */

/** admin | suspended | trial | active | expired */
function account_state(array $u): string
{
    if ($u['role'] === 'admin') return 'admin';
    if ($u['status'] === 'suspended') return 'suspended';
    if ($u['expires_at'] !== null && strtotime($u['expires_at']) <= time()) return 'expired';
    return (int) $u['on_trial'] ? 'trial' : 'active';
}

function account_state_label(string $state): string
{
    return [
        'admin' => 'ผู้ดูแลระบบ', 'suspended' => 'ระงับ', 'trial' => 'ทดลองใช้',
        'active' => 'ใช้งานอยู่', 'expired' => 'หมดอายุ',
    ][$state] ?? $state;
}

/** Whole days left (rounded up), or null when the account never expires. */
function account_days_left(array $u): ?int
{
    if ($u['role'] === 'admin' || $u['expires_at'] === null) return null;
    return (int) max(0, ceil((strtotime($u['expires_at']) - time()) / 86400));
}

function account_can_write(array $u): bool
{
    return in_array(account_state($u), ['admin', 'trial', 'active'], true);
}

function grace_days(): int
{
    return max(0, (int) setting('grace_days', '7'));
}

/** When client links of an expired account stop working. */
function account_links_close_at(array $u): ?int
{
    if ($u['role'] === 'admin' || $u['expires_at'] === null) return null;
    return strtotime($u['expires_at']) + grace_days() * 86400;
}

/**
 * Are this account's client links allowed to open?
 * Expired accounts keep their links for the grace period so their clients
 * are not cut off the moment a renewal is late.
 */
function account_public_ok($userId): bool
{
    static $cache = [];
    $uid = (int) $userId;
    if ($uid <= 0) return true; // galleries created before accounts existed
    if (isset($cache[$uid])) return $cache[$uid];
    $u = find_user($uid);
    if (!$u || $u['status'] === 'suspended') return $cache[$uid] = false;
    $close = account_links_close_at($u);
    return $cache[$uid] = ($close === null || $close > time());
}

/** Max galleries for the account (0 = unlimited). */
function album_limit(array $u): int
{
    if ($u['role'] === 'admin') return 0;
    if ((int) $u['on_trial']) return max(0, (int) setting('trial_max_albums', '0'));
    if ($u['plan_id'] && ($p = find_plan((int) $u['plan_id']))) return (int) $p['max_albums'];
    return 0;
}

function count_user_albums(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM albums WHERE user_id = ?');
    $st->execute([$userId]);
    return (int) $st->fetchColumn();
}

/** Album owned by the signed-in account, or null. */
function find_my_album(int $id, array $u): ?array
{
    $st = db()->prepare('SELECT * FROM albums WHERE id = ? AND user_id = ?');
    $st->execute([$id, $u['id']]);
    return $st->fetch() ?: null;
}

/* ---------------------------------------------------------------
 * Plans
 * ------------------------------------------------------------- */
function all_plans(bool $activeOnly = true): array
{
    return db()->query('SELECT * FROM plans' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort, id')->fetchAll();
}

function find_plan(int $id): ?array
{
    static $cache = [];
    if (array_key_exists($id, $cache)) return $cache[$id];
    $st = db()->prepare('SELECT * FROM plans WHERE id = ?');
    $st->execute([$id]);
    return $cache[$id] = ($st->fetch() ?: null);
}

function money(float|string|int $n): string
{
    $n = (float) $n;
    return number_format($n, floor($n) == $n ? 0 : 2);
}

function plan_period_label(array $p): string
{
    $d = (int) $p['days'];
    if ($d % 365 === 0) return ($d / 365 === 1 ? '' : ($d / 365) . ' ') . 'ปี';
    if ($d % 30 === 0)  return ($d / 30 === 1 ? '' : ($d / 30) . ' ') . 'เดือน';
    return $d . ' วัน';
}

/* ---------------------------------------------------------------
 * Payments (bank transfer + slip, approved by the central admin)
 * ------------------------------------------------------------- */
function pending_payment_count(?int $userId = null): int
{
    if ($userId) {
        $st = db()->prepare("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND user_id = ?");
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }
    return (int) db()->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();
}

function find_payment(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** New expiry when $days are added: counted from the current expiry if still running, else from now. */
function extended_expiry(array $u, int $days): string
{
    $base = ($u['expires_at'] !== null && strtotime($u['expires_at']) > time()) ? strtotime($u['expires_at']) : time();
    return date('Y-m-d H:i:s', strtotime('+' . $days . ' days', $base));
}

function approve_payment(int $paymentId, int $adminId): ?string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND status = 'pending' FOR UPDATE");
        $st->execute([$paymentId]);
        $p = $st->fetch();
        if (!$p) { $pdo->rollBack(); return 'รายการนี้ถูกตรวจสอบไปแล้ว'; }
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
        $st->execute([$p['user_id']]);
        $u = $st->fetch();
        if (!$u) { $pdo->rollBack(); return 'ไม่พบบัญชีสมาชิก'; }

        $newExp = extended_expiry($u, (int) $p['days']);
        $pdo->prepare('UPDATE users SET expires_at = ?, on_trial = 0, plan_id = COALESCE(?, plan_id) WHERE id = ?')
            ->execute([$newExp, $p['plan_id'], $u['id']]);
        $pdo->prepare("UPDATE payments SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$adminId, $paymentId]);
        log_membership((int) $u['id'], 'payment_approved', [
            'detail' => $p['plan_name'] . ' · ฿' . money($p['amount']) . ' · +' . (int) $p['days'] . ' วัน',
            'before' => $u['expires_at'], 'after' => $newExp, 'payment_id' => $paymentId, 'actor' => find_user($adminId),
        ]);
        $pdo->commit();
        return null;
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
}

function reject_payment(int $paymentId, int $adminId, string $reason): ?string
{
    $st = db()->prepare("UPDATE payments SET status = 'rejected', admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
    $st->execute([mb_substr($reason, 0, 500), $adminId, $paymentId]);
    if (!$st->rowCount()) return 'รายการนี้ถูกตรวจสอบไปแล้ว';
    $p = find_payment($paymentId);
    log_membership((int) $p['user_id'], 'payment_rejected', [
        'detail' => $p['plan_name'] . ' · ฿' . money($p['amount']) . ' · เหตุผล: ' . $reason,
        'payment_id' => $paymentId, 'actor' => find_user($adminId),
    ]);
    return null;
}

function payment_status_label(string $s): string
{
    return ['pending' => 'รอตรวจสอบ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ'][$s] ?? $s;
}

/** Slips are personal data → kept outside the web root (data/ is "Require all denied"). */
function save_slip(string $field): string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('กรุณาแนบสลิปการโอนเงิน');
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('อัปโหลดสลิปไม่สำเร็จ (รหัส ' . $f['error'] . ')');
    if ($f['size'] > 8 * 1024 * 1024) throw new RuntimeException('ไฟล์สลิปใหญ่เกิน 8 MB');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('สลิปต้องเป็นไฟล์ JPG, PNG, WEBP, HEIC หรือ PDF');
    if (!is_dir(SLIP_DIR)) mkdir(SLIP_DIR, 0775, true);
    $name = 'slip-' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], SLIP_DIR . '/' . $name)) throw new RuntimeException('บันทึกสลิปไม่สำเร็จ');
    return $name;
}

function slip_path(?string $file): ?string
{
    if (!$file || !preg_match('/^slip-[a-f0-9]{24}\.[a-z]+$/', $file)) return null;
    $p = SLIP_DIR . '/' . $file;
    return is_file($p) ? $p : null;
}

function delete_slip(?string $file): void
{
    if ($p = slip_path($file)) @unlink($p);
}

/* ---------------------------------------------------------------
 * PromptPay QR payload (EMVCo / Thai QR) – rendered as a QR in the browser
 * ------------------------------------------------------------- */
function crc16_ccitt(string $s): int
{
    $crc = 0xFFFF;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $crc ^= ord($s[$i]) << 8;
        for ($b = 0; $b < 8; $b++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return $crc;
}

/** $id = mobile number (10 digits), citizen / tax ID (13) or e-wallet ID (15). */
function promptpay_payload(string $id, ?float $amount = null): ?string
{
    $id = preg_replace('/\D/', '', $id);
    if (strlen($id) === 10 && $id[0] === '0') { $tag = '01'; $val = '0066' . substr($id, 1); }
    elseif (strlen($id) === 13)               { $tag = '02'; $val = $id; }
    elseif (strlen($id) === 15)               { $tag = '03'; $val = $id; }
    else return null;

    $f = fn(string $t, string $v) => $t . sprintf('%02d', strlen($v)) . $v;
    $p = $f('00', '01')
       . $f('01', $amount ? '12' : '11')
       . $f('29', $f('00', 'A000000677010111') . $f($tag, $val))
       . $f('58', 'TH')
       . $f('53', '764');
    if ($amount) $p .= $f('54', number_format($amount, 2, '.', ''));
    $p .= '6304';
    return $p . sprintf('%04X', crc16_ccitt($p));
}
