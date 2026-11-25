<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Shared\ApiResponse;
use App\Shared\Authorization\BindingValidity;
use App\Shared\Authorization\CapabilityWildcard;
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
            'tenant_name' => $this->tenantName($principal->tenantId()),
            'legal_entity_name' => $this->tenantName($principal->tenantId()),
            'roles' => BindingValidity::whereActiveOn(
                UserRole::query()
                    ->where('tenant_id', $principal->tenantId())
                    ->where('user_id', $principal->userId()),
                $this->clock->now()->toDateString(),
            )
                ->orderBy('role_code')
                ->pluck('role_code')
                ->all(),
        ]);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $businessDate = $this->clock->now()->toDateString();

        $query = AuthorityBinding::query()
            ->where('tenant_id', $principal->tenantId())
            ->where('principal_id', $principal->userId())
            ->where('auth_version', $principal->authVersion());

        $bindings = BindingValidity::whereActiveOn($query, $businessDate)
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

        $wildcardBinding = null;
        $concreteRows = [];

        foreach ($bindings as $binding) {
            if (CapabilityWildcard::is($binding['capability'])) {
                $wildcardBinding ??= $binding;

                continue;
            }

            // A concrete binding for a catalogued code wins over the wildcard
            // expansion because it carries a narrower, auditable scope. Keep
            // only the first deterministic row so no code is returned twice.
            $concreteRows[$binding['capability']] ??= $binding;
        }

        $wildcard = $wildcardBinding !== null;
        $rows = $bindings;

        if ($wildcardBinding !== null) {
            $rows = [];

            foreach (app(CapabilityCatalog::class)->all() as $definition) {
                $rows[] = $concreteRows[$definition->code] ?? [
                    'capability' => $definition->code,
                    'anchor_type' => $wildcardBinding['anchor_type'],
                    'anchor_id' => $wildcardBinding['anchor_id'],
                    'field_profile' => $wildcardBinding['field_profile'],
                    'purpose_code' => $wildcardBinding['purpose_code'],
                    'valid_from' => $wildcardBinding['valid_from'],
                    'valid_to' => $wildcardBinding['valid_to'],
                    'wildcard' => true,
                ];
            }
        }

        return ApiResponse::ok([
            'capabilities' => $rows,
            'wildcard' => $wildcard,
            'mfa_required' => $this->requiresMfa($principal->userId(), $principal->tenantId()),
            'mfa_verified' => $principal->hasMfa(),
        ]);
    }

    private function tenantName(int $tenantId): string
    {
        $entities = app(OrganizationFacts::class)->legalEntitiesForTenant($tenantId);

        return $entities === [] ? 'HRMS' : $entities[0]->name;
    }

    private function requiresMfa(int $userId, int $tenantId): bool
    {
        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->first();

        return $user instanceof User
            && app(\App\Modules\IAM\Services\MfaPolicy::class)->challengeRequired($user);
    }
}
