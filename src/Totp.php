<?php

declare(strict_types=1);

namespace Deployer;

use chillerlan\QRCode\QRCode;
use InvalidArgumentException;

final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generates a cryptographically random Base32 secret string (RFC 6238 recommends 160 bits = 20 bytes).
     */
    public static function generateSecret(int $byteLength = 20): string
    {
        return self::base32Encode(random_bytes($byteLength));
    }

    /**
     * Encodes raw binary bytes to Base32 without padding.
     */
    public static function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $chars = '';
        $buffer = 0;
        $bitsLeft = 0;

        $length = strlen($binary);
        for ($i = 0; $i < $length; $i++) {
            $buffer = ($buffer << 8) | ord($binary[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $index = ($buffer >> $bitsLeft) & 0x1F;
                $chars .= self::BASE32_ALPHABET[$index];
            }
        }

        if ($bitsLeft > 0) {
            $index = ($buffer << (5 - $bitsLeft)) & 0x1F;
            $chars .= self::BASE32_ALPHABET[$index];
        }

        return $chars;
    }

    /**
     * Decodes a Base32 string back to raw binary bytes.
     */
    public static function base32Decode(string $base32): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32) ?? '');
        if ($clean === '') {
            return '';
        }

        $binary = '';
        $buffer = 0;
        $bitsLeft = 0;

        $length = strlen($clean);
        for ($i = 0; $i < $length; $i++) {
            $val = strpos(self::BASE32_ALPHABET, $clean[$i]);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $binary;
    }

    /**
     * Calculates the 6-digit TOTP code for a given timestamp.
     */
    public static function calculateCode(string $base32Secret, ?int $timestamp = null, int $timeStep = 30): string
    {
        $timestamp = $timestamp ?? time();
        $counter = intdiv($timestamp, $timeStep);

        $secretBytes = self::base32Decode($base32Secret);
        if ($secretBytes === '') {
            throw new InvalidArgumentException('Invalid or empty Base32 secret.');
        }

        // 64-bit big-endian counter
        $packedCounter = pack('J', $counter);
        $hash = hash_hmac('sha1', $packedCounter, $secretBytes, true);

        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $code = $truncated % 1_000_000;
        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verifies a 6-digit code against the secret, allowing a drift of ±$discrepancy time steps (default 1 = ±30s).
     */
    public static function verify(string $base32Secret, string $code, int $discrepancy = 1, ?int $timestamp = null, int $timeStep = 30): bool
    {
        $cleanCode = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($cleanCode) !== 6) {
            return false;
        }

        $timestamp = $timestamp ?? time();

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $windowTime = $timestamp + ($i * $timeStep);
            $expected = self::calculateCode($base32Secret, $windowTime, $timeStep);
            if (hash_equals($expected, $cleanCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alias for verify()
     */
    public static function verifyCode(string $base32Secret, string $code, int $discrepancy = 1, ?int $timestamp = null, int $timeStep = 30): bool
    {
        return self::verify($base32Secret, $code, $discrepancy, $timestamp, $timeStep);
    }

    /**
     * Generates the standard otpauth:// URI recognized by Google Authenticator.
     */
    public static function getProvisioningUri(string $base32Secret, string $account = 'Admin', string $issuer = 'SSHhh'): string
    {
        $cleanSecret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32Secret) ?? '');
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            $cleanSecret,
            rawurlencode($issuer)
        );
    }

    /**
     * Generates a self-contained SVG Data URI for the QR Code.
     */
    public static function getQrCodeDataUri(string $otpauthUri): string
    {
        return (new QRCode())->render($otpauthUri);
    }

    /**
     * Formats a secret string into readable 4-character chunks for manual entry.
     */
    public static function formatSecretForDisplay(string $base32Secret): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32Secret) ?? '');
        return trim(chunk_split($clean, 4, ' '));
    }
}
