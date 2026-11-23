<?php
/**
 * Paid features per membership plan.
 *
 *   plans.features            comma list, e.g. "proof,sell,face"
 *   settings.trial_features   what the free trial includes
 *
 * Central admins and members without a plan (days granted by hand) get everything.
 */
declare(strict_types=1);

const FEATURES = [
    'proof' => ['♡', 'ระบบคัดรูป', 'ลูกค้าเลือกภาพรีทัช ส่งรายการให้ช่าง'],
    'sell'  => ['฿', 'ลายน้ำ + ขายรูป', 'ภาพตัวอย่างมีลายน้ำ ลูกค้าจ่ายผ่านพร้อมเพย์แล้วโหลดต้นฉบับ'],
    'face'  => ['☺', 'ค้นหาด้วยใบหน้า', 'ลูกค้าถ่ายเซลฟี่ แล้วระบบหาภาพที่มีตัวเอง'],
];

function migrate_features(PDO $pdo): void
{
    if (!column_exists($pdo, 'plans', 'features')) {
        $pdo->exec("ALTER TABLE plans ADD COLUMN features VARCHAR(120) NOT NULL DEFAULT 'proof,sell,face' AFTER max_albums");
    }
    $pdo->prepare('INSERT IGNORE INTO settings(`key`, `value`) VALUES(?, ?)')->execute(['trial_features', 'proof,sell,face']);
}

function parse_features(?string $csv): array
{
    return array_values(array_intersect(array_keys(FEATURES), array_map('trim', explode(',', (string) $csv))));
}

function account_features(?array $u): array
{
    if (!$u) return [];
    if ($u['role'] === 'admin') return array_keys(FEATURES);
    if ((int) $u['on_trial']) return parse_features(setting('trial_features', 'proof,sell,face'));
    if (!empty($u['plan_id']) && ($p = find_plan((int) $u['plan_id']))) return parse_features($p['features'] ?? '');
    return array_keys(FEATURES);
}

function has_feature(?array $u, string $feature): bool
{
    return in_array($feature, account_features($u), true);
}

/** Feature check for client pages: what the shop that owns the gallery has paid for. */
function album_has_feature(array $album, string $feature): bool
{
    static $cache = [];
    $uid = (int) ($album['user_id'] ?? 0);
    if (!isset($cache[$uid])) $cache[$uid] = account_features(album_owner($album));
    return in_array($feature, $cache[$uid], true);
}

/** Small "upgrade" note shown instead of a locked setting. */
function feature_locked_note(string $feature): string
{
    $name = FEATURES[$feature][1] ?? $feature;
    return '<p class="locked">🔒 ' . e($name) . ' ไม่รวมอยู่ในแพ็กเกจปัจจุบัน · <a href="' . e(url('admin/billing.php')) . '">ดูแพ็กเกจที่มีฟีเจอร์นี้ →</a></p>';
}
