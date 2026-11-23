<?php
declare(strict_types=1);

define('APP_DIR', dirname(__DIR__));
define('DATA_DIR', APP_DIR . '/data');
define('CACHE_DIR', DATA_DIR . '/cache');
define('UPLOAD_DIR', APP_DIR . '/uploads');
define('APP_VERSION', '1.1.0');
define('SCHEMA_VERSION', 7);

date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/google.php';
require_once __DIR__ . '/account.php';
require_once __DIR__ . '/proofing.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/features.php';

function config(string $key, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        // config.php is not in git (it holds the DB password); fall back to the example on a fresh clone.
        $file = is_file(APP_DIR . '/config.php') ? APP_DIR . '/config.php' : APP_DIR . '/config.example.php';
        $cfg = require $file;
    }
    return $cfg[$key] ?? $default;
}

/* ---------------------------------------------------------------
 * Database (MySQL / MariaDB) – database & tables are auto-created
 * ------------------------------------------------------------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    foreach ([DATA_DIR, CACHE_DIR, UPLOAD_DIR] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0775, true);
    }
    if (!is_file(DATA_DIR . '/.htaccess')) {
        file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
    }
    if (!is_file(UPLOAD_DIR . '/.htaccess')) {
        file_put_contents(UPLOAD_DIR . '/.htaccess', "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|php\\d)$\">\n  Require all denied\n</FilesMatch>\n");
    }

    $name = config('db_name', 'sendphoto');
    $dsn  = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', config('db_host', '127.0.0.1'), (int) config('db_port', 3306));
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, config('db_user', 'root'), config('db_pass', ''), $opts);

    $flag = DATA_DIR . '/.schema_v' . SCHEMA_VERSION;
    if (!is_file($flag)) {
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $pdo->exec('USE `' . str_replace('`', '', $name) . '`');
    $pdo->exec("SET time_zone = '+07:00'");
    if (!is_file($flag)) {
        migrate($pdo);
        migrate_accounts($pdo);
        migrate_proofing($pdo);
        migrate_notify($pdo);
        migrate_features($pdo);
        file_put_contents($flag, date('c'));
    }
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
        `value` MEDIUMTEXT NULL
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS albums (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token          VARCHAR(40)  NOT NULL,
        title          VARCHAR(255) NOT NULL,
        client         VARCHAR(255) NOT NULL DEFAULT '',
        tagline        VARCHAR(255) NOT NULL DEFAULT '',
        description    TEXT NULL,
        event_date     DATE NULL,
        sources        TEXT NULL,
        cover_image_id INT UNSIGNED NULL,
        cover_file     VARCHAR(255) NULL,
        logo_file      VARCHAR(255) NULL,
        is_active      TINYINT(1) NOT NULL DEFAULT 1,
        allow_download TINYINT(1) NOT NULL DEFAULT 1,
        expires_at     DATETIME NULL,
        total_bytes    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        views          INT UNSIGNED NOT NULL DEFAULT 0,
        downloads      INT UNSIGNED NOT NULL DEFAULT 0,
        synced_at      DATETIME NULL,
        sync_note      TEXT NULL,
        created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token),
        KEY idx_created (created_at)
    ) $opts");

    $pdo->exec("CREATE TABLE IF NOT EXISTS images (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        album_id   INT UNSIGNED NOT NULL,
        provider   VARCHAR(16)  NOT NULL,
        file_id    TEXT NOT NULL,
        file_hash  CHAR(40) NOT NULL,
        ref        VARCHAR(512) NULL,
        name       VARCHAR(255) NOT NULL DEFAULT '',
        mime       VARCHAR(80)  NOT NULL DEFAULT '',
        size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        width      INT UNSIGNED NOT NULL DEFAULT 0,
        height     INT UNSIGNED NOT NULL DEFAULT 0,
        sort       INT NOT NULL DEFAULT 0,
        hidden     TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_album_file (album_id, file_hash),
        KEY idx_album_sort (album_id, sort),
        CONSTRAINT fk_images_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
    ) $opts");
}

/* ---------------------------------------------------------------
 * Settings
 * ------------------------------------------------------------- */
