<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\MfaFactor;
use App\Modules\IAM\Models\RecoveryCode;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\Crypt;

final class MfaService
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly SessionManager $sessions,
        private readonly SecurityEventService $events,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{otpauth_uri: string, secret: string, factor_id: int}
     */
    public function beginTotpEnrollment(User $user, string $label = 'TOTP'): array
    {
        $secret = $this->totp->generateSecret();

        $factor = MfaFactor::query()->create([
            'user_id' => (int) $user->id,
            'type' => MfaFactor::TYPE_TOTP,
            'secret_ciphertext' => Crypt::encryptString($secret),
            'label' => mb_substr($label, 0, 80),
        ]);

        $issuer = (string) config('iam.mfa.totp_issuer', 'HRMS');
        $uri = $this->totp->provisioningUri($issuer, $user->username, $secret);

        return ['otpauth_uri' => $uri, 'secret' => $secret, 'factor_id' => (int) $factor->id];
    }

    /**
     * @return list<string> one-time recovery codes, returned exactly once
     */
    public function confirmTotpEnrollment(User $user, int $factorId, string $code): array
    {
        $factor = MfaFactor::query()
            ->where('user_id', $user->id)
            ->whereKey($factorId)
            ->where('type', MfaFactor::TYPE_TOTP)
            ->whereNull('disabled_at')
            ->first();

        if ($factor === null) {
            throw new ValidationException([], 'MFA factor not found.', ['reason' => 'MFA_FACTOR_NOT_FOUND']);
        }

        $secret = Crypt::decryptString((string) $factor->secret_ciphertext);

        if (! $this->totp->verify($secret, $code, $this->clock->now()->getTimestamp())) {
            $this->events->record((int) $user->id, 'mfa.enroll.failure', 'FAILURE', ['reason' => 'BAD_TOTP', 'factor_type' => 'TOTP']);

            throw new ValidationException([], 'The verification code is invalid.', ['reason' => 'MFA_CODE_INVALID']);
        }

        $now = $this->clock->now();

        $factor->forceFill(['confirmed_at' => $now])->save();
        // Enrolling a second factor changes the session/authentication posture,
        // not the set of authorization grants. Revoke sessions and replay the
        // unchanged snapshot so the new generation is usable.
        $user->forceFill(['mfa_enrolled_at' => $now])->save();
        app(\App\Shared\Authorization\AuthorizationSnapshot::class)->replay($user, 'mfa.enrolled');

        RecoveryCode::query()->where('user_id', $user->id)->delete();
        $codes = [];

        for ($i = 0; $i < (int) config('iam.mfa.recovery_code_count', 10); $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = $raw;
            RecoveryCode::query()->create([
                'user_id' => (int) $user->id,
                'code_hash' => hash('sha256', $raw),
                'created_at' => $now,
            ]);
        }

        $this->events->record((int) $user->id, 'mfa.enrolled', 'SUCCESS', ['factor_type' => 'TOTP']);

        return $codes;
    }

    public function verifyStepUp(User $user, string $code, string $factorType, Session $session): void
    {
        $type = strtoupper($factorType);

        // SMS and email are explicitly not accepted second factors.
        if (in_array($type, ['SMS', 'EMAIL'], true)) {
            throw new ValidationException([], 'SMS and email are not accepted second factors.', ['reason' => 'MFA_FACTOR_UNSUPPORTED']);
        }

        if (! in_array($type, ['TOTP', 'WEBAUTHN', 'RECOVERY_CODE'], true)) {
            throw new ValidationException([], 'Unsupported second factor type.', ['reason' => 'MFA_FACTOR_UNSUPPORTED']);
        }

        $verified = match ($type) {
            'TOTP' => $this->verifyTotp($user, $code),
            'RECOVERY_CODE' => $this->consumeRecoveryCode($user, $code),
            'WEBAUTHN' => false,
        };

        if (! $verified) {
            $this->events->record((int) $user->id, 'mfa.step_up.failure', 'FAILURE', [
                'reason' => 'MFA_CODE_INVALID',
                'factor_type' => $type,
            ]);

            throw new ValidationException([], 'The verification code is invalid.', ['reason' => 'MFA_CODE_INVALID']);
        }

        $this->sessions->markMfaVerified($session);
        $this->events->record((int) $user->id, 'mfa.step_up', 'SUCCESS', [
            'factor_type' => $type,
            'session_id' => (string) $session->id,
        ]);
    }

    public function verifyWebAuthnStepUp(User $user, Session $session, string $clientDataJson): void
    {
        app(WebAuthnService::class)->verifyAssertionClientData((int) $user->id, $clientDataJson);
        $this->sessions->markMfaVerified($session);
        $this->events->record((int) $user->id, 'mfa.step_up', 'SUCCESS', [
            'factor_type' => 'WEBAUTHN',
            'session_id' => (string) $session->id,
        ]);
    }

    public function requiresMfa(User $user): bool
    {
        return app(MfaPolicy::class)->challengeRequired($user);
    }

    private function verifyTotp(User $user, string $code): bool
    {
        $factor = MfaFactor::query()
            ->where('user_id', $user->id)
            ->where('type', MfaFactor::TYPE_TOTP)
            ->whereNotNull('confirmed_at')
            ->whereNull('disabled_at')
            ->latest('id')
            ->first();

        if ($factor === null) {
            return false;
        }

        $secret = Crypt::decryptString((string) $factor->secret_ciphertext);

        return $this->totp->verify($secret, $code, $this->clock->now()->getTimestamp());
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = strtoupper(trim($code));
        $record = RecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('code_hash', hash('sha256', $normalized))
            ->whereNull('used_at')
            ->first();

        if ($record === null) {
            return false;
        }

        $record->forceFill(['used_at' => $this->clock->now()])->save();

        return true;
    }
}
