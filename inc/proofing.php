<?php
/**
 * Client proofing – clients pick the photos they want retouched / printed
 * and send the list to the photographer.
 *
 *   albums.proof_enabled      1 = show the "คัดรูป" mode on the client page
 *   albums.proof_limit        how many photos are included (0 = no limit)
 *   albums.proof_extra_price  price per photo over the limit (0 = can't go over)
 *   albums.proof_deadline     last day the client can send / change the list
 *   proofs                    one row per client browser (client_key)
 */
declare(strict_types=1);

function migrate_proofing(PDO $pdo): void
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $cols = [
        'proof_enabled'     => 'TINYINT(1) NOT NULL DEFAULT 0',
        'proof_limit'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'proof_extra_price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
        'proof_deadline'    => 'DATE NULL',
        'proof_message'     => "VARCHAR(500) NOT NULL DEFAULT ''",
        'cover_style'       => "VARCHAR(16) NOT NULL DEFAULT 'editorial'",
    ];
    foreach ($cols as $col => $def) {
        if (!column_exists($pdo, 'albums', $col)) {
            $pdo->exec("ALTER TABLE albums ADD COLUMN $col $def");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS proofs (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        album_id     INT UNSIGNED NOT NULL,
        client_key   CHAR(32) NOT NULL,
        name         VARCHAR(120) NOT NULL,
        contact      VARCHAR(160) NOT NULL DEFAULT '',
        note         TEXT NULL,
        image_ids    MEDIUMTEXT NOT NULL,
        pick_count   INT UNSIGNED NOT NULL DEFAULT 0,
        extra_count  INT UNSIGNED NOT NULL DEFAULT 0,
        extra_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        status       VARCHAR(16) NOT NULL DEFAULT 'submitted',
        seen         TINYINT(1) NOT NULL DEFAULT 0,
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_album_client (album_id, client_key),
        KEY idx_album (album_id, submitted_at),
        CONSTRAINT fk_proofs_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
    ) $opts");
}

function proof_open(array $album): bool
{
    if (!(int) ($album['proof_enabled'] ?? 0)) return false;
    if (!empty($album['proof_deadline']) && strtotime($album['proof_deadline'] . ' 23:59:59') < time()) return false;
    return true;
}

/** @return array{count:int, extra:int, amount:float, over:bool} */
function proof_quote(array $album, int $count): array
{
    $limit = (int) $album['proof_limit'];
    $price = (float) $album['proof_extra_price'];
    $extra = $limit > 0 ? max(0, $count - $limit) : 0;
    return [
        'count'  => $count,
        'extra'  => $extra,
        'amount' => $extra * $price,
        'over'   => $extra > 0 && $price <= 0,   // over the limit and extras aren't sold
    ];
}

function album_proofs(int $albumId): array
{
    $st = db()->prepare('SELECT * FROM proofs WHERE album_id = ? ORDER BY submitted_at DESC, id DESC');
    $st->execute([$albumId]);
    return $st->fetchAll();
}

function find_proof_by_key(int $albumId, string $key): ?array
{
    $st = db()->prepare('SELECT * FROM proofs WHERE album_id = ? AND client_key = ?');
    $st->execute([$albumId, $key]);
    return $st->fetch() ?: null;
}

function proof_ids(array $proof): array
{
    return array_values(array_filter(array_map('intval', explode(',', (string) $proof['image_ids']))));
}

/** Selected images of a proof, in gallery order. */
function proof_images(array $proof): array
{
    $ids = proof_ids($proof);
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT * FROM images WHERE album_id = ? AND id IN ($in) ORDER BY sort, id");
    $st->execute([(int) $proof['album_id'], ...$ids]);
    return $st->fetchAll();
}

/** Unseen submissions per album, for dashboard badges. */
function unseen_proof_counts(int $userId): array
{
    $st = db()->prepare('SELECT p.album_id, COUNT(*) AS n FROM proofs p JOIN albums a ON a.id = p.album_id
                         WHERE a.user_id = ? AND p.seen = 0 GROUP BY p.album_id');
    $st->execute([$userId]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['album_id']] = (int) $r['n'];
    return $out;
}

function proof_status_label(string $s): string
{
    return ['submitted' => 'ส่งรายการแล้ว', 'locked' => 'ล็อกแล้ว · กำลังรีทัช'][$s] ?? $s;
}