function setting(string $key, ?string $default = null): ?string
{
    global $__settings;
    if ($__settings === null) {
        $__settings = [];
        foreach (db()->query('SELECT `key`, `value` FROM settings') as $row) {
            $__settings[$row['key']] = $row['value'];
        }
    }
    return $__settings[$key] ?? $default;
}

function set_setting(string $key, ?string $value): void
{
    global $__settings;
    db()->prepare('INSERT INTO settings(`key`, `value`) VALUES(?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
        ->execute([$key, $value]);
    $__settings[$key] = $value;
}

/** Name of the whole service (shown on login / sign-up pages). */
function platform_name(): string
{
    return setting('platform_name', '') ?: (setting('site_name', '') ?: 'Photo Delivery');
}

/** Shop name of the signed-in account, else the service name. */
function site_name(): string
{
    $u = current_user();
    return $u ? ($u['shop_name'] ?: platform_name()) : platform_name();
}

/* ---------------------------------------------------------------
 * URLs
 * ------------------------------------------------------------- */
function base_path(): string
{
    static $base = null;
    if ($base !== null) return $base;
    $docRoot = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $appDir  = str_replace('\\', '/', (string) realpath(APP_DIR));
    $base = '';
    if ($docRoot !== '' && stripos($appDir, $docRoot) === 0) {
        $base = substr($appDir, strlen($docRoot));
    }
    return $base = rtrim('/' . trim($base, '/'), '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function abs_url(string $path = ''): string
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

function share_url(string $token): string
{
    return abs_url('s/' . rawurlencode($token));
}

function asset(string $path): string
{
    $file = APP_DIR . '/assets/' . $path;
    return url('assets/' . $path) . '?v=' . (is_file($file) ? filemtime($file) : APP_VERSION);
}

function upload_url(?string $file): string
{
    return $file ? url('uploads/' . rawurlencode($file)) : '';
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------------------------------------------------------
 * Output helpers
 * ------------------------------------------------------------- */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function thai_date(?string $date, bool $short = false, bool $time = false): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    if (!$ts) return '';
    $full = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    $abbr = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $m = (int) date('n', $ts) - 1;
    $s = (int) date('j', $ts) . ' ' . ($short ? $abbr[$m] : $full[$m]) . ' ' . ((int) date('Y', $ts) + 543);
    return $time ? $s . ' ' . date('H:i', $ts) : $s;
}

function human_bytes(int|float $bytes): string
{
    if ($bytes <= 0) return '';
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) min(floor(log($bytes, 1024)), count($u) - 1);
    return number_format($bytes / (1024 ** $i), $i >= 2 ? 2 : 0) . ' ' . $u[$i];
}

/** Unguessable share token generated from random_bytes(). */
function new_token(int $len = 16): string
{
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $n = strlen($alphabet);
    $max = 256 - (256 % $n);
    $out = '';
    while (strlen($out) < $len) {
        foreach (str_split(random_bytes($len * 2)) as $ch) {
            $b = ord($ch);
            if ($b >= $max) continue;
            $out .= $alphabet[$b % $n];
            if (strlen($out) === $len) break;
        }
    }
    return $out;
}

/* ---------------------------------------------------------------
 * Sessions / auth / CSRF (admin only)
 * ------------------------------------------------------------- */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('sendphoto_admin');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => base_path() . '/',
    ]);
    session_start();
}

/**
 * Signed-in account (owner of a shop, or the central admin), or null.
 * The session is bound to a fragment of the password hash, so changing the
 * password or suspending the account signs out every other session.
 */
function current_user(bool $refresh = false): ?array
{
    static $cache = false;
    if ($refresh) $cache = false;
    if ($cache !== false) return $cache;
    if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE['sendphoto_admin'])) return $cache = null;
    start_session();
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if (!$uid) return $cache = null;
    $u = find_user($uid);
    if (!$u || $u['status'] === 'suspended' || !hash_equals((string) ($_SESSION['pv'] ?? ''), session_pv($u))) {
        unset($_SESSION['uid'], $_SESSION['pv']);
        return $cache = null;
    }
    return $cache = $u;
}

