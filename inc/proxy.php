<?php
declare(strict_types=1);

/**
 * Fetch an image fully into memory (used for cached previews and ZIP files).
 * Returns [bytes, mime] or null when the provider does not return an image.
 */
function fetch_image_bytes(string $url, int $timeout = 120): ?array
{
    $body = http_get($url, $info, $timeout, ['Accept: image/*,*/*;q=0.8']);
    $code = (int) ($info['http_code'] ?? 0);
    $type = strtolower(trim(explode(';', (string) ($info['content_type'] ?? ''))[0]));
    if ($body === null || $code >= 400 || $body === '') return null;
    if (str_starts_with($type, 'text/') || $type === 'application/json') return null;
    if ($type === '' || $type === 'application/octet-stream' || $type === 'binary/octet-stream') {
        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: 'application/octet-stream';
    }
    return [$body, $type];
}

/**
 * Last resort when Google has no thumbnail for a file: download the original
 * and shrink it with GD (if the extension is enabled; otherwise use it as-is).
 */
function preview_from_original(array $img, string $size): ?array
{
    $got = fetch_image_bytes(upstream_url($img, 'full'), 180);
    if (!$got || !function_exists('imagecreatefromstring')) return $got;

    $src = @imagecreatefromstring($got[0]);
    if (!$src) return $got;
    if (function_exists('exif_read_data') && $got[1] === 'image/jpeg') {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode(substr($got[0], 0, 131072)));
        $rot = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($rot) $src = imagerotate($src, $rot, 0);
    }
    $max = $size === 'thumb' ? 800 : 2048;
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w > $max) {
        $nh = (int) round($h * $max / $w);
        $dst = imagecreatetruecolor($max, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $max, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }
    ob_start();
    imagejpeg($src, null, 84);
    imagedestroy($src);
    return [(string) ob_get_clean(), 'image/jpeg'];
}

/* ---------------- preview cache (data/cache) ---------------- */

function cache_path(array $img, string $size): string
{
    $key = sha1($img['provider'] . '|' . $img['file_id'] . '|' . $size);
    return CACHE_DIR . '/' . substr($key, 0, 2) . '/' . $key;
}

function cache_store(array $img, string $size, string $bytes, string $mime): void
{
    $file = cache_path($img, $size);
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
    file_put_contents($file . '.tmp', $bytes);
    file_put_contents($file . '.type', $mime);
    @rename($file . '.tmp', $file);
    if ($size === 'thumb' && !empty($img['id']) && empty($img['width'])) {
        $d = @getimagesizefromstring($bytes);
        if ($d) save_image_ratio((int) $img['id'], (int) $d[0], (int) $d[1]);
    }
}

/** Remember an image's proportions (from its thumbnail) so the gallery can lay it out uncropped. */
function save_image_ratio(int $imageId, int $w, int $h): void
{
    if ($w > 0 && $h > 0) {
        db()->prepare('UPDATE images SET width = ?, height = ? WHERE id = ? AND width = 0')->execute([$w, $h, $imageId]);
    }
}

/** Fill in missing proportions from thumbnails that are already cached. */
function backfill_ratios(array $images): int
{
    $n = 0;
    foreach ($images as $im) {
        if (!empty($im['width'])) continue;
        $f = cache_path($im, 'thumb');
        if (is_file($f) && ($d = @getimagesize($f))) {
            save_image_ratio((int) $im['id'], (int) $d[0], (int) $d[1]);
            $n++;
        }
    }
    return $n;
}

/**
 * Download many previews in parallel into the cache, so clients see
 * thumbnails instantly the first time they open the gallery.
 * @return array{ok:int, failed:int}
 */
function warm_cache(array $images, string $size = 'thumb', int $concurrency = 12): array
{
    $todo = array_values(array_filter($images, fn($im) => !is_file(cache_path($im, $size))));
    $ok = $failed = 0;
    $fromOriginal = [];
    $mh = curl_multi_init();
    $running = [];
    $next = 0;
    $add = function (array $im, string $url) use ($mh, &$running) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_MAXREDIRS       => 8,
            CURLOPT_CONNECTTIMEOUT  => 15,
            CURLOPT_TIMEOUT         => 60,
            CURLOPT_USERAGENT       => SP_USER_AGENT,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        curl_multi_add_handle($mh, $ch);
        $running[(int) $ch] = ['ch' => $ch, 'img' => $im, 'url' => $url];
    };

    while ($next < count($todo) || $running) {
        while ($next < count($todo) && count($running) < $concurrency) {
            $im = $todo[$next++];
            $add($im, upstream_url($im, $size));
        }
        curl_multi_exec($mh, $active);
        curl_multi_select($mh, 0.5);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $job = $running[(int) $ch];
            unset($running[(int) $ch]);
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($body && $code < 400 && str_starts_with($type, 'image/')) {
                cache_store($job['img'], $size, $body, $type);
                $ok++;
            } elseif (($fb = upstream_fallback($job['img'], $size)) && $job['url'] !== $fb) {
                $add($job['img'], $fb);   // retry once via Drive's other thumbnail endpoint
            } else {
                $fromOriginal[] = $job['img'];
            }
        }
    }
    curl_multi_close($mh);

    // Google had no thumbnail for these: build one from the original file.
    foreach ($fromOriginal as $im) {
        if ($got = preview_from_original($im, $size)) {
            cache_store($im, $size, $got[0], $got[1]);
            $ok++;
        } else {
            $failed++;
        }
    }
    return ['ok' => $ok, 'failed' => $failed];
}

/**
 * Stream a remote file straight to the browser (originals can be large).
 * Returns false if nothing was sent (so the caller can show an error).
 */
function stream_remote(string $url, string $downloadName, bool $attachment = true): bool
{
    @set_time_limit(0);
    while (ob_get_level() > 0) ob_end_clean();

    $status = 0;
    $type = '';
    $length = null;
    $started = false;
    $failed = false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 8,
        CURLOPT_CONNECTTIMEOUT  => 15,
        CURLOPT_TIMEOUT         => 0,
        CURLOPT_USERAGENT       => SP_USER_AGENT,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION  => function ($ch, $line) use (&$status, &$type, &$length) {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) {
                $status = (int) $m[1];
                $type = '';
                $length = null;
            } elseif (stripos($line, 'content-type:') === 0) {
                $type = strtolower(trim(explode(';', substr($line, 13))[0]));
            } elseif (stripos($line, 'content-length:') === 0) {
                $length = (int) trim(substr($line, 15));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$started, &$failed, &$status, &$type, &$length, $downloadName, $attachment) {
            if (!$started) {
                if ($status >= 400 || str_starts_with($type, 'text/') || $type === 'application/json') {
                    $failed = true;
                    return -1;
                }
                if ($type === '' || str_contains($type, 'octet-stream')) {
                    $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($chunk) ?: 'application/octet-stream';
                }
                $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName) ?: 'photo.jpg';
                header('Content-Type: ' . $type);
                header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
                header('Cache-Control: private, no-store');
                header('X-Robots-Tag: noindex, nofollow');
                if ($length) header('Content-Length: ' . $length);
                $started = true;
            }
            echo $chunk;
            flush();
            return connection_aborted() ? -1 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
    return $started && !$failed;
}
