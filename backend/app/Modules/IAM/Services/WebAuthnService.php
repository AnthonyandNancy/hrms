<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Shared\Exceptions\ValidationException;
use Illuminate\Support\Facades\Cache;

/**
 * WebAuthn (Level 2) ceremony verifier. This is a real challenge verifier:
 * it checks type, challenge, origin and rpIdHash. It deliberately does not
 * pretend to validate assertion signatures without a bound credential; that
 * requires a stored COSE public key and is delegated to the credential service.
 */
final class WebAuthnService
{
    /**
     * @return array{challenge: string, public_key: array<string, mixed>}
     */
    public function beginRegistration(int $tenantId, int $userId): array
    {
        $challenge = $this->base64UrlEncode(random_bytes(32));
        Cache::put($this->cacheKey($tenantId, $userId, 'register'), $challenge, (int) config('iam.mfa.webauthn_challenge_ttl_seconds', 300));

        return [
            'challenge' => $challenge,
            'public_key' => [
                'rp' => ['id' => (string) config('iam.mfa.webauthn_rp_id', 'localhost'), 'name' => 'HRMS'],
                'user' => [
                    'id' => $this->base64UrlEncode(pack('J', $userId)),
                    'name' => 'user-'.$userId,
                    'displayName' => 'User '.$userId,
                ],
                'challenge' => $challenge,
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                    ['type' => 'public-key', 'alg' => -257],
                ],
                'timeout' => 300000,
                'attestation' => 'none',
                'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
                'challenge_type' => 'webauthn.create',
            ],
        ];
    }

    /**
     * @return array{challenge: string, public_key: array<string, mixed>}
     */
    public function beginAssertion(int $tenantId, int $userId): array
    {
        $challenge = $this->base64UrlEncode(random_bytes(32));
        Cache::put($this->cacheKey($tenantId, $userId, 'assert'), $challenge, (int) config('iam.mfa.webauthn_challenge_ttl_seconds', 300));

        return [
            'challenge' => $challenge,
            'public_key' => [
                'challenge' => $challenge,
                'rpId' => (string) config('iam.mfa.webauthn_rp_id', 'localhost'),
                'timeout' => 300000,
                'userVerification' => 'preferred',
                'challenge_type' => 'webauthn.get',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyRegistrationClientData(int $tenantId, int $userId, string $clientDataJson): array
    {
        return $this->verifyClientData($tenantId, $userId, 'register', 'webauthn.create', $clientDataJson);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyAssertionClientData(int $tenantId, int $userId, string $clientDataJson): array
    {
        return $this->verifyClientData($tenantId, $userId, 'assert', 'webauthn.get', $clientDataJson);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyClientData(int $tenantId, int $userId, string $ceremony, string $expectedType, string $clientDataJson): array
    {
        $key = $this->cacheKey($tenantId, $userId, $ceremony);
        $expectedChallenge = Cache::get($key);

        if (! is_string($expectedChallenge) || $expectedChallenge === '') {
            throw $this->invalid('The WebAuthn challenge has expired.');
        }

        $decoded = $this->base64UrlDecode($clientDataJson);
        $data = is_string($decoded) ? json_decode($decoded, true) : null;

        if (! is_array($data)) {
            throw $this->invalid('The WebAuthn client data is malformed.');
        }

        $expectedOrigin = rtrim((string) config('iam.mfa.webauthn_origin', 'http://localhost'), '/');
        $origin = is_string($data['origin'] ?? null) ? rtrim($data['origin'], '/') : '';

        if (($data['type'] ?? null) !== $expectedType
            || ! is_string($data['challenge'] ?? null)
            || ! hash_equals($expectedChallenge, (string) $data['challenge'])
            || ! hash_equals($expectedOrigin, $origin)
            || ($data['crossOrigin'] ?? false) !== false) {
            throw $this->invalid('The WebAuthn client data did not match the challenge, type or origin.');
        }

        // Consume the challenge only after every check passes.
        Cache::forget($key);

        return $data;
    }

    /**
     * Verify a raw authenticatorData rpIdHash. Used when a credential is bound.
     */
    public function verifyRpIdHash(string $authenticatorData): bool
    {
        if (strlen($authenticatorData) < 37) {
            return false;
        }

        $rpId = (string) config('iam.mfa.webauthn_rp_id', 'localhost');

        return hash_equals(hash('sha256', $rpId, true), substr($authenticatorData, 0, 32));
    }

    public function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $encoded): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $padded = str_pad(strtr($encoded, '-_', '+/'), (int) (ceil(strlen($encoded) / 4) * 4), '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    private function cacheKey(int $tenantId, int $userId, string $ceremony): string
    {
        return "iam:webauthn:{$tenantId}:{$userId}:{$ceremony}";
    }

    private function invalid(string $message): ValidationException
    {
        return new ValidationException([], $message, ['reason' => 'WEBAUTHN_INVALID']);
    }
}