function session_pv(array $u): string
{
    return substr(hash('sha256', (string) $u['password_hash']), 0, 16);
}

function login_user(array $u): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['pv']  = session_pv($u);
    $_SESSION['tries'] = 0;
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
    current_user(true);
}

function is_superadmin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Any signed-in account (shop owner or central admin). */
function require_login(bool $json = false): array
{
    $u = current_user();
    if (!$u) {
        if ($json) json_out(['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบใหม่'], 401);
        redirect(url('admin/login.php'));
    }
    return $u;
}

/** Central admin only. */
function require_superadmin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        flash('หน้านี้สำหรับผู้ดูแลระบบเท่านั้น', 'err');
        redirect(url('admin/'));
    }
    return $u;
}

/** Block changes when the membership has ended (central admin is never blocked). */
function require_active_account(array $u, bool $json = false): void
{
    if (account_can_write($u)) return;
    $msg = $u['status'] === 'suspended' ? 'บัญชีถูกระงับ' : 'สมาชิกหมดอายุแล้ว กรุณาต่ออายุที่หน้า “แพ็กเกจ” ก่อนสร้างหรือแก้ไขแกลเลอรี';
    if ($json) json_out(['ok' => false, 'error' => $msg], 402);
    flash($msg, 'err');
    redirect(url('admin/billing.php'));
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(419);
        exit('Session หมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่');
    }
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    start_session();
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------------------------------------------------------
 * Albums / images
 * ------------------------------------------------------------- */
