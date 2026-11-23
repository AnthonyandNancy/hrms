<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\ApiResponse;
use App\Shared\Time\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly Clock $clock,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $user = User::query()->whereKey($principal->userId())->firstOrFail();

        return ApiResponse::ok([
            'id' => (int) $user->id,
            'tenant_id' => (int) $user->tenant_id,
            'username' => $user->username,
            'email' => $user->email,
            'employment_id' => $principal->employmentId(),
            'is_privileged' => $principal->isPrivileged(),
            'has_mfa' => $user->hasConfirmedMfa(),
            'mfa_verified' => $principal->hasMfa(),
            'auth_version' => $principal->authVersion(),
            'roles' => UserRole::query()
                ->where('tenant_id', $principal->tenantId())
                ->where('user_id', $principal->userId())
                ->where('valid_from', '<=', $this->clock->now())
                ->where('valid_to', '>', $this->clock->now())
                ->orderBy('role_code')
                ->pluck('role_code')
                ->all(),
        ]);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $businessDate = $this->clock->now()->toDateString();

        $bindings = AuthorityBinding::query()
            ->where('tenant_id', $principal->tenantId())
            ->where('principal_id', $principal->userId())
            ->where('auth_version', $principal->authVersion())
            ->where('valid_from', '<=', $businessDate.' 23:59:59')
            ->where('valid_to', '>', $businessDate.' 00:00:00')
            ->orderBy('capability_code')
            ->orderBy('anchor_type')
            ->orderBy('anchor_id')
            ->get()
            ->map(fn (AuthorityBinding $binding): array => [
                'capability' => $binding->capability_code,
                'anchor_type' => $binding->anchor_type,
                'anchor_id' => $binding->anchor_id,
                'field_profile' => $binding->field_profile,
                'purpose_code' => $binding->purpose_code,
                'valid_from' => $binding->valid_from->toIso8601ZuluString(),
                'valid_to' => $binding->valid_to->toIso8601ZuluString(),
            ])
            ->all();

        return ApiResponse::ok([
            'capabilities' => $bindings,
            'mfa_required' => $this->requiresMfa($principal->userId(), $principal->tenantId()),
            'mfa_verified' => $principal->hasMfa(),
        ]);
    }

    private function requiresMfa(int $userId, int $tenantId): bool
    {
        $privileged = (bool) User::query()->whereKey($userId)->value('is_privileged');

        if ($privileged) {
            return true;
        }

        $codes = AuthorityBinding::query()
            ->where('tenant_id', $tenantId)
            ->where('principal_id', $userId)
            ->whereIn('capability_code', ['payroll.run.prepare', 'payroll.run.approve', 'payroll.run.publish', 'payroll.export'])
            ->where('valid_from', '<=', $this->clock->now())
            ->where('valid_to', '>', $this->clock->now())
            ->exists();

        return $codes;
    }
}
