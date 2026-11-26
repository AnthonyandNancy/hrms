<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\PermissionProjection;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Shared\ApiResponse;
use App\Shared\Authorization\BindingValidity;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly Clock $clock,
        private readonly PermissionProjection $permissions,
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
        [$rows, $wildcard] = $this->permissions->effectiveBindings($principal->tenantId(), $principal->userId(), $principal->authVersion());

        return ApiResponse::ok([
            'capabilities' => $rows,
            'wildcard' => $wildcard,
            'mfa_required' => $this->requiresMfa($principal->userId(), $principal->tenantId()),
            'mfa_verified' => $principal->hasMfa(),
        ]);
    }

    /**
     * Menu, action and field projection for the authenticated principal.
     *
     * Both this endpoint and the administrator preview consume the same
     * PermissionProjection, so visible pages, actions and denial reasons cannot
     * drift between the two surfaces.
     */
    public function permissions(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $projection = $this->permissions->forUser(
            $principal->tenantId(),
            $principal->userId(),
            $principal->authVersion(),
        );

        return ApiResponse::ok([
            'menus' => (new \App\Modules\IAM\Authorization\PermissionResourceCatalog)
                ->menusForCapabilities($projection['action_codes']),
            'actions' => $projection['action_codes'],
            'field_capabilities' => $projection['field_capabilities'],
            'scope_labels' => $projection['scope_labels'],
            'auth_version' => $principal->authVersion(),
            'pages' => $projection['pages'],
            'denied' => $projection['denied'],
            'wildcard' => $projection['wildcard'],
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
