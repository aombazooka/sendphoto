<?php
declare(strict_types=1);

/**
 * Reading photo lists from Google Drive, Google Photos shared albums,
 * Immich shared links and plain image URLs.
 *
 * Every item returned has the shape:
 *   provider, file_id, ref, name, mime, size_bytes, width, height
 */

const SP_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const SP_IMAGE_EXT  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp', 'avif'];

function http_get(string $url, ?array &$info = null, int $timeout = 30, array $headers = []): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 8,
        CURLOPT_CONNECTTIMEOUT  => 15,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_USERAGENT       => SP_USER_AGENT,
        CURLOPT_ENCODING        => '',
        CURLOPT_HTTPHEADER      => array_merge(['Accept-Language: en-US,en;q=0.9,th;q=0.8'], $headers),
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $info['error'] = curl_error($ch);
    curl_close($ch);
    return $body === false ? null : $body;
}

function sp_item(string $provider, string $fileId, array $extra = []): array
{
    return $extra + [
        'provider' => $provider, 'file_id' => $fileId, 'ref' => null,
        'name' => '', 'mime' => '', 'size_bytes' => 0, 'width' => 0, 'height' => 0, 'section' => '',
    ];
}

/**
 * Work out what a pasted link points to.
 */
function parse_source(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) return null;
    if (!preg_match('~^https?://~i', $line)) return ['type' => 'invalid', 'url' => $line];

    $host = strtolower((string) parse_url($line, PHP_URL_HOST));
    $path = (string) parse_url($line, PHP_URL_PATH);

    if (str_contains($host, 'drive.google.com') || str_contains($host, 'docs.google.com')) {
        if (preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $line, $m))              return ['type' => 'drive_folder', 'id' => $m[1], 'url' => $line];
        if (preg_match('~folderview\?.*?id=([A-Za-z0-9_-]{10,})~', $line, $m))     return ['type' => 'drive_folder', 'id' => $m[1], 'url' => $line];
        if (preg_match('~/(?:file/)?d/([A-Za-z0-9_-]{10,})~', $line, $m))          return ['type' => 'drive_file', 'id' => $m[1], 'url' => $line];
        if (preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $line, $m))                return ['type' => 'drive_file', 'id' => $m[1], 'url' => $line];
        return ['type' => 'invalid', 'url' => $line];
    }

    if ($host === 'photos.app.goo.gl' || $host === 'photos.google.com' || $host === 'goo.gl') {
        return ['type' => 'gphotos', 'url' => $line];
    }

    if (preg_match('~^lh\d+\.googleusercontent\.com$~', $host)) {
        return ['type' => 'url', 'url' => preg_replace('~=[^/=]*$~', '', $line)];
    }

    // Immich shared links: https://immich.example.com/share/<key>  or  /s/<slug>
    if (preg_match('~^(https?://.+?)/share/([A-Za-z0-9_-]{8,})/?(?:[?#].*)?$~', $line, $m)) {
        return ['type' => 'immich', 'base' => $m[1], 'p' => 'key', 'v' => $m[2], 'url' => $line];
    }
    if (is_image_name($path)) {
        return ['type' => 'url', 'url' => $line];
    }
    if (preg_match('~^(https?://.+?)/s/([^/?#]+)/?(?:[?#].*)?$~', $line, $m)) {
        return ['type' => 'immich', 'base' => $m[1], 'p' => 'slug', 'v' => rawurldecode($m[2]), 'url' => $line];
    }

    return ['type' => 'url', 'url' => $line];
}

function is_image_name(string $name): bool
{
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), SP_IMAGE_EXT, true);
}

function fetch_source(array $src, ?string $apiKey, array &$errors): array
{
    try {
        switch ($src['type']) {
            case 'drive_folder':
                return $apiKey ? drive_folder_api($src['id'], $apiKey, 0) : drive_folder_scrape($src['id'], 0);
            case 'drive_file':
                return $apiKey ? [drive_file_api($src['id'], $apiKey)] : [sp_item('drive', $src['id'])];
            case 'gphotos':
                return gphotos_album($src['url']);
            case 'immich':
                return immich_shared($src);
            case 'url':
                return [sp_item('url', $src['url'], ['name' => basename((string) parse_url($src['url'], PHP_URL_PATH))])];
            default:
                $errors[] = 'ลิงก์ไม่ถูกต้อง: ' . $src['url'];
                return [];
        }
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage() . ' — ' . $src['url'];
        return [];
    }
}

