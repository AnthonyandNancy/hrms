<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Middleware;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Auth\AuthenticationFailedException;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\ErrorCode;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Time\Clock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level capability gate. The capability code and anchor type are route
 * middleware parameters: metadata is both executable policy and the evidence
 * inspected by capability:audit.
 *
 * Usage: ->middleware('capability:people.employee.read,LEGAL_ENTITY,id')
 */
final class RequireCapability
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly AuthenticationContext $context,
        private readonly Clock $clock,
    ) {}

    public function handle(Request $request, Closure $next, string $capability, string $anchorType = 'SELF', ?string $anchorParameter = null): Response
    {
        $principal = $this->context->principalOrNull();

        // Testing seam: explicit Principal binding without a cookie session.
        if ($principal === null && app()->environment('testing') && app()->bound(\App\Modules\IAM\Contracts\Principal::class)) {
            $principal = app(\App\Modules\IAM\Contracts\Principal::class);
            $this->context->set($principal);
            app()->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        }

        if ($principal === null) {
            return ApiResponse::error(ErrorCode::UNAUTHENTICATED->value, 'Authentication is required.', 401, ['reason' => 'UNAUTHENTICATED']);
        }

        $anchor = AnchorType::tryFrom(strtoupper($anchorType));

        if ($anchor === null) {
            return ApiResponse::error(ErrorCode::FORBIDDEN->value, 'The route anchor is not valid.', 403, ['reason' => 'INVALID_ANCHOR']);
        }

        $anchorId = $this->anchorIdFor($request, $anchor, $anchorParameter);

        try {
            $allowed = $this->authorizer->allows(
                $principal,
                $capability,
                new AnchorRef($anchor, $anchorId),
                $this->clock->now()->toDateString(),
            );
        } catch (AuthenticationFailedException) {
            return ApiResponse::error(ErrorCode::UNAUTHENTICATED->value, 'Authentication is required.', 401, ['reason' => 'AUTH_VERSION_STALE']);
        } catch (NotFoundOrForbiddenException) {
            return ApiResponse::error(ErrorCode::NOT_FOUND->value, 'The requested resource was not found.', 404, ['reason' => 'OUT_OF_SCOPE']);
        }

        if (! $allowed) {
            return ApiResponse::error(ErrorCode::FORBIDDEN->value, 'You are not allowed to perform this action.', 403, [
                'reason' => 'CAPABILITY_DENIED',
                'capability' => $capability,
                'anchor_type' => $anchor->value,
            ]);
        }

        return $next($request);
    }

    private function anchorIdFor(Request $request, AnchorType $anchor, ?string $parameter): ?int
    {
        if ($parameter !== null && $parameter !== '') {
            $value = $request->route($parameter) ?? $request->input($parameter) ?? $request->header('X-Legal-Entity-Id');

            return is_numeric($value) ? (int) $value : null;
        }

        $candidates = match ($anchor) {
            AnchorType::ORG_UNIT, AnchorType::ORG_SUBTREE => [$request->route('orgUnit'), $request->route('org_unit'), $request->route('id')],
            AnchorType::CASE => [$request->route('case'), $request->route('caseId'), $request->route('id')],
            AnchorType::PAYROLL_RUN => [$request->route('payrollRun'), $request->route('run'), $request->route('id')],
            AnchorType::LEGAL_ENTITY => [$request->route('legalEntity'), $request->route('legal_entity'), $request->route('id')],
            AnchorType::SELF, AnchorType::MANAGED => [null],
        };

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return null;
    }
}
