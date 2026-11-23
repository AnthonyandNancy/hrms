<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Http\Middleware\WorkflowPrincipalContext;
use App\Modules\Workflow\Services\DelegationService;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DelegationController
{
    public function __construct(private readonly DelegationService $delegations) {}

    public function store(Request $request): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);

        $validated = $request->validate([
            'proxy_user_id' => ['required', 'integer', 'min:1'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['required', 'date_format:Y-m-d'],
            'max_amount_minor' => ['nullable', 'integer', 'min:0'],
            'scope' => ['sometimes', 'array'],
        ]);

        $delegation = $this->delegations->create(
            principal: $principal,
            proxyUserId: (int) $validated['proxy_user_id'],
            validFrom: (string) $validated['valid_from'],
            validTo: (string) $validated['valid_to'],
            maxAmountMinor: isset($validated['max_amount_minor']) ? (int) $validated['max_amount_minor'] : null,
            scope: (array) ($validated['scope'] ?? []),
        );

        return ApiResponse::created([
            'id' => $delegation->id,
            'proxy_user_id' => $delegation->proxy_user_id,
            'principal_user_id' => $delegation->principal_user_id,
            'valid_from' => $delegation->valid_from->toDateString(),
            'valid_to' => $delegation->valid_to->toDateString(),
            'max_amount_minor' => $delegation->max_amount_minor,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $principal = WorkflowPrincipalContext::from($request);
        $delegation = $this->delegations->revoke($principal, $id);

        return ApiResponse::ok([
            'id' => $delegation->id,
            'active' => $delegation->active,
            'revoked_at' => $delegation->revoked_at?->toIso8601ZuluString(),
        ]);
    }
}