/** Read every source (one per line) and return unique items + errors. */
function collect_sources(string $text, ?string $apiKey = null): array
{
    @set_time_limit(300);
    $apiKey = trim((string) $apiKey) ?: null;
    $errors = [];
    $found  = [];
    foreach (preg_split('~\R~', $text) as $line) {
        $src = parse_source($line);
        if (!$src) continue;
        foreach (fetch_source($src, $apiKey, $errors) as $item) {
            $key = sha1($item['provider'] . '|' . $item['file_id']);
            if (!isset($found[$key])) $found[$key] = $item;
        }
    }
    return ['items' => $found, 'errors' => $errors];
}

/* ---------------- Google Drive: no API key (embedded folder view) ---------------- */

function drive_folder_scrape(string $folderId, int $depth, string $path = ''): array
{
    $html = http_get('https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folderId), $info);
    if ($html === null || ($info['http_code'] ?? 0) >= 400) {
        throw new RuntimeException('เปิดโฟลเดอร์ Google Drive ไม่ได้ ตรวจสอบว่าแชร์เป็น "ทุกคนที่มีลิงก์"');
    }
    if (!str_contains($html, 'flip-entry') && str_contains($html, 'accounts.google.com')) {
        throw new RuntimeException('โฟลเดอร์ Google Drive ยังไม่ได้แชร์แบบ "ทุกคนที่มีลิงก์"');
    }

    $files = [];
    $folders = [];
    $parts = preg_split('~<div class="flip-entry"~', $html);
    array_shift($parts);
    foreach ($parts as $chunk) {
        if (!preg_match('~id="entry-([A-Za-z0-9_-]+)"~', $chunk, $mId)) continue;
        $id = $mId[1];
        $title = '';
        if (preg_match('~class="flip-entry-title">(.*?)</div>~s', $chunk, $mT)) {
            $title = trim(html_entity_decode(strip_tags($mT[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('~href="[^"]*/folders/~', $chunk)) {
            $folders[] = [$id, $title];
            continue;
        }
        if ($title !== '' && !is_image_name($title)) continue;
        $files[] = sp_item('drive', $id, ['name' => $title, 'section' => $path]);
    }
    return drive_merge_sections($files, $folders, $depth, $path,
        fn($id, $sub) => drive_folder_scrape($id, $depth + 1, $sub));
}

/**
 * This folder's photos (by name) followed by each subfolder (by name), every
 * subfolder becoming a section such as "พิธีเช้า" or "พิธีเช้า / ครอบครัว".
 */
function drive_merge_sections(array $files, array $folders, int $depth, string $path, callable $recurse): array
{
    usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    usort($folders, fn($a, $b) => strnatcasecmp($a[1], $b[1]));
    $items = $files;
    if ($depth < 3) {
        foreach ($folders as [$id, $title]) {
            $sub = mb_substr(($path !== '' ? $path . ' / ' : '') . ($title !== '' ? $title : 'โฟลเดอร์'), 0, 190);
            array_push($items, ...$recurse($id, $sub));
        }
    }
    return $items;
}

/* ---------------- Google Drive API v3 (API key – sizes, dimensions, > 50 files) ---------------- */

function drive_api_item(array $f): array
{
    $w = (int) ($f['imageMediaMetadata']['width'] ?? 0);
    $h = (int) ($f['imageMediaMetadata']['height'] ?? 0);
    if (((int) ($f['imageMediaMetadata']['rotation'] ?? 0)) % 2 === 1) [$w, $h] = [$h, $w];
    return sp_item('drive', $f['id'], [
        'name' => $f['name'] ?? '', 'mime' => $f['mimeType'] ?? '',
        'size_bytes' => (int) ($f['size'] ?? 0), 'width' => $w, 'height' => $h,
    ]);
}

function drive_api_get(string $url): array
{
    $json = http_get($url, $info);
    $data = $json ? json_decode($json, true) : null;
    if (!is_array($data) || isset($data['error'])) {
        $msg = $data['error']['message'] ?? ('HTTP ' . ($info['http_code'] ?? '?'));
        throw new RuntimeException('Google Drive API: ' . $msg);
    }
    return $data;
}

function drive_file_api(string $id, string $key): array
{
    $data = drive_api_get('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?' . http_build_query([
        'key' => $key, 'supportsAllDrives' => 'true',
        'fields' => 'id,name,mimeType,size,imageMediaMetadata(width,height,rotation)',
    ]));
    return drive_api_item($data);
}

function drive_folder_api(string $folderId, string $key, int $depth, string $path = ''): array
{
    $files = [];
    $folders = [];
    $pageToken = '';
    do {
        $data = drive_api_get('https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q'         => "'" . $folderId . "' in parents and trashed = false",
            'key'       => $key,
            'pageSize'  => 1000,
            'fields'    => 'nextPageToken,files(id,name,mimeType,size,imageMediaMetadata(width,height,rotation))',
            'orderBy'   => 'name_natural',
            'pageToken' => $pageToken,
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]));
        foreach ($data['files'] ?? [] as $f) {
            if ($f['mimeType'] === 'application/vnd.google-apps.folder') {
                $folders[] = [$f['id'], (string) $f['name']];
                continue;
            }
            if (str_starts_with($f['mimeType'], 'image/')) $files[] = ['section' => $path] + drive_api_item($f);
        }
        $pageToken = $data['nextPageToken'] ?? '';
    } while ($pageToken !== '');
    return drive_merge_sections($files, $folders, $depth, $path,
        fn($id, $sub) => drive_folder_api($id, $key, $depth + 1, $sub));
}

/* ---------------- Google Photos shared album ---------------- */

function gphotos_album(string $url): array
{
    $html = http_get($url, $info, 45);
    if ($html === null || ($info['http_code'] ?? 0) >= 400) {
        throw new RuntimeException('เปิดอัลบั้ม Google Photos ไม่ได้ ตรวจสอบว่าเปิดการแชร์ลิงก์แล้ว');
    }
    $items = [];
    $seen = [];
    if (preg_match_all('~\["(AF1Qip[A-Za-z0-9_-]+)",\["(https://lh3\.googleusercontent\.com/pw/[A-Za-z0-9_-]+)",(\d+),(\d+)~', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $row) {
            if (isset($seen[$row[1]])) continue;
            $seen[$row[1]] = true;
            $items[] = sp_item('gphotos', $row[2], ['width' => (int) $row[3], 'height' => (int) $row[4]]);
        }
    }
    if (!$items && preg_match_all('~https://lh3\.googleusercontent\.com/pw/[A-Za-z0-9_-]{40,}~', $html, $m2)) {
        foreach (array_unique($m2[0]) as $u) $items[] = sp_item('gphotos', $u);
    }
    if (!$items) throw new RuntimeException('ไม่พบรูปภาพในอัลบั้ม Google Photos นี้');
    return $items;
}

/* ---------------- Immich shared link ---------------- */

function immich_shared(array $src): array
{
    $base = rtrim($src['base'], '/');
    $auth = rawurlencode($src['p']) . '=' . rawurlencode($src['v']);
    $hdr  = ['Accept: application/json'];

    $json = http_get($base . '/api/shared-links/me?' . $auth, $info, 30, $hdr);
    $data = $json ? json_decode($json, true) : null;
    $code = (int) ($info['http_code'] ?? 0);
    if ($code === 401) throw new RuntimeException('ลิงก์ Immich นี้ตั้งรหัสผ่านไว้ หรือหมดอายุแล้ว');
    if (!is_array($data) || $code >= 400) throw new RuntimeException('อ่านลิงก์ Immich ไม่ได้ (HTTP ' . $code . ')');

    $assets = $data['assets'] ?? [];
    if (!$assets && !empty($data['album']['id'])) {
        $json2 = http_get($base . '/api/albums/' . rawurlencode($data['album']['id']) . '?' . $auth, $info2, 60, $hdr);
        $album = $json2 ? json_decode($json2, true) : null;
        $assets = is_array($album) ? ($album['assets'] ?? []) : [];
    }

    $ref = json_encode(['base' => $base, 'p' => $src['p'], 'v' => $src['v']], JSON_UNESCAPED_SLASHES);
    $items = [];
    foreach ($assets as $a) {
        if (($a['type'] ?? 'IMAGE') !== 'IMAGE') continue;
        $ex = $a['exifInfo'] ?? [];
        $w = (int) ($ex['exifImageWidth'] ?? 0);
        $h = (int) ($ex['exifImageHeight'] ?? 0);
        if (in_array((string) ($ex['orientation'] ?? ''), ['5', '6', '7', '8'], true)) [$w, $h] = [$h, $w];
        $items[] = sp_item('immich', (string) $a['id'], [
            'ref' => $ref,
            'name' => (string) ($a['originalFileName'] ?? ''),
            'mime' => (string) ($a['originalMimeType'] ?? ''),
            'size_bytes' => (int) ($ex['fileSizeInByte'] ?? 0),
            'width' => $w, 'height' => $h,
        ]);
    }
    if (!$items) throw new RuntimeException('ไม่พบรูปภาพในลิงก์ Immich นี้');
    return $items;
}

/* ---------------- Sync an album with its sources ---------------- */

function sync_album(int $albumId): array
{
    $album = find_album($albumId);
    if (!$album) return ['count' => 0, 'added' => 0, 'removed' => 0, 'errors' => ['ไม่พบอัลบั้ม']];

    ['items' => $found, 'errors' => $errors] = collect_sources((string) $album['sources'], google_key_for($album['user_id'] ?? null));

    $pdo = db();
    $existing = [];
    $st = $pdo->prepare('SELECT id, file_hash FROM images WHERE album_id = ?');
    $st->execute([$albumId]);
    foreach ($st as $r) $existing[$r['file_hash']] = (int) $r['id'];

    $added = $removed = 0;
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO images(album_id, provider, file_id, file_hash, ref, name, mime, size_bytes, width, height, sort, section)
                              VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $upd = $pdo->prepare('UPDATE images SET ref = ?, name = ?, mime = ?,
                                size_bytes = IF(? > 0, ?, size_bytes),
                                width = IF(? > 0, ?, width), height = IF(? > 0, ?, height), sort = ?, section = ?
                              WHERE id = ?');
        $i = 0;
        foreach ($found as $hash => $it) {
            $i++;
            if (isset($existing[$hash])) {
                $upd->execute([$it['ref'], $it['name'], $it['mime'], $it['size_bytes'], $it['size_bytes'],
                               $it['width'], $it['width'], $it['height'], $it['height'], $i, $it['section'], $existing[$hash]]);
                unset($existing[$hash]);
            } else {
                $ins->execute([$albumId, $it['provider'], $it['file_id'], $hash, $it['ref'], $it['name'], $it['mime'],
                               $it['size_bytes'], $it['width'], $it['height'], $i, $it['section']]);
                $added++;
            }
        }
        // Only remove photos when every source was read successfully,
        // so a temporary Google error never wipes an album.
        if (!$errors && $existing) {
            $del = $pdo->prepare('DELETE FROM images WHERE id = ?');
            foreach ($existing as $id) { $del->execute([$id]); $removed++; }
            $pdo->prepare('UPDATE albums SET cover_image_id = NULL WHERE id = ? AND cover_image_id IS NOT NULL
                           AND cover_image_id NOT IN (SELECT id FROM images WHERE album_id = ?)')
                ->execute([$albumId, $albumId]);
        }
        $c = count_images($albumId);
        $pdo->prepare('UPDATE albums SET synced_at = NOW(), sync_note = ?, total_bytes = ? WHERE id = ?')
            ->execute([$errors ? implode("\n", $errors) : '', $c['bytes'], $albumId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return ['count' => count($found), 'added' => $added, 'removed' => $removed, 'errors' => $errors];
}
