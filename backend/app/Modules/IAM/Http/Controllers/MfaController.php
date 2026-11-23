<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\MfaService;
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

        return ApiResponse::ok($this->webauthn->beginRegistration((int) $user->tenant_id, (int) $user->id));
    }

    public function webauthnAssertOptions(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        return ApiResponse::ok($this->webauthn->beginAssertion((int) $user->tenant_id, (int) $user->id));
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
