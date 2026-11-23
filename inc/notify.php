<?php
/**
 * Notifications for photographers.
 *
 *   In the app  : notifications table → bell in the admin menu
 *   LINE OA     : Messaging API push to the photographer's own LINE user ID
 *                 (LINE Notify was shut down in 2025, so an Official Account is used)
 *   Telegram    : bot → chat
 *
 * Per-shop settings live in user_settings:
 *   notify_ev_{proof|view|download|expiring}   '1' / '0'
 *   notify_line_token, notify_line_to, notify_tg_token, notify_tg_chat
 */
declare(strict_types=1);

const NOTIFY_EVENTS = [
    'proof'    => ['♡', 'ลูกค้าส่งรายการคัดรูป', 'ส่งครั้งแรก และเมื่อแก้ไขแล้วส่งใหม่'],
    'view'     => ['◉', 'ลูกค้าเปิดลิงก์ครั้งแรก', 'รู้ว่าลูกค้าได้รับแกลเลอรีแล้ว'],
    'download' => ['↓', 'ลูกค้าเริ่มบันทึก / ดาวน์โหลดภาพ', 'แจ้งครั้งแรกของแต่ละแกลเลอรี'],
    'expiring' => ['⏳', 'ลิงก์ใกล้หมดอายุ (3 วัน)', 'แจ้งในระบบ เพื่อให้ต่ออายุหรือเตือนลูกค้า'],
];

function migrate_notify(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        album_id   INT UNSIGNED NULL,
        type       VARCHAR(20)  NOT NULL,
        title      VARCHAR(255) NOT NULL,
        body       TEXT NULL,
        link       VARCHAR(500) NULL,
        dedupe     VARCHAR(80)  NULL,
        delivery   VARCHAR(255) NOT NULL DEFAULT '',
        is_read    TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dedupe (user_id, dedupe),
        KEY idx_user (user_id, is_read, created_at),
        CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function notify_enabled(int $userId, string $type): bool
{
    return user_setting($userId, 'notify_ev_' . $type, '1') === '1';
}

/**
 * Record a notification and (optionally) push it to LINE / Telegram.
 * A $dedupe key makes the same event fire only once.
 */
function notify(int $userId, string $type, string $title, string $body = '', ?int $albumId = null,
                ?string $link = null, ?string $dedupe = null, bool $external = true): bool
{
    if (!$userId || !notify_enabled($userId, $type)) return false;
    try {
        $st = db()->prepare('INSERT IGNORE INTO notifications(user_id, album_id, type, title, body, link, dedupe) VALUES(?,?,?,?,?,?,?)');
        $st->execute([$userId, $albumId, $type, mb_substr($title, 0, 255), $body, $link, $dedupe]);
        if ($st->rowCount() === 0) return false;   // already sent
        $id = (int) db()->lastInsertId();
    } catch (Throwable $ex) {
        return false;
    }

    if ($external) {
        $icon = NOTIFY_EVENTS[$type][0] ?? '•';
        $text = $icon . ' ' . $title . ($body !== '' ? "\n" . $body : '') . ($link ? "\n\nเปิดดู: " . $link : '');
        $result = notify_deliver($userId, $text);
        if ($result) {
            db()->prepare('UPDATE notifications SET delivery = ? WHERE id = ?')->execute([mb_substr($result, 0, 255), $id]);
        }
    }
    return true;
}

/** Send to every configured channel. Returns a short status like "LINE ✓ · Telegram ✗ (401)". */
function notify_deliver(int $userId, string $text): string
{
    $out = [];
    $lt = (string) user_setting($userId, 'notify_line_token', '');
    $lu = (string) user_setting($userId, 'notify_line_to', '');
    if ($lt !== '' && $lu !== '') {
        [$ok, $err] = send_line($lt, $lu, $text);
        $out[] = 'LINE ' . ($ok ? '✓' : '✗ ' . $err);
    }
    $tt = (string) user_setting($userId, 'notify_tg_token', '');
    $tc = (string) user_setting($userId, 'notify_tg_chat', '');
    if ($tt !== '' && $tc !== '') {
        [$ok, $err] = send_telegram($tt, $tc, $text);
        $out[] = 'Telegram ' . ($ok ? '✓' : '✗ ' . $err);
    }
    return implode(' · ', $out);
}

function notify_post_json(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $body === false ? '' : (string) $body, $err];
}

