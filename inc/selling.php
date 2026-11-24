<?php
/**
 * Watermarked previews + selling photos (paid by PromptPay transfer & slip).
 *
 *   albums.watermark_enabled  previews shown to clients carry the shop's watermark
 *   albums.sell_enabled       originals are locked until the client's order is paid
 *   albums.sell_price_photo   price per photo
 *   albums.sell_price_all     price for every photo in the gallery (0 = not offered)
 *   orders                    one row per purchase; a client is identified by client_key
 *
 * The shop's payment details are user_settings: pay_promptpay, pay_name, pay_note.
 */
declare(strict_types=1);

function migrate_selling(PDO $pdo): void
{
    $cols = [
        'watermark_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sell_enabled'      => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sell_price_photo'  => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
        'sell_price_all'    => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
    ];
    foreach ($cols as $col => $def) {
        if (!column_exists($pdo, 'albums', $col)) $pdo->exec("ALTER TABLE albums ADD COLUMN $col $def");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        album_id      INT UNSIGNED NOT NULL,
        client_key    CHAR(32) NOT NULL,
        name          VARCHAR(120) NOT NULL,
        contact       VARCHAR(160) NOT NULL DEFAULT '',
        image_ids     MEDIUMTEXT NOT NULL,
        is_all        TINYINT(1) NOT NULL DEFAULT 0,
        photo_count   INT UNSIGNED NOT NULL DEFAULT 0,
        amount        DECIMAL(10,2) NOT NULL DEFAULT 0,
        status        VARCHAR(16) NOT NULL DEFAULT 'pending',
        slip_file     VARCHAR(80) NULL,
        reject_reason VARCHAR(255) NOT NULL DEFAULT '',
        seen          TINYINT(1) NOT NULL DEFAULT 0,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        slip_at       DATETIME NULL,
        paid_at       DATETIME NULL,
        KEY idx_album (album_id, created_at),
        KEY idx_client (album_id, client_key),
        CONSTRAINT fk_orders_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ---------------- switches ---------------- */

function sell_on(array $album): bool
{
    return (int) ($album['sell_enabled'] ?? 0) === 1 && album_has_feature($album, 'sell');
}

/** Previews for clients get a watermark (always when selling). */
function watermark_on(array $album): bool
{
    return ((int) ($album['watermark_enabled'] ?? 0) === 1 || (int) ($album['sell_enabled'] ?? 0) === 1)
        && album_has_feature($album, 'sell');
}

/* ---------------- orders ---------------- */

/** Price for a set of photos – the "all photos" price is used when it is cheaper. */
function sell_quote(array $album, int $count, int $total): array
{
    $per   = (float) $album['sell_price_photo'];
    $all   = (float) $album['sell_price_all'];
    $isAll = $all > 0 && ($count >= $total || $count * $per >= $all);
    return [
        'count'  => $isAll ? $total : $count,
        'is_all' => $isAll,
        'amount' => round($isAll ? $all : $count * $per, 2),
    ];
}

function client_orders(int $albumId, string $key): array
{
    $st = db()->prepare('SELECT * FROM orders WHERE album_id = ? AND client_key = ? ORDER BY id DESC');
    $st->execute([$albumId, $key]);
    return $st->fetchAll();
}

/** Image ids the client has paid for ('all' => true when a full-gallery order was paid). */
function client_owned(int $albumId, string $key): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $key)) return ['all' => false, 'ids' => []];
    $all = false;
    $ids = [];
    foreach (client_orders($albumId, $key) as $o) {
        if ($o['status'] !== 'paid') continue;
        if ((int) $o['is_all']) $all = true;
        foreach (explode(',', (string) $o['image_ids']) as $id) if ((int) $id) $ids[(int) $id] = true;
    }
    return ['all' => $all, 'ids' => $ids];
}

function client_owns(array $album, string $key, int $imageId): bool
{
    $o = client_owned((int) $album['id'], $key);
    return $o['all'] || isset($o['ids'][$imageId]);
}

function album_orders(int $albumId): array
{
    $st = db()->prepare('SELECT * FROM orders WHERE album_id = ? AND status <> \'pending\' ORDER BY FIELD(status, \'review\') DESC, id DESC');
    $st->execute([$albumId]);
    return $st->fetchAll();
}

function order_status_label(string $s): string
{
    return ['pending' => 'รอชำระเงิน', 'review' => 'รอตรวจสลิป', 'paid' => 'ชำระแล้ว', 'rejected' => 'ไม่ผ่าน'][$s] ?? $s;
}

/** Orders with a slip waiting to be checked, per album (dashboard badges). */
function orders_to_review(int $userId): array
{
    $st = db()->prepare('SELECT o.album_id, COUNT(*) n FROM orders o JOIN albums a ON a.id = o.album_id
                         WHERE a.user_id = ? AND o.status = \'review\' GROUP BY o.album_id');
    $st->execute([$userId]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['album_id']] = (int) $r['n'];
    return $out;
}

