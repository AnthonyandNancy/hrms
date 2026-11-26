<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\MfaService;
use App\Modules\IAM\Services\SessionManager;
use App\Modules\IAM\Services\WebAuthnService;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MfaController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly MfaService $mfa,
        private readonly WebAuthnService $webauthn,
        private readonly SessionManager $sessions,
    ) {}

    public function enrollTotp(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        return ApiResponse::created($this->mfa->beginTotpEnrollment($user, 'Authenticator'), 'Scan the QR code and confirm with a 6-digit code.');
    }

    public function confirmTotp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'factor_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        return ApiResponse::ok([
            'confirmed' => true,
            'recovery_codes' => $this->mfa->confirmTotpEnrollment(
                $this->currentUser(),
                (int) $validated['factor_id'],
                (string) $validated['code'],
            ),
        ], 200, 'TOTP enrollment confirmed. Store the recovery codes now.');
    }

    public function stepUp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'factor_type' => ['required', 'string', 'in:TOTP,WEBAUTHN,RECOVERY_CODE'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $this->currentUser();
        $session = $this->currentSession();

        if ((string) $validated['factor_type'] === 'WEBAUTHN') {
            $this->mfa->verifyWebAuthnStepUp($user, $session, (string) $validated['code']);
        } else {
            $this->mfa->verifyStepUp($user, (string) $validated['code'], (string) $validated['factor_type'], $session);
        }

        return ApiResponse::ok([
            'mfa_verified' => true,
            'valid_until' => $session->fresh()?->mfa_verified_until?->toIso8601ZuluString(),
        ]);
    }

    public function webauthnRegisterOptions(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        return ApiResponse::ok($this->webauthn->beginRegistration((int) $user->id));
    }

    public function webauthnAssertOptions(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        return ApiResponse::ok($this->webauthn->beginAssertion((int) $user->id));
    }

    /**
     * Complete a password-verified but unauthenticated MFA challenge. This is
     * the only step-up path that does not already have a session principal.
     */
    public function completeChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'min:16', 'max:512'],
            'factor_type' => ['required', 'string', 'in:TOTP,WEBAUTHN,RECOVERY_CODE'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        // Verify the factor against the still-open challenge row first. A bad
        // code must not consume the challenge or issue a session token.
        ['session' => $preSession, 'user' => $user] = $this->sessions->findChallenge((string) $validated['challenge_id']);

        if ((string) $validated['factor_type'] === 'WEBAUTHN') {
            $this->mfa->verifyWebAuthnStepUp($user, $preSession, (string) $validated['code']);
        } else {
            $this->mfa->verifyStepUp($user, (string) $validated['code'], (string) $validated['factor_type'], $preSession);
        }

        $issued = $this->sessions->completeChallenge((string) $validated['challenge_id'], $request);
        $session = $issued['session'];
        $this->sessions->markMfaVerified($session);

        return ApiResponse::ok([
            'user' => [
                'id' => (int) $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'is_privileged' => (bool) $user->is_privileged,
                'mfa_enrolled' => $user->hasConfirmedMfa(),
                'auth_version' => (int) $user->auth_version,
            ],
            'csrf_token' => $issued['csrf_token'],
            'session' => [
                'id' => (string) $session->id,
                'mfa_verified' => true,
                'idle_expires_at' => $session->idle_expires_at->toIso8601ZuluString(),
                'absolute_expires_at' => $session->absolute_expires_at->toIso8601ZuluString(),
            ],
        ], 200, 'Login complete.')->withCookie(cookie(
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

    private function currentUser(): User
    {
        $principal = $this->context->requirePrincipal();

        return User::query()->whereKey($principal->userId())->firstOrFail();
    }

    private function currentSession(): Session
    {
        $principal = $this->context->requirePrincipal();
        $session = Session::query()->whereKey($principal->sessionId())->first();

        if ($session === null) {
            throw new ValidationException([], 'The session is no longer valid.', ['reason' => 'SESSION_INVALID']);
        }

        return $session;
    }
}
