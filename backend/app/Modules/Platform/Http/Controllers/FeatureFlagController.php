<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Features\FeatureFlagService;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operational flag surface. Reading is available to any authenticated operator
 * with the platform capability; changing a risky domain flag is deliberately
 * guarded by `capability:platform.feature.manage` route metadata. The caller
 * must also pass its own authorization middleware once IAM is merged.
 */
final class FeatureFlagController
{
    public function index(FeatureFlagService $flags): JsonResponse
    {
        $tenantId = RequestContext::tenantId();
        $items = [];

        foreach ($flags->flags() as $flag) {
            $items[] = [
                'flag' => $flag,
                'enabled' => $flags->enabled($flag, $tenantId),
                'risky' => in_array($flag, ['payroll_enabled', 'time_enabled', 'export_enabled'], true),
            ];
        }

        return ApiResponse::ok(['items' => $items]);
    }

    public function update(string $flag, Request $request, FeatureFlagService $flags): JsonResponse
    {
        if (! in_array($flag, $flags->flags(), true)) {
            throw new NotFoundOrForbiddenException;
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $flags->set(
            $flag,
            RequestContext::tenantId(),
            (bool) $validated['enabled'],
            RequestContext::userId(),
            (string) $validated['reason'],
        );

        return ApiResponse::ok([
            'flag' => $flag,
            'enabled' => $flags->enabled($flag, RequestContext::tenantId()),
        ]);
    }
}
