<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Middleware;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * High-risk mutations require a fresh step-up (15 minutes) in addition to
 * MFA enrollment. The freshness is stored on the server-side session so a
 * client cannot assert it.
 */
final class RequireMfaStepUp
{
    public function __construct(private readonly AuthenticationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $principal = $this->context->principalOrNull();

        if ($principal === null) {
            return ApiResponse::error(ErrorCode::UNAUTHENTICATED->value, 'Authentication is required.', 401, ['reason' => 'UNAUTHENTICATED']);
        }

        if (! $principal->hasMfa()) {
            return ApiResponse::error(
                ErrorCode::FORBIDDEN->value,
                'A fresh multi-factor step-up is required for this action.',
                403,
                ['reason' => 'MFA_STEP_UP_REQUIRED'],
            );
        }

        return $next($request);
    }
}