/** The shop's payment details shown to its clients. */
function shop_payment(int $userId, float $amount = 0): array
{
    $pp = (string) user_setting($userId, 'pay_promptpay', '');
    return [
        'promptpay' => $pp,
        'name'      => (string) user_setting($userId, 'pay_name', ''),
        'note'      => (string) user_setting($userId, 'pay_note', ''),
        'payload'   => $pp !== '' ? (string) promptpay_payload($pp, $amount > 0 ? $amount : null) : '',
    ];
}

/* ---------------- watermark ---------------- */

function watermark_font(): ?string
{
    static $font = false;
    if ($font !== false) return $font;
    $candidates = array_merge(glob(APP_DIR . '/assets/fonts/*.ttf') ?: [], [
        'C:/Windows/Fonts/tahoma.ttf', 'C:/Windows/Fonts/LeelawUI.ttf', 'C:/Windows/Fonts/leelawad.ttf',
        '/usr/share/fonts/truetype/tlwg/Garuda.ttf', '/usr/share/fonts/truetype/noto/NotoSansThai-Regular.ttf',
        '/usr/share/fonts/noto/NotoSansThai-Regular.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf', '/Library/Fonts/Arial Unicode.ttf',
    ]);
    foreach ($candidates as $f) if (is_file($f)) return $font = $f;
    return $font = null;
}

function watermark_text(int $userId): string
{
    $t = trim((string) user_setting($userId, 'wm_text', ''));
    if ($t !== '') return $t;
    $u = find_user($userId);
    return $u && $u['shop_name'] !== '' ? $u['shop_name'] : 'PREVIEW';
}

/** Changing the watermark text must not serve old cached images. */
function watermark_version(int $userId): string
{
    return substr(sha1(watermark_text($userId) . '|' . user_setting($userId, 'wm_logo', '0') . '|' . user_setting($userId, 'logo_file', '') . '|v2'), 0, 10);
}

/** Tile the shop name (and logo, if chosen) diagonally across the photo. */
function apply_watermark(string $bytes, int $userId): ?array
{
    if (!function_exists('imagecreatefromstring')) return null;
    $img = @imagecreatefromstring($bytes);
    if (!$img) return null;
    imagealphablending($img, true);
    $w = imagesx($img);
    $h = imagesy($img);
    $text = watermark_text($userId);
    $font = watermark_font();
    $size = max(12, (int) round(min($w, $h) / 16));
    $white = imagecolorallocatealpha($img, 255, 255, 255, 78);   // 0 = opaque, 127 = clear
    $shade = imagecolorallocatealpha($img, 0, 0, 0, 108);

    if ($font) {
        $box = imagettfbbox($size, 28, $font, $text);
        $tw = (int) (max(abs($box[2] - $box[6]), abs($box[4] - $box[0])) * 1.35) + $size * 2;
        $th = (int) (max(abs($box[1] - $box[5]), abs($box[3] - $box[7])) * 1.6) + $size * 2;
        for ($y = -$th; $y < $h + $th; $y += $th) {
            $shift = (int) (($y / max(1, $th)) % 2) * (int) ($tw / 2);
            for ($x = -$tw + $shift; $x < $w + $tw; $x += $tw) {
                imagettftext($img, $size, 28, $x + 2, $y + 2, $shade, $font, $text);
                imagettftext($img, $size, 28, $x, $y, $white, $font, $text);
            }
        }
    } else {
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $text) ?: 'PREVIEW';
        for ($y = 10; $y < $h; $y += 60) {
            for ($x = ($y / 60 % 2) * 90; $x < $w; $x += 180) imagestring($img, 5, $x, $y, $ascii, $white);
        }
    }

    // Optional: the shop logo in the centre.
    $logo = user_setting($userId, 'wm_logo', '0') === '1' ? user_setting($userId, 'logo_file') : null;
    if ($logo && is_file(UPLOAD_DIR . '/' . $logo) && ($l = @imagecreatefromstring((string) file_get_contents(UPLOAD_DIR . '/' . $logo)))) {
        $lw = (int) ($w * .28);
        $lh = (int) (imagesy($l) * $lw / max(1, imagesx($l)));
        $tmp = imagecreatetruecolor($lw, $lh);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        imagecopyresampled($tmp, $l, 0, 0, 0, 0, $lw, $lh, imagesx($l), imagesy($l));
        imagefilter($tmp, IMG_FILTER_COLORIZE, 0, 0, 0, 70);
        imagecopy($img, $tmp, (int) (($w - $lw) / 2), (int) (($h - $lh) / 2), 0, 0, $lw, $lh);
        imagedestroy($tmp);
        imagedestroy($l);
    }

    ob_start();
    imagejpeg($img, null, 82);
    imagedestroy($img);
    return [(string) ob_get_clean(), 'image/jpeg'];
}
