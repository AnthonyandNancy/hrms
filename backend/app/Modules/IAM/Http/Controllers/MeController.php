<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\PermissionResourceCatalog;
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
        [$rows, $wildcard] = $this->effectiveBindings($principal->tenantId(), $principal->userId(), $principal->authVersion());

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
     * Page visibility is exact: a page is returned only when its own
     * required_capability is granted (or it is public). Menu groups are merely
     * containers and never imply a child page.
     */
    public function permissions(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        [$rows, $wildcard] = $this->effectiveBindings($principal->tenantId(), $principal->userId(), $principal->authVersion());

        $actions = [];
        $fieldProfilesByCapability = [];

        foreach ($rows as $row) {
            $capability = (string) $row['capability'];
            $actions[$capability] = true;
            $fieldProfilesByCapability[$capability] = $this->higherProfile(
                $fieldProfilesByCapability[$capability] ?? null,
                (string) $row['field_profile'],
            );
        }

        ksort($actions);
        $actionCodes = array_keys($actions);

        $catalog = new PermissionResourceCatalog;
        $visiblePages = [];

        foreach ($catalog->pages() as $page) {
            $required = (string) $page['required_capability'];

            if ($required !== 'public' && ! isset($actions[$required])) {
                continue;
            }

            $visiblePages[] = [
                ...$page,
                'field_profile_max' => $required === 'public'
                    ? FieldProfile::P0_PUBLIC->value
                    : ($fieldProfilesByCapability[$required] ?? FieldProfile::P0_PUBLIC->value),
            ];
        }

        $anchorLabels = [
            'SELF' => '仅本人',
            'MANAGED' => '下属',
            'ORG_UNIT' => '本部门',
            'ORG_SUBTREE' => '本部门及子部门',
            'CASE' => '指定案例',
            'PAYROLL_RUN' => '指定工资批次',
            'LEGAL_ENTITY' => '本公司/法人',
        ];
        $scopeLabels = [];

        foreach ($rows as $row) {
            $anchorType = (string) $row['anchor_type'];
            $scopeLabels[$anchorType] = [
                'anchor_type' => $anchorType,
                'label' => $anchorLabels[$anchorType] ?? $anchorType,
            ];
        }

        ksort($scopeLabels);

        $profiles = [];

        foreach ($fieldProfilesByCapability as $profile) {
            $profiles[$profile] = true;
        }

        $fieldCapabilities = array_keys($profiles);
        sort($fieldCapabilities);

        return ApiResponse::ok([
            'menus' => $catalog->menusForCapabilities($actionCodes),
            'actions' => $actionCodes,
            'field_capabilities' => $fieldCapabilities,
            'scope_labels' => array_values($scopeLabels),
            'auth_version' => $principal->authVersion(),
            'pages' => $visiblePages,
            'wildcard' => $wildcard,
        ]);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function effectiveBindings(int $tenantId, int $userId, int $authVersion): array
    {
        $businessDate = $this->clock->now()->toDateString();

        $query = AuthorityBinding::query()
            ->where('tenant_id', $tenantId)
            ->where('principal_id', $userId)
            ->where('auth_version', $authVersion);

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

        if ($wildcardBinding === null) {
            return [$bindings, false];
        }

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

        return [$rows, true];
    }

    private function higherProfile(?string $current, string $candidate): string
    {
        if ($current === null) {
            return $candidate;
        }

        $currentProfile = FieldProfile::tryFrom($current);
        $candidateProfile = FieldProfile::tryFrom($candidate);

        if ($currentProfile === null || $candidateProfile === null) {
            return $current;
        }

        return $candidateProfile->rank() > $currentProfile->rank() ? $candidate : $current;
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