/** LINE Messaging API push. */
function send_line(string $token, string $to, string $text): array
{
    [$code, $body, $err] = notify_post_json('https://api.line.me/v2/bot/message/push',
        ['to' => $to, 'messages' => [['type' => 'text', 'text' => mb_substr($text, 0, 4900)]]],
        ['Authorization: Bearer ' . $token]);
    if ($code === 200) return [true, ''];
    $msg = json_decode($body, true)['message'] ?? ($err ?: 'HTTP ' . $code);
    return [false, '(' . $code . ') ' . $msg];
}

function send_telegram(string $token, string $chat, string $text): array
{
    [$code, $body, $err] = notify_post_json('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage',
        ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000), 'disable_web_page_preview' => true]);
    if ($code === 200) return [true, ''];
    $msg = json_decode($body, true)['description'] ?? ($err ?: 'HTTP ' . $code);
    return [false, '(' . $code . ') ' . $msg];
}

/** Find the chat ID of whoever last messaged the bot (after they press Start / send "hi"). */
function telegram_find_chat(string $token): ?array
{
    $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/getUpdates?limit=20');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8]);
    $data = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    if (empty($data['ok'])) return null;
    foreach (array_reverse($data['result'] ?? []) as $u) {
        $chat = $u['message']['chat'] ?? $u['channel_post']['chat'] ?? null;
        if ($chat) return ['id' => (string) $chat['id'], 'name' => $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))];
    }
    return null;
}

/* ---------------- reading ---------------- */

function unread_notification_count(int $userId): int
{
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $st->execute([$userId]);
        return $cache[$userId] = (int) $st->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

function last_notification_id(int $userId): int
{
    try {
        $st = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $ex) {
        return 0;
    }
}

/** Notifications newer than $afterId (for the live check in the admin). */
function notifications_after(int $userId, int $afterId, int $limit = 10): array
{
    $st = db()->prepare('SELECT id, type, title, body, link, created_at FROM notifications
                         WHERE user_id = ? AND id > ? ORDER BY id DESC LIMIT ' . max(1, $limit));
    $st->execute([$userId, $afterId]);
    return array_reverse($st->fetchAll());
}

function user_notifications(int $userId, int $limit = 100): array
{
    $st = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit));
    $st->execute([$userId]);
    return $st->fetchAll();
}

/** Links that expire within 3 days → one in-app reminder each (checked when the photographer opens the admin). */
function check_expiring_links(int $userId): void
{
    static $done = false;
    if ($done || !notify_enabled($userId, 'expiring')) return;
    $done = true;
    $st = db()->prepare('SELECT id, title, expires_at FROM albums WHERE user_id = ? AND is_active = 1
                         AND expires_at > NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)');
    $st->execute([$userId]);
    foreach ($st as $a) {
        notify($userId, 'expiring', 'ลิงก์ “' . $a['title'] . '” จะหมดอายุ ' . thai_date($a['expires_at'], true, true) . ' น.',
            'ต่ออายุได้ที่หน้าแก้ไขแกลเลอรี หรือแจ้งลูกค้าให้บันทึกภาพก่อนหมดอายุ',
            (int) $a['id'], abs_url('admin/album.php?id=' . $a['id']), 'exp:' . $a['id'] . ':' . date('Ymd', strtotime($a['expires_at'])), false);
    }
}

/** True when the person looking at a client page is the shop that owns it (so we don't notify about yourself). */
function viewer_is_owner(array $album): bool
{
    $u = current_user();
    return $u && ((int) $u['id'] === (int) $album['user_id'] || $u['role'] === 'admin');
}

function notify_mask(string $secret): string
{
    return $secret === '' ? '' : '••••••' . mb_substr($secret, -4);
}
