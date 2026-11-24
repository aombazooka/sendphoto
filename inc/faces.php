<?php
/**
 * Face search ("หาภาพของฉัน").
 *
 *   The photographer's browser builds the index: for each photo it finds faces and
 *   turns each into 128 numbers (a face descriptor). Only those numbers are stored.
 *   A client takes a selfie; their own browser turns it into a descriptor and sends
 *   just that (never the photo) – the server returns photos with a close match.
 *
 *   albums.face_enabled   off by default (face data is sensitive personal data under PDPA)
 *   images.face_indexed   1 once the photo has been scanned (even if it has no faces)
 *   faces                 one row per face: 128 float32 values packed into 512 bytes
 */
declare(strict_types=1);

const FACE_MATCH_DISTANCE = 0.55;   // smaller = stricter (face-api's usual cut-off is 0.6)

function migrate_faces(PDO $pdo): void
{
    if (!column_exists($pdo, 'albums', 'face_enabled')) {
        $pdo->exec('ALTER TABLE albums ADD COLUMN face_enabled TINYINT(1) NOT NULL DEFAULT 0');
    }
    if (!column_exists($pdo, 'images', 'face_indexed')) {
        $pdo->exec('ALTER TABLE images ADD COLUMN face_indexed TINYINT(1) NOT NULL DEFAULT 0');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS faces (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        album_id   INT UNSIGNED NOT NULL,
        image_id   INT UNSIGNED NOT NULL,
        descriptor VARBINARY(512) NOT NULL,
        KEY idx_album (album_id),
        CONSTRAINT fk_faces_image FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function face_on(array $album): bool
{
    return (int) ($album['face_enabled'] ?? 0) === 1 && album_has_feature($album, 'face');
}

/** Base64 of 128 little-endian float32 values → binary, or null when malformed. */
function face_descriptor_from_b64(string $b64): ?string
{
    $bin = base64_decode($b64, true);
    if ($bin === false || strlen($bin) !== 512) return null;
    foreach (unpack('g*', $bin) as $v) {
        if (!is_finite($v) || abs($v) > 10) return null;
    }
    return $bin;
}

function face_index_stats(int $albumId): array
{
    $st = db()->prepare('SELECT COUNT(*) total, COALESCE(SUM(face_indexed = 1), 0) done FROM images WHERE album_id = ? AND hidden = 0');
    $st->execute([$albumId]);
    $r = $st->fetch();
    $f = db()->prepare('SELECT COUNT(*) FROM faces WHERE album_id = ?');
    $f->execute([$albumId]);
    return ['total' => (int) $r['total'], 'done' => (int) $r['done'], 'faces' => (int) $f->fetchColumn()];
}

/**
 * Photos whose faces are close to the given descriptor, best match first.
 * @return int[] image ids
 */
function face_match(int $albumId, string $probe): array
{
    $p = array_values(unpack('g*', $probe));
    $st = db()->prepare('SELECT f.image_id, f.descriptor FROM faces f JOIN images i ON i.id = f.image_id
                         WHERE f.album_id = ? AND i.hidden = 0');
    $st->execute([$albumId]);
    $best = [];
    $limit = FACE_MATCH_DISTANCE * FACE_MATCH_DISTANCE;
    foreach ($st as $row) {
        $d = array_values(unpack('g*', $row['descriptor']));
        $sum = 0.0;
        for ($k = 0; $k < 128; $k++) {
            $x = $p[$k] - $d[$k];
            $sum += $x * $x;
            if ($sum > $limit) break;
        }
        if ($sum <= $limit) {
            $id = (int) $row['image_id'];
            if (!isset($best[$id]) || $sum < $best[$id]) $best[$id] = $sum;
        }
    }
    asort($best);
    return array_keys($best);
}
