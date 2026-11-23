<?php

declare(strict_types=1);

namespace App\Modules\Platform\Logging;

/**
 * Deterministic redaction for log context and exception messages. Keys are
 * matched case-insensitively at every nesting depth. Values that look like
 * Chinese resident IDs or bank-card numbers are masked even when the key is
 * innocuous, because free-text upstream systems often stuff them into notes.
 */
final class SensitiveDataRedactor
{
    public const REDACTED = '[REDACTED]';

    /** @var list<string> */
    public const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'password_hash',
        'token',
        'access_token',
        'refresh_token',
        'session_token',
        'authorization',
        'cookie',
        'secret',
        'api_key',
        'private_key',
        'id_number',
        'id_number_ciphertext',
        'national_id',
        'identity_number',
        'bank_account',
        'bank_account_number',
        'card_number',
        'cvv',
        'mobile',
        'phone',
        'email',
        'address',
        'date_of_birth',
        'birth_date',
        'salary',
        'health',
        'biometric',
    ];

    /**
     * @param  array<string|int, mixed>  $context
     * @return array<string|int, mixed>
     */
    public function context(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->context($value);

                continue;
            }

            if (is_string($value)) {
                $clean[$key] = $this->maskString($value);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    public function message(string $message): string
    {
        $masked = preg_replace('/((?:[?&]|\b)(?:token|key|secret|password)[=:]\s*)[^&\s,;]+/i', '$1'.self::REDACTED, $message) ?? $message;
        $masked = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', '[EMAIL]', $masked) ?? $masked;
        $masked = preg_replace('/\b1[3-9]\d{9}\b/', '[MOBILE]', $masked) ?? $masked;

        return $this->maskString($masked);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, self::SENSITIVE_KEYS, true);
    }

    private function maskString(string $value): string
    {
        // Chinese resident identity number, 18 chars with X checksum.
        $value = preg_replace('/\b\d{17}[\dXx]\b/', '[ID_NUMBER]', $value) ?? $value;
        // Bank card-like 16-19 digit runs with optional separators.
        $value = preg_replace('/\b(?:\d[ -]?){15,18}\d\b/', '[CARD_NUMBER]', $value) ?? $value;

        return $value;
    }
}
