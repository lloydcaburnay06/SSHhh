<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Minimal Web Push sender (RFC 8030 / RFC 8291 aes128gcm / RFC 8292 VAPID) using only ext-openssl.
 * Stores browser push subscriptions and the server's VAPID key pair.
 */
final class WebPush
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';


    public function __construct(private PDO $pdo, private SettingsRepository $settings)
    {
    }

    /** Public VAPID key (base64url, uncompressed P-256 point) for PushManager.subscribe(). */
    public function publicKey(): string
    {
        $this->ensureKeys();
        return (string) $this->settings->get('push_vapid_public', '');
    }

    /**
     * @param array{endpoint?: string, keys?: array{p256dh?: string, auth?: string}} $sub
     */
    public function saveSubscription(array $sub, string $userAgent): void
    {
        $endpoint = (string) ($sub['endpoint'] ?? '');
        $p256dh = (string) ($sub['keys']['p256dh'] ?? '');
        $auth = (string) ($sub['keys']['auth'] ?? '');
        if (!preg_match('#^https://[^\s]+$#', $endpoint) || strlen(self::b64urlDecode($p256dh)) !== 65 || strlen(self::b64urlDecode($auth)) !== 16) {
            throw new \InvalidArgumentException('Invalid push subscription from the browser.');
        }
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO `push_subscriptions` (`endpoint_hash`, `endpoint`, `p256dh`, `auth`, `user_agent`, `created_at`)
            VALUES (:h, :e, :p, :a, :ua, NOW())
            ON DUPLICATE KEY UPDATE `p256dh` = VALUES(`p256dh`), `auth` = VALUES(`auth`), `user_agent` = VALUES(`user_agent`), `fail_count` = 0
        SQL)->execute([
            ':h' => hash('sha256', $endpoint), ':e' => $endpoint, ':p' => $p256dh, ':a' => $auth,
            ':ua' => mb_substr($userAgent, 0, 255),
        ]);
    }

    public function removeSubscription(string $endpoint): void
    {
        $this->pdo->prepare('DELETE FROM `push_subscriptions` WHERE `endpoint_hash` = :h')->execute([':h' => hash('sha256', $endpoint)]);
    }

    /**
     * @return list<array{id: int, device: string, created_at: string, last_ok_at: ?string}>
     */
    public function listDevices(): array
    {
        $rows = $this->pdo->query('SELECT `id`, `user_agent`, `created_at`, `last_ok_at` FROM `push_subscriptions` ORDER BY `id`')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'device'     => self::describeAgent((string) $r['user_agent']),
            'created_at' => (string) $r['created_at'],
            'last_ok_at' => $r['last_ok_at'] !== null ? (string) $r['last_ok_at'] : null,
        ], $rows);
    }

    public function removeDevice(int $id): void
    {
        $this->pdo->prepare('DELETE FROM `push_subscriptions` WHERE `id` = :id')->execute([':id' => $id]);
    }

    /**
     * Sends a notification payload (JSON) to every saved device.
     *
     * @param array<string, mixed> $payload
     * @return array{sent: int, failed: int, removed: int, errors: list<string>}
     */
    public function sendToAll(array $payload, int $ttlSeconds = 1200): array
    {
        $this->ensureKeys();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = ['sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => []];

        foreach ($this->pdo->query('SELECT * FROM `push_subscriptions`')->fetchAll(PDO::FETCH_ASSOC) as $sub) {
            try {
                $code = $this->sendOne((string) $sub['endpoint'], (string) $sub['p256dh'], (string) $sub['auth'], (string) $json, $ttlSeconds);
                if ($code >= 200 && $code < 300) {
                    $out['sent']++;
                    $this->pdo->prepare('UPDATE `push_subscriptions` SET `last_ok_at` = NOW(), `fail_count` = 0 WHERE `id` = :id')->execute([':id' => $sub['id']]);
                } elseif ($code === 404 || $code === 410) {
                    // Subscription expired or the user turned notifications off on that device
                    $out['removed']++;
                    $this->pdo->prepare('DELETE FROM `push_subscriptions` WHERE `id` = :id')->execute([':id' => $sub['id']]);
                } else {
                    $out['failed']++;
                    $out['errors'][] = "HTTP {$code}";
                    $this->pdo->prepare('UPDATE `push_subscriptions` SET `fail_count` = `fail_count` + 1 WHERE `id` = :id')->execute([':id' => $sub['id']]);
                }
            } catch (Throwable $e) {
                $out['failed']++;
                $out['errors'][] = $e->getMessage();
            }
        }
        return $out;
    }

    private function sendOne(string $endpoint, string $p256dh, string $auth, string $payload, int $ttl): int
    {
        $body = self::encrypt($payload, self::b64urlDecode($p256dh), self::b64urlDecode($auth));

        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $jwt = self::vapidJwt($audience, (string) $this->settings->get('push_vapid_private_pem', ''), 'mailto:admin@example.com');

        $ch = curl_init($endpoint);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: vapid t=' . $jwt . ', k=' . $this->publicKey(),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: ' . $ttl,
                'Urgency: high',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if (class_exists(\Composer\CaBundle\CaBundle::class)) {
            $ca = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
            $opts[is_dir($ca) ? CURLOPT_CAPATH : CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            throw new RuntimeException("Push service unreachable ({$err})");
        }
        return $code;
    }

    /**
     * RFC 8291 message encryption (single aes128gcm record).
     * $asPrivatePem / $salt are only passed in tests (RFC 8291 §5 test vector).
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $asPrivatePem = null, ?string $salt = null): string
    {
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new RuntimeException('Invalid subscription keys.');
        }
        $asKey = openssl_pkey_get_private($asPrivatePem ?? self::newP256KeyPem());
        if ($asKey === false) {
            throw new RuntimeException('Could not load the EC key (OpenSSL).');
        }
        $asDetails = openssl_pkey_get_details($asKey);
        $asPublic = "\x04" . str_pad($asDetails['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($asDetails['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $uaKey = openssl_pkey_get_public(self::rawP256ToPem($uaPublic));
        $ecdhSecret = $uaKey !== false ? openssl_pkey_derive($uaKey, $asKey, 32) : false;
        if ($ecdhSecret === false) {
            throw new RuntimeException('ECDH key agreement failed.');
        }

        $salt ??= random_bytes(16);
        $ikm = hash_hkdf('sha256', $ecdhSecret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('AES-GCM encryption failed.');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** RFC 8292 VAPID JWT, ES256. */
    public static function vapidJwt(string $audience, string $privatePem, string $subject): string
    {
        $header = self::b64urlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64urlEncode((string) json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
        $input = $header . '.' . $claims;
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the VAPID token.');
        }
        return $input . '.' . self::b64urlEncode(self::derToRawSignature($der));
    }

    /**
     * New P-256 private key as PEM. Uses OpenSSL when it can generate keys; otherwise phpseclib
     * (pure PHP). XAMPP on Windows often has no openssl.cnf, which makes openssl_pkey_new() fail.
     */
    public static function newP256KeyPem(): string
    {
        $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key !== false && @openssl_pkey_export($key, $pem) && is_string($pem) && $pem !== '') {
            return $pem;
        }
        while (openssl_error_string() !== false) {
            // clear OpenSSL's error queue so later calls aren't confused by these failures
        }
        return \phpseclib3\Crypt\EC::createKey('nistp256')->toString('PKCS8');
    }

    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $d === false ? '' : $d;
    }

    public static function rawP256ToPem(string $raw): string
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** ECDSA DER (SEQUENCE{r,s}) → 64-byte r||s as JWS requires. */
    private static function derToRawSignature(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $read = static function () use ($der, &$pos): string {
            $len = ord($der[$pos + 1]);
            $val = substr($der, $pos + 2, $len);
            $pos += 2 + $len;
            return str_pad(ltrim($val, "\0"), 32, "\0", STR_PAD_LEFT);
        };
        return $read() . $read();
    }

    private static function describeAgent(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone/iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            default => 'Device',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'browser',
        };
        return "{$browser} on {$os}";
    }

    private function ensureKeys(): void
    {
        if ((string) $this->settings->get('push_vapid_public', '') !== '' && (string) $this->settings->get('push_vapid_private_pem', '') !== '') {
            return;
        }
        $pem = self::newP256KeyPem();
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new RuntimeException('Could not generate VAPID keys.');
        }
        $d = openssl_pkey_get_details($key);
        $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $this->settings->set('push_vapid_private_pem', $pem, true);
        $this->settings->set('push_vapid_public', self::b64urlEncode($pub), false);
    }
}
