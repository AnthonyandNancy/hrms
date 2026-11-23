<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\BreakGlassService;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BreakGlassController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly BreakGlassService $breakGlass,
    ) {}

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'approver_user_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'scope' => ['sometimes', 'array', 'max:10'],
            'scope.*' => ['string', 'max:120'],
        ]);

        $principal = $this->context->requirePrincipal();
        $requester = User::query()->whereKey($principal->userId())->firstOrFail();

        /** @var list<string>|null $scope */
        $scope = isset($validated['scope']) && is_array($validated['scope'])
            ? array_values(array_map(static fn (mixed $value): string => (string) $value, $validated['scope']))
            : null;

        $result = $this->breakGlass->activate(
            $request,
            $requester,
            (int) $validated['approver_user_id'],
            (string) $validated['reason'],
            $scope,
        );

        return ApiResponse::created([
            'grant_id' => (int) $result['grant']->id,
            'requester_user_id' => (int) $result['grant']->requester_user_id,
            'approver_user_id' => (int) $result['grant']->approver_user_id,
            'expires_at' => $result['grant']->expires_at->toIso8601ZuluString(),
            'scope' => $result['capabilities'],
            'revoked_at' => null,
        ]);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $actor = User::query()->whereKey($principal->userId())->firstOrFail();
        $grant = $this->breakGlass->revoke($request, $actor, $id);

        return ApiResponse::ok([
            'grant_id' => (int) $grant->id,
            'revoked_at' => $grant->revoked_at?->toIso8601ZuluString(),
        ]);
    }
}
