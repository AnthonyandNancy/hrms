<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Auth\AuthenticationFailedException;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Shared\Time\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Issues and validates server-side sessions. The raw bearer and CSRF tokens
 * are returned exactly once to the caller; only SHA-256 hashes are stored.
 */
final class SessionManager
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array{session: Session, token: string, csrf_token: string}
     */
    public function issue(User $user, Request $request, bool $privileged = false): array
    {
        $now = $this->clock->now();
        $token = $this->randomToken();
        $csrf = $this->randomToken();

        $idleMinutes = $privileged
            ? (int) config('iam.session.privileged_idle_minutes', 15)
            : (int) config('iam.session.standard_idle_minutes', 30);
        $absoluteHours = $privileged
            ? (int) config('iam.session.privileged_absolute_hours', 8)
            : (int) config('iam.session.standard_absolute_hours', 12);

        $session = Session::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => (int) $user->id,
            'tenant_id' => (int) $user->tenant_id,
            'token_hash' => hash('sha256', $token),
            'csrf_hash' => hash('sha256', $csrf),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'mfa_verified' => false,
            'auth_version' => (int) $user->auth_version,
            'idle_expires_at' => $now->addMinutes($idleMinutes),
            'absolute_expires_at' => $now->addHours($absoluteHours),
            'last_seen_at' => $now,
            'created_at' => $now,
        ]);

        return ['session' => $session, 'token' => $token, 'csrf_token' => $csrf];
    }

    public function findValid(string $token): Session
    {
        $session = Session::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if (! $session instanceof Session) {
            throw new AuthenticationFailedException('SESSION_INVALID');
        }

        $now = $this->clock->now();

        if ($session->absolute_expires_at->lessThanOrEqualTo($now) || $session->idle_expires_at->lessThanOrEqualTo($now)) {
            $this->revoke($session);

            throw new AuthenticationFailedException('SESSION_EXPIRED');
        }

        return $session;
    }

    public function assertAuthVersion(User $user, Session $session): void
    {
        if ((int) $user->auth_version !== (int) $session->auth_version) {
            $this->revoke($session);

            throw new AuthenticationFailedException('AUTH_VERSION_STALE');
        }

        if (! $user->isActive()) {
            $this->revoke($session);

            throw new AuthenticationFailedException('USER_NOT_ACTIVE');
        }
    }

    /**
     * Sliding idle window capped by the absolute expiry.
     */
    public function touch(Session $session): void
    {
        $now = $this->clock->now();
        $privileged = (bool) User::query()->whereKey($session->user_id)->value('is_privileged');
        $idleMinutes = $privileged
            ? (int) config('iam.session.privileged_idle_minutes', 15)
            : (int) config('iam.session.standard_idle_minutes', 30);
        $idle = $now->addMinutes($idleMinutes);

        $session->forceFill([
            'last_seen_at' => $now,
            'idle_expires_at' => $idle->lessThan($session->absolute_expires_at) ? $idle : $session->absolute_expires_at,
        ])->save();
    }

    public function revoke(Session $session): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => $this->clock->now()])->save();
        }
    }

    public function revokeAllForUser(int $tenantId, int $userId, ?string $exceptSessionId = null): int
    {
        return Session::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->when($exceptSessionId !== null, fn ($query) => $query->whereKeyNot($exceptSessionId))
            ->update(['revoked_at' => $this->clock->now()]);
    }

    public function markMfaVerified(Session $session): void
    {
        $now = $this->clock->now();
        $session->forceFill([
            'mfa_verified' => true,
            'mfa_verified_until' => $now->addMinutes((int) config('iam.mfa.step_up_minutes', 15)),
        ])->save();
    }

    public function hasFreshMfa(Session $session): bool
    {
        return (bool) $session->mfa_verified
            && $session->mfa_verified_until !== null
            && $session->mfa_verified_until->greaterThan($this->clock->now());
    }

    public function csrfMatches(Session $session, ?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($session->csrf_hash, hash('sha256', $token));
    }

    /**
     * Rotate on login and privilege change: old session is revoked and the
     * caller receives a new token/CSRF pair.
     *
     * @return array{session: Session, token: string, csrf_token: string}
     */
    public function rotate(Session $old, User $user, Request $request): array
    {
        $this->revoke($old);

        return $this->issue($user, $request, (bool) $user->is_privileged);
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
