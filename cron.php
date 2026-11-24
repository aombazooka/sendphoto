<?php
/**
 * Scheduled jobs – run from the command line every hour:
 *
 *   Windows (XAMPP):  schtasks /Create /SC HOURLY /TN "sendphoto-cron" /TR "C:\xampp\php\php.exe C:\xampp\htdocs\sendphoto\cron.php"
 *   Linux:            0 * * * *  php /var/www/sendphoto/cron.php >/dev/null 2>&1
 *
 * Jobs:
 *   1. Links expiring within 3 days → notify the shop (in the app + LINE / Telegram / Web Push), once per gallery
 *   2. Unpaid orders with no slip after 30 days → removed
 *   3. Cached previews not touched for 120 days → removed (they are re-made on demand)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (empty($_SERVER['DOCUMENT_ROOT'])) $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);   // best guess for links; set base_url to be sure
require __DIR__ . '/inc/bootstrap.php';
@set_time_limit(600);

$log = fn(string $m) => print('[' . date('Y-m-d H:i:s') . '] ' . $m . PHP_EOL);
if (trim((string) config('base_url', '')) === '') {
    $log('คำเตือน: ยังไม่ได้ตั้ง base_url ใน config.php — ลิงก์ในแจ้งเตือนจะชี้ไปที่ localhost');
}

// 1. expiring links
$before = (int) db()->query('SELECT COALESCE(MAX(id), 0) FROM notifications')->fetchColumn();
$users = db()->query("SELECT DISTINCT user_id FROM albums WHERE is_active = 1 AND expires_at > NOW()
                      AND expires_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)")->fetchAll(PDO::FETCH_COLUMN);
foreach ($users as $uid) check_expiring_links((int) $uid);
$sent = (int) db()->query('SELECT COUNT(*) FROM notifications WHERE id > ' . $before)->fetchColumn();
$log('ลิงก์ใกล้หมดอายุ: แจ้งเตือนใหม่ ' . $sent . ' รายการ');

// 2. stale unpaid orders
$n = db()->exec("DELETE FROM orders WHERE status = 'pending' AND slip_file IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
$log('ลบคำสั่งซื้อที่ไม่ได้ชำระเกิน 30 วัน: ' . (int) $n);

// 3. old cached previews
$removed = 0;
$limit = time() - 120 * 86400;
foreach (glob(CACHE_DIR . '/*/*') ?: [] as $f) {
    if (is_file($f) && filemtime($f) < $limit && @unlink($f)) $removed++;
}
$log('ลบแคชภาพเก่า: ' . $removed . ' ไฟล์');

set_setting('cron_last_run', date('Y-m-d H:i:s'));
$log('เสร็จ');
