<?php

declare(strict_types=1);

namespace App\Modules\Platform\Features;

use App\Shared\ApiResponse;
use App\Shared\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `feature:payroll_enabled`. Disabled flags return 404 so an
 * unauthorised caller cannot tell whether the domain exists. The block is
 * audited before the response so disabled functionality remains observable.
 */
final class EnsureFeatureEnabled
{
    public function __construct(private readonly FeatureFlagService $flags) {}

    public function handle(Request $request, Closure $next, string $flag): Response
    {
        if ($this->flags->enabled($flag)) {
            return $next($request);
        }

        $this->flags->auditBlocked($flag, '/'.$request->path());

        return ApiResponse::error(
            ErrorCode::NOT_FOUND->value,
            ErrorCode::NOT_FOUND->defaultMessage(),
            ErrorCode::NOT_FOUND->status(),
            ['feature' => $flag],
        );
    }
}
