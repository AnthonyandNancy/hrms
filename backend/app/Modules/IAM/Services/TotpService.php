<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

/**
 * Dependency-free RFC 6238 TOTP using HMAC-SHA1, 30-second steps and a
 * configurable digit count. Base32 is implemented locally to avoid pulling a
 * dependency into the security core.
 */
final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function code(string $base32Secret, ?int $timestamp = null, ?int $digits = null): string
    {
        $digits ??= $this->setting('iam.mfa.totp_digits', 6);
        $step = $this->setting('iam.mfa.totp_step_seconds', 30);
        $timestamp ??= time();
        $counter = intdiv($timestamp, $step);

        $key = $this->base32Decode($base32Secret);

        if ($key === null || $key === '') {
            return str_repeat('0', $digits);
        }

        $binaryCounter = pack('J', $counter);
        $hmac = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hmac[19]) & 0x0F;
        $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        $modulo = 10 ** $digits;

        return str_pad((string) ($binary % $modulo), $digits, '0', STR_PAD_LEFT);
    }

    public function verify(string $base32Secret, string $code, ?int $timestamp = null): bool
    {
        if (preg_match('/^\d{6,8}$/', $code) !== 1) {
            return false;
        }

        $decoded = $this->base32Decode($base32Secret);

        if ($decoded === null || $decoded === '') {
            return false;
        }

        $timestamp ??= time();
        $window = $this->setting('iam.mfa.totp_window', 1);
        $step = $this->setting('iam.mfa.totp_step_seconds', 30);
        $digits = strlen($code);
        $matched = false;

        foreach (range(-$window, $window) as $offset) {
            $candidate = $this->code($base32Secret, $timestamp + ($offset * $step), $digits);

            // Evaluate all windows to keep timing independent of where a match occurs.
            $matched = hash_equals($candidate, $code) || $matched;
        }

        return $matched;
    }

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    private function setting(string $key, int $default): int
    {
        if (! function_exists('app') || ! function_exists('config')) {
            return $default;
        }

        try {
            if (! app()->bound('config')) {
                return $default;
            }

            return (int) config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function base32Encode(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($raw) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    public function base32Decode(string $encoded): ?string
    {
        $encoded = strtoupper(preg_replace('/\s+/', '', $encoded) ?? '');

        if ($encoded === '') {
            return '';
        }

        if (preg_match('/^[A-Z2-7]+=*$/', $encoded) !== 1) {
            return null;
        }

        $encoded = rtrim($encoded, '=');
        $bits = '';

        foreach (str_split($encoded) as $character) {
            $value = strpos(self::ALPHABET, $character);

            if ($value === false) {
                return null;
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $raw = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $raw .= chr(bindec($byte));
            }
        }

        return $raw;
    }

    public function provisioningUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer.':'.$account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => $this->setting('iam.mfa.totp_digits', 6),
            'period' => $this->setting('iam.mfa.totp_step_seconds', 30),
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/'.$label.'?'.$query;
    }
}
