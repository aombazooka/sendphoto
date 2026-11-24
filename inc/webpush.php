<?php
/**
 * Web Push (RFC 8030 / 8291 / 8292) without external libraries:
 * notifications reach the photographer's browser even when the admin is closed.
 *
 *   settings.vapid_public / vapid_private   server key pair (created on first use)
 *   push_subs                               one row per browser that allowed notifications
 */
declare(strict_types=1);

function migrate_webpush(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subs (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        endpoint   VARCHAR(700) NOT NULL,
        ep_hash    CHAR(40) NOT NULL,
        p256dh     VARCHAR(120) NOT NULL,
        auth       VARCHAR(60) NOT NULL,
        agent      VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ep (ep_hash),
        KEY idx_user (user_id),
        CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function b64u_enc(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Raw P-256 point (65 bytes, 0x04|x|y) → PEM public key that OpenSSL understands. */
function p256_raw_to_pem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** On Windows (XAMPP) OpenSSL can't find its config file by itself. */
function openssl_cfg(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    foreach ([getenv('OPENSSL_CONF') ?: '', PHP_BINDIR . '/extras/ssl/openssl.cnf', dirname((string) PHP_BINARY) . '/extras/ssl/openssl.cnf',
              'C:/xampp/php/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf'] as $f) {
        if ($f !== '' && is_file($f)) return $cfg = ['config' => $f];
    }
    return $cfg = [];
}

function p256_new_key(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC] + openssl_cfg());
    if (!$key) throw new RuntimeException('OpenSSL สร้างคีย์ไม่ได้');
    $d = openssl_pkey_get_details($key);
    $raw = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    openssl_pkey_export($key, $pem, null, openssl_cfg());
    return ['key' => $key, 'pem' => $pem, 'raw' => $raw];
}

/** The server's VAPID key pair (made once and kept in settings). */
function vapid_keys(): array
{
    $pub = setting('vapid_public');
    $prv = setting('vapid_private');
    if (!$pub || !$prv) {
        $k = p256_new_key();
        set_setting('vapid_public', b64u_enc($k['raw']));
        set_setting('vapid_private', $k['pem']);
        $pub = b64u_enc($k['raw']);
        $prv = $k['pem'];
    }
    return ['public' => $pub, 'private' => $prv];
}

/** ECDSA DER signature → the raw r|s (64 bytes) that JWT ES256 needs. */
function der_to_raw_sig(string $der): string
{
    $pos = 2;                                   // SEQUENCE header (short form for P-256)
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $pos += 2 + $len;
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

function vapid_header(string $endpoint): string
{
    $k = vapid_keys();
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $sub = trim((string) setting('vapid_subject', '')) ?: abs_url('');
    if (!preg_match('~^(mailto:|https?://)~', $sub)) $sub = 'mailto:' . $sub;
    $jwtIn = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.'
           . b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
    openssl_sign($jwtIn, $der, $k['private'], OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $jwtIn . '.' . b64u_enc(der_to_raw_sig($der)) . ', k=' . $k['public'];
}

/** Encrypt a payload for one subscription (RFC 8291, aes128gcm). */
function webpush_encrypt(string $payload, string $p256dhB64, string $authB64): string
{
    $uaPub = b64u_dec($p256dhB64);
    $auth  = b64u_dec($authB64);
    if (strlen($uaPub) !== 65 || strlen($auth) !== 16) throw new RuntimeException('subscription keys ไม่ถูกต้อง');

    $as = p256_new_key();
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(p256_raw_to_pem($uaPub)), $as['key'], 32);
    if ($ecdh === false) throw new RuntimeException('ECDH ไม่สำเร็จ');

    $ikm   = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPub . $as['raw'], $auth);
    $salt  = random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $as['raw'] . $ct . $tag;
}

/** Send to one subscription. Returns the push service's HTTP status (201 = accepted). */
function webpush_send(array $sub, array $message): int
{
    $body = webpush_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sub['p256dh'], $sub['auth']);
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: high',
            'Authorization: ' . vapid_header($sub['endpoint']),
        ],
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** Push to every browser of a user; forgets subscriptions the push service says are gone. */
function webpush_user(int $userId, array $message): ?string
{
    $st = db()->prepare('SELECT * FROM push_subs WHERE user_id = ?');
    $st->execute([$userId]);
    $subs = $st->fetchAll();
    if (!$subs) return null;
    $ok = 0;
    foreach ($subs as $s) {
        try {
            $code = webpush_send($s, $message);
        } catch (Throwable $ex) {
            $code = 0;
        }
        if ($code >= 200 && $code < 300) $ok++;
        elseif (in_array($code, [403, 404, 410], true)) db()->prepare('DELETE FROM push_subs WHERE id = ?')->execute([(int) $s['id']]);
    }
    return 'Web Push ' . ($ok ? '✓' : '✗') . ($ok < count($subs) ? ' (' . $ok . '/' . count($subs) . ')' : '');
}
