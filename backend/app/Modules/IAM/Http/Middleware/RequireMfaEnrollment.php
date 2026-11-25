<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Middleware;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\MfaService;
use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces MFA enrollment for privileged users and payroll prepare/approve/
 * publish capability holders. Enrollment is required before such a capability
 * can be exercised; step-up freshness is a separate 15-minute control.
 */
final class RequireMfaEnrollment
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly MfaService $mfa,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('iam.mfa.enabled', false)) {
            return $next($request);
        }

        $principal = $this->context->principalOrNull();

        if ($principal === null) {
            return ApiResponse::error(ErrorCode::UNAUTHENTICATED->value, 'Authentication is required.', 401, ['reason' => 'UNAUTHENTICATED']);
        }

        $user = User::query()->whereKey($principal->userId())->first();

        if ($user === null ? $principal->isPrivileged() : $this->mfa->requiresMfa($user)) {
            $enrolled = $user?->hasConfirmedMfa() ?? false;

            if (! $enrolled) {
                return ApiResponse::error(
                    ErrorCode::FORBIDDEN->value,
                    'Multi-factor authentication enrollment is required.',
                    403,
                    ['reason' => 'MFA_REQUIRED'],
                );
            }
        }

        return $next($request);
    }
}
