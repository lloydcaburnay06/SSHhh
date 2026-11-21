<?php

declare(strict_types=1);

namespace Deployer\Tests;

use Deployer\WebPush;
use PHPUnit\Framework\TestCase;

final class WebPushTest extends TestCase
{
    public function testEncryptionMatchesRfc8291TestVector(): void
    {
        $d = WebPush::b64urlDecode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
        $key = @openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1', 'd' => $d]]);
        if ($key === false || !openssl_pkey_export($key, $pem)) {
            self::markTestSkipped('This PHP/OpenSSL build cannot import a raw EC private key (needed only for the fixed test vector).');
        }

        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            WebPush::b64urlDecode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            WebPush::b64urlDecode('BTBZMqHH6r4Tts7J_aSIgg'),
            $pem,
            WebPush::b64urlDecode('DGv6ra1nlYgDCS1FRnbzlw')
        );

        self::assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::b64urlEncode($body)
        );
    }

    public function testVapidJwtIsSignedWithEs256(): void
    {
        $pem = WebPush::newP256KeyPem();
        $key = openssl_pkey_get_private($pem);
        $jwt = WebPush::vapidJwt('https://fcm.googleapis.com', $pem, 'mailto:test@example.com');
        [$h, $c, $s] = explode('.', $jwt);

        self::assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::b64urlDecode($h), true));
        self::assertSame('https://fcm.googleapis.com', json_decode(WebPush::b64urlDecode($c), true)['aud']);
        $raw = WebPush::b64urlDecode($s);
        self::assertSame(64, strlen($raw));

        $int = static function (string $b): string {
            $b = ltrim($b, "\0");
            return (ord($b[0]) & 0x80) ? "\0" . $b : $b;
        };
        $r = $int(substr($raw, 0, 32));
        $sv = $int(substr($raw, 32));
        $der = "\x30" . chr(4 + strlen($r) + strlen($sv)) . "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($sv)) . $sv;
        self::assertSame(1, openssl_verify("{$h}.{$c}", $der, openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));
    }

    public function testPhpseclibKeysWorkForSigningAndEncryption(): void
    {
        // The fallback used when OpenSSL can't generate keys (XAMPP on Windows without openssl.cnf)
        $serverPem = \phpseclib3\Crypt\EC::createKey('nistp256')->toString('PKCS8');
        $jwt = WebPush::vapidJwt('https://web.push.apple.com', $serverPem, 'mailto:admin@example.com');
        self::assertCount(3, explode('.', $jwt));

        // Fake browser subscription, then decrypt what the server sends (RFC 8291 receiver side)
        $uaPem = \phpseclib3\Crypt\EC::createKey('nistp256')->toString('PKCS8');
        $ua = openssl_pkey_get_details(openssl_pkey_get_private($uaPem))['ec'];
        $uaPub = "\x04" . str_pad($ua['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ua['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);

        $body = WebPush::encrypt('{"title":"hi"}', $uaPub, $auth, WebPush::newP256KeyPem());

        $salt = substr($body, 0, 16);
        $asPub = substr($body, 21, ord($body[20]));
        $ct = substr($body, 21 + ord($body[20]));
        $ecdh = openssl_pkey_derive(openssl_pkey_get_public(WebPush::rawP256ToPem($asPub)), openssl_pkey_get_private($uaPem), 32);
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
        $plain = openssl_decrypt(
            substr($ct, 0, -16), 'aes-128-gcm',
            hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt), OPENSSL_RAW_DATA,
            hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt), substr($ct, -16)
        );
        self::assertSame("{\"title\":\"hi\"}\x02", $plain);
    }
}
