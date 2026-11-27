<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Models\LoginAttempt;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\PasswordHasher;
use App\Modules\IAM\Services\SecurityEventService;
use App\Modules\IAM\Services\SessionManager;
use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuthController
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PasswordHasher $passwords,
        private readonly SecurityEventService $events,
        private readonly Clock $clock,
        private readonly AuthenticationContext $context,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $username = mb_strtolower(trim((string) $validated['username']));
        $ip = (string) $request->ip();

        $failures = LoginAttempt::query()
            ->where('username', $username)
            ->where('succeeded', false)
            ->where('attempted_at', '>=', $this->clock->now()->subMinutes((int) config('iam.login.failure_window_minutes', 15)))
            ->count();

        if ($failures >= (int) config('iam.login.max_failures', 10)) {
            $this->events->record(null, 'login.failure', 'FAILURE', ['reason' => 'LOCKED'], $request);

            return ApiResponse::error(ErrorCode::UNAUTHENTICATED->value, 'Authentication failed.', 401, ['reason' => 'ACCOUNT_LOCKED']);
        }

        // Fixed-window throttle: 5 attempts/min per username+IP.
        $recent = LoginAttempt::query()
            ->where('username', $username)
            ->where('ip', $ip)
            ->where('attempted_at', '>=', $this->clock->now()->subMinute())
            ->count();

        if ($recent >= (int) config('iam.login.rate_limit_per_minute', 5)) {
            $this->events->record(null, 'login.failure', 'FAILURE', ['reason' => 'RATE_LIMITED'], $request);

            return ApiResponse::error(ErrorCode::RATE_LIMITED->value, ErrorCode::RATE_LIMITED->defaultMessage(), 429, ['reason' => 'LOGIN_RATE_LIMITED']);
        }

        $user = User::query()->where('username', $username)->first();

        $valid = $user instanceof User
            && $this->passwords->verify((string) $validated['password'], $user->password_hash);

        LoginAttempt::query()->create([
            'username' => $username,
            'ip' => $ip,
            'succeeded' => $valid && $user->isActive(),
            'attempted_at' => $this->clock->now(),
        ]);

        if (! $valid || ! $user instanceof User || ! $user->isActive()) {
            $this->events->record($user?->id, 'login.failure', 'FAILURE', ['reason' => 'BAD_CREDENTIALS'], $request);

            return ApiResponse::error(ErrorCode::UNAUTHENTICATED->value, 'Authentication failed.', 401, ['reason' => 'BAD_CREDENTIALS']);
        }

        if ($this->passwords->needsRehash((string) $user->password_hash)) {
            $user->forceFill(['password_hash' => $this->passwords->hash((string) $validated['password'])])->save();
        }

        if (app(\App\Modules\IAM\Services\MfaPolicy::class)->challengeRequired($user)) {
            $issued = $this->sessions->issueChallenge($user, $request);

            $user->forceFill(['last_login_at' => $this->clock->now()])->save();

            $this->events->record((int) $user->id, 'login.mfa_challenge', 'SUCCESS', [
                'session_id' => (string) $issued['session']->id,
            ], $request);

            return ApiResponse::accepted([
                'status' => 'MFA_REQUIRED',
                'challenge_id' => $issued['challenge_token'],
                'masked_destination' => 'TOTP authenticator',
                'expires_at' => $issued['session']->mfa_challenge_expires_at?->toIso8601ZuluString(),
            ], 'A second factor is required.');
        }

        $issued = $this->sessions->issue($user, $request, (bool) $user->is_privileged);
        $user->forceFill(['last_login_at' => $this->clock->now()])->save();

        $this->events->record((int) $user->id, 'login.succeeded', 'SUCCESS', [
            'session_id' => (string) $issued['session']->id,
            'method' => 'PASSWORD',
        ], $request);

        return $this->sessionResponse($issued, $user, 'Login successful.');
    }

    public function logout(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $sessionId = $principal->sessionId();
        $session = $sessionId === null ? null : Session::query()->whereKey($sessionId)->first();

        if ($session !== null) {
            $this->sessions->revoke($session);
        }

        $this->events->record($principal->userId(), 'logout', 'SUCCESS', ['session_id' => $sessionId], $request);

        return ApiResponse::ok(['logged_out' => true])->withCookie($this->forgetCookie());
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();

        DB::transaction(function () use ($principal, $request): void {
            $user = User::query()->whereKey($principal->userId())->lockForUpdate()->firstOrFail();
            // Logout is a session lifecycle event, not an authorization change.
            // Bumping auth_version here would invalidate every binding snapshot
            // while leaving its rows on the old generation.
            $this->sessions->revokeAllForUser((int) $user->id);
            $this->events->record((int) $user->id, 'logout.all', 'SUCCESS', [], $request);
        });

        return ApiResponse::ok(['logged_out' => true, 'sessions_revoked' => true])->withCookie($this->forgetCookie());
    }

    /**
     * Live server-side sessions for the caller. The raw token and CSRF hashes
     * are never serialised; `device` is a derived, human-readable label and
     * `current` marks the session that authenticated this request.
     */
    public function sessions(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $currentSessionId = $principal->sessionId();
        $now = $this->clock->now();

        $items = Session::query()
            ->where('user_id', $principal->userId())
            ->whereNull('revoked_at')
            ->where('absolute_expires_at', '>', $now)
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (Session $session): array => [
                'id' => (string) $session->id,
                'device' => $this->deviceLabel($session->user_agent),
                'ip' => $session->ip,
                'current' => (string) $session->id === $currentSessionId,
                'mfa_verified' => (bool) $session->mfa_verified,
                'last_seen_at' => $session->last_seen_at->toIso8601ZuluString(),
                'created_at' => $session->created_at->toIso8601ZuluString(),
                'absolute_expires_at' => $session->absolute_expires_at->toIso8601ZuluString(),
            ])
            ->values()
            ->all();

        return ApiResponse::ok(['items' => $items]);
    }

    /**
     * Revoke exactly one session owned by the caller. An id belonging to
     * another user answers 404 (not 403) so session ids cannot be enumerated.
     * Revocation is idempotent: an already-revoked owned row is still `revoked`.
     */
    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();

        $session = Session::query()
            ->whereKey($id)
            ->where('user_id', $principal->userId())
            ->first();

        if (! $session instanceof Session) {
            throw new NotFoundOrForbiddenException;
        }

        $alreadyRevoked = $session->revoked_at !== null;
        $current = (string) $session->id === $principal->sessionId();

        if (! $alreadyRevoked) {
            $this->sessions->revoke($session);
        }

        $this->events->record((int) $principal->userId(), 'session.revoked', 'SUCCESS', [
            'session_id' => (string) $session->id,
            'reason' => $current ? 'CURRENT_SESSION' : 'OTHER_SESSION',
        ], $request);

        $response = ApiResponse::ok([
            'revoked' => true,
            'already_revoked' => $alreadyRevoked,
            'current_session' => $current,
        ]);

        return $current ? $response->withCookie($this->forgetCookie()) : $response;
    }

    public function changePassword(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:12', 'max:1024', 'different:current_password'],
            // The SPA always sends the confirmation; older API clients may omit
            // it. When present it must match the new password.
            'new_password_confirmation' => ['nullable', 'string', 'same:new_password'],
        ]);

        $user = User::query()->whereKey($principal->userId())->firstOrFail();

        if (! $this->passwords->verify((string) $validated['current_password'], $user->password_hash)) {
            $this->events->record((int) $user->id, 'password.change', 'FAILURE', ['reason' => 'BAD_CURRENT_PASSWORD'], $request);

            throw new ValidationException(['current_password' => ['The current password is incorrect.']], 'The given data was invalid.', ['reason' => 'BAD_CURRENT_PASSWORD']);
        }

        DB::transaction(function () use ($user, $validated, $principal, $request): void {
            $user->forceFill([
                'password_hash' => $this->passwords->hash((string) $validated['new_password']),
            ])->save();

            // Password rotation revokes sessions only. Authorization remains
            // the same snapshot until an actual grant/revocation occurs.
            $this->sessions->revokeAllForUser((int) $user->id);
            $this->events->record((int) $user->id, 'password.change', 'SUCCESS', ['session_id' => $principal->sessionId()], $request);
        });

        return ApiResponse::ok([
            'password_changed' => true,
            'reauth_required' => true,
            'sessions_revoked' => true,
        ])->withCookie($this->forgetCookie());
    }

    /**
     * Derive a readable device label from the stored user agent. A raw UA
     * string is never the only label: unknown agents still receive a stable
     * "未知设备" label rather than an empty cell.
     */
    private function deviceLabel(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);
        $browser = match (true) {
            str_contains($agent, 'edg/') => 'Edge',
            str_contains($agent, 'chrome/') && ! str_contains($agent, 'chromium') => 'Chrome',
            str_contains($agent, 'firefox/') => 'Firefox',
            str_contains($agent, 'safari/') && ! str_contains($agent, 'chrome/') => 'Safari',
            str_contains($agent, 'curl/') => '命令行客户端',
            default => null,
        };

        $platform = match (true) {
            str_contains($agent, 'windows') => 'Windows',
            str_contains($agent, 'iphone') || str_contains($agent, 'ipad') => 'iOS',
            str_contains($agent, 'android') => 'Android',
            str_contains($agent, 'mac os x') || str_contains($agent, 'macintosh') => 'macOS',
            str_contains($agent, 'linux') => 'Linux',
            default => null,
        };

        if ($browser === null && $platform === null) {
            return '未知设备';
        }

        return implode(' · ', array_values(array_filter([$platform, $browser])));
    }

    /**
     * @param  array{session: Session, token: string, csrf_token: string}  $issued
     */
    private function sessionResponse(array $issued, User $user, string $message): JsonResponse
    {
        $session = $issued['session'];

        return ApiResponse::ok([
            'user' => $this->userPayload($user),
            'csrf_token' => $issued['csrf_token'],
            'session' => [
                'id' => (string) $session->id,
                'mfa_verified' => (bool) $session->mfa_verified,
                'idle_expires_at' => $session->idle_expires_at->toIso8601ZuluString(),
                'absolute_expires_at' => $session->absolute_expires_at->toIso8601ZuluString(),
            ],
        ], 200, $message)->withCookie(cookie(
            name: (string) config('iam.session.cookie', 'hrms_session'),
            value: $issued['token'],
            minutes: 0,
            path: (string) config('iam.session.path', '/'),
            domain: config('iam.session.domain'),
            secure: (bool) config('iam.session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'is_privileged' => (bool) $user->is_privileged,
            'mfa_enrolled' => $user->hasConfirmedMfa(),
            'auth_version' => (int) $user->auth_version,
        ];
    }

    private function forgetCookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            name: (string) config('iam.session.cookie', 'hrms_session'),
            value: '',
            minutes: -1,
            path: (string) config('iam.session.path', '/'),
            domain: config('iam.session.domain'),
            secure: (bool) config('iam.session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }
}