function find_album_by_token(string $token): ?array
{
    if (!preg_match('/^[A-Za-z0-9]{8,40}$/', $token)) return null;
    $st = db()->prepare('SELECT * FROM albums WHERE token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

function find_album(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM albums WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function album_expired(array $a): bool
{
    return !empty($a['expires_at']) && strtotime($a['expires_at']) <= time();
}

/** Checked on the server for every page, image and download request. */
function album_is_public(array $a): bool
{
    return (int) $a['is_active'] === 1 && !album_expired($a) && account_public_ok($a['user_id'] ?? null);
}

function album_status(array $a): array
{
    if (!(int) $a['is_active']) return ['off', 'ปิดลิงก์แล้ว'];
    if (album_expired($a))      return ['exp', 'หมดอายุ'];
    if (!account_public_ok($a['user_id'] ?? null)) return ['off', 'ปิด (สมาชิกหมดอายุ)'];
    return ['on', 'เปิดอยู่'];
}

function album_images(int $albumId, bool $includeHidden = false): array
{
    $sql = 'SELECT * FROM images WHERE album_id = ?' . ($includeHidden ? '' : ' AND hidden = 0') . ' ORDER BY sort, id';
    $st = db()->prepare($sql);
    $st->execute([$albumId]);
    return $st->fetchAll();
}

function find_image(int $albumId, int $imageId): ?array
{
    $st = db()->prepare('SELECT * FROM images WHERE id = ? AND album_id = ?');
    $st->execute([$imageId, $albumId]);
    return $st->fetch() ?: null;
}

function album_cover_image(array $album): ?array
{
    if ($album['cover_image_id']) {
        $img = find_image((int) $album['id'], (int) $album['cover_image_id']);
        if ($img) return $img;
    }
    $st = db()->prepare('SELECT * FROM images WHERE album_id = ? AND hidden = 0 ORDER BY sort, id LIMIT 1');
    $st->execute([$album['id']]);
    return $st->fetch() ?: null;
}

/** Cover URL for display: uploaded cover first, else a photo from the album. */
function album_cover_url(array $album, bool $public = false, string $size = 'preview'): string
{
    if (!empty($album['cover_file'])) return upload_url($album['cover_file']);
    $img = album_cover_image($album);
    return $img ? media_url($img, $size, $public ? $album['token'] : null) : '';
}

function album_logo_url(array $album): string
{
    if (!empty($album['logo_file'])) {
        return $album['logo_file'] === '-' ? '' : upload_url($album['logo_file']);
    }
    return upload_url(user_setting((int) ($album['user_id'] ?? 0), 'logo_file'));
}

function count_images(int $albumId): array
{
    $st = db()->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(hidden = 0),0) AS visible,
                                COALESCE(SUM(CASE WHEN hidden = 0 THEN size_bytes ELSE 0 END),0) AS bytes
                         FROM images WHERE album_id = ?');
    $st->execute([$albumId]);
    $r = $st->fetch();
    return ['total' => (int) $r['total'], 'visible' => (int) $r['visible'], 'bytes' => (int) $r['bytes']];
}

/**
 * Browser-facing image URL – always goes through media.php so Drive file IDs
 * and Immich keys are never exposed. Pass the album token for client pages;
 * without a token the request is only allowed for a logged-in admin.
 */
function media_url(array $img, string $size = 'thumb', ?string $token = null): string
{
    $q = ['i' => $img['id'], 's' => $size];
    if ($token) $q = ['t' => $token] + $q;
    return url('media.php?' . http_build_query($q));
}

/**
 * Server-side URL to fetch an image from its provider.
 * $size: thumb | preview | full
 */
function upstream_url(array $img, string $size): string
{
    $w = $size === 'thumb' ? 800 : 2048;
    switch ($img['provider']) {
        case 'drive':
            if ($size === 'full') {
                return 'https://drive.usercontent.google.com/download?id=' . rawurlencode($img['file_id']) . '&export=download&confirm=t';
            }
            return 'https://lh3.googleusercontent.com/d/' . rawurlencode($img['file_id']) . '=w' . $w;
        case 'gphotos':
            return $img['file_id'] . ($size === 'full' ? '=d' : '=w' . $w);
        case 'immich':
            $ref = json_decode((string) $img['ref'], true) ?: [];
            $auth = rawurlencode($ref['p'] ?? 'key') . '=' . rawurlencode($ref['v'] ?? '');
            $base = rtrim($ref['base'] ?? '', '/') . '/api/assets/' . rawurlencode($img['file_id']);
            return $size === 'full'
                ? $base . '/original?' . $auth
                : $base . '/thumbnail?size=preview&' . $auth;
        default:
            return $img['file_id'];
    }
}

/** Fallback when the primary Drive thumbnail endpoint refuses. */
function upstream_fallback(array $img, string $size): ?string
{
    if ($img['provider'] !== 'drive') return null;
    $w = $size === 'thumb' ? 800 : 2048;
    return 'https://drive.google.com/thumbnail?id=' . rawurlencode($img['file_id']) . '&sz=w' . $w;
}

function download_name(array $img, int $index, string $mime = ''): string
{
    $name = trim((string) $img['name']);
    if ($name !== '') return $name;
    $ext = match (true) {
        str_contains($mime, 'png')  => 'png',
        str_contains($mime, 'heic') => 'heic',
        str_contains($mime, 'webp') => 'webp',
        default                     => 'jpg',
    };
    return sprintf('photo-%03d.%s', $index, $ext);
}

/* ---------------------------------------------------------------
 * Uploads (cover / logo)
 * ------------------------------------------------------------- */
function save_upload(string $field, int $maxMb = 15): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (รหัส ' . $f['error'] . ')');
    if ($f['size'] > $maxMb * 1024 * 1024) throw new RuntimeException('ไฟล์ใหญ่เกิน ' . $maxMb . ' MB');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('รองรับเฉพาะไฟล์ JPG, PNG, WEBP, GIF หรือ SVG');
    if ($ext === 'svg') {
        $svg = (string) file_get_contents($f['tmp_name']);
        if (preg_match('/<script|on\w+\s*=|javascript:/i', $svg)) throw new RuntimeException('ไฟล์ SVG ไม่ปลอดภัย');
    }

    $name = $field . '-' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
    }
    return $name;
}

function delete_upload(?string $file): void
{
    if ($file && $file !== '-' && preg_match('/^[a-z]+-[a-f0-9]{20}\.[a-z]+$/', $file)) {
        @unlink(UPLOAD_DIR . '/' . $file);
    }
}
