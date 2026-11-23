<?php
/** Live check for the admin: unread count + notifications newer than ?after=ID. */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

header('Cache-Control: no-store, private');
$me = require_login(true);
session_write_close();   // don't hold the session lock while other admin pages load

$uid   = (int) $me['id'];
$after = max(0, (int) ($_GET['after'] ?? 0));
$items = notifications_after($uid, $after, 10);

json_out([
    'ok'     => true,
    'unread' => unread_notification_count($uid),
    'last'   => max($after, last_notification_id($uid)),
    'items'  => array_map(fn($n) => [
        'id'    => (int) $n['id'],
        'type'  => $n['type'],
        'icon'  => NOTIFY_EVENTS[$n['type']][0] ?? '•',
        'title' => $n['title'],
        'body'  => (string) $n['body'],
        'link'  => (string) $n['link'],
        'time'  => date('H:i', strtotime($n['created_at'])),
    ], $items),
]);
