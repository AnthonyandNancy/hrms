<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\UserRole;
use App\Modules\Platform\Contracts\MenuOverrideReader;
use App\Shared\Authorization\BindingValidity;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;

/**
 * The single page/action/field/scope resolver used by /me/permissions and the
 * administrator permission preview. A page is visible only when an effective
 * role grants it through role_pages AND its required capability is present.
 * A page granted without the capability, or capable but not granted, carries a
 * stable denial reason instead of silently disappearing.
 */
final class PermissionProjection
{
    public function __construct(
        private readonly Clock $clock,
        private readonly MenuOverrideReader $menuOverrides,
        private readonly PermissionResourceCatalog $catalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(int $userId, int $authVersion): array
    {
        [$rows, $wildcard] = $this->effectiveBindings($userId, $authVersion);

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

        $now = $this->clock->now();
        $roles = UserRole::query()
            ->where('user_id', $userId)
            ->where('auth_version', $authVersion)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now)
            ->orderBy('role_code')
            ->pluck('role_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        $rolePages = RolePage::query()
            ->whereIn('role_code', $roles)
            ->pluck('page_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
        $rolePageSet = array_fill_keys($rolePages, true);

        $overrides = [];
        foreach ($this->menuOverrides->overrides() as $override) {
            $overrides[(string) $override['page_code']] = $override;
        }

        $visiblePages = [];
        $denied = [];

        foreach ($this->catalog->pages() as $page) {
            $pageCode = (string) $page['page_code'];
            $required = (string) $page['required_capability'];
            $override = $overrides[$pageCode] ?? null;

            if ($required !== 'public' && ! isset($rolePageSet[$pageCode])) {
                $denied[] = ['page_code' => $pageCode, 'path' => (string) $page['path'], 'reason' => 'PAGE_NOT_GRANTED'];

                continue;
            }

            if ($required !== 'public' && ! isset($actions[$required])) {
                $denied[] = ['page_code' => $pageCode, 'path' => (string) $page['path'], 'reason' => 'CAPABILITY_NOT_GRANTED'];

                continue;
            }

            if ($override !== null && $override['visible'] === false) {
                $denied[] = ['page_code' => $pageCode, 'path' => (string) $page['path'], 'reason' => 'MENU_HIDDEN'];

                continue;
            }

            $visiblePages[] = [
                ...$page,
                'label' => $override['label'] ?? $page['menu_label'],
                'sort_order' => $override['sort_order'] ?? $page['order'],
                'granted_by_role' => $roles,
                'field_profile_max' => $required === 'public'
                    ? FieldProfile::P0_PUBLIC->value
                    : ($fieldProfilesByCapability[$required] ?? FieldProfile::P0_PUBLIC->value),
            ];
        }

        $profiles = array_fill_keys(array_values($fieldProfilesByCapability), true);
        $fieldCapabilities = array_keys($profiles);
        sort($fieldCapabilities);

        return [
            'roles' => $roles,
            'pages' => $visiblePages,
            'actions' => $rows,
            'action_codes' => $actionCodes,
            'field_capabilities' => $fieldCapabilities,
            'scope_labels' => $this->scopeLabels($rows),
            'denied' => $denied,
            'wildcard' => $wildcard,
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    public function effectiveBindings(int $userId, int $authVersion): array
    {
        $businessDate = $this->clock->now()->toDateString();

        $bindings = BindingValidity::whereActiveOn(
            AuthorityBinding::query()
                ->where('principal_id', $userId)
                ->where('auth_version', $authVersion),
            $businessDate,
        )
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
            $concreteRows[$binding['capability']] ??= $binding;
        }

        if ($wildcardBinding === null) {
            return [$bindings, false];
        }

        $rows = [];
        foreach ((new CapabilityCatalog)->all() as $definition) {
            $rows[] = $concreteRows[$definition->code] ?? [
                ...$wildcardBinding,
                'capability' => $definition->code,
                'wildcard' => true,
            ];
        }

        return [$rows, true];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{anchor_type: string, label: string}>
     */
    private function scopeLabels(array $rows): array
    {
        $labels = [
            'SELF' => '仅本人',
            'MANAGED' => '下属',
            'ORG_UNIT' => '本部门',
            'ORG_SUBTREE' => '本部门及子部门',
            'CASE' => '指定案例',
            'PAYROLL_RUN' => '指定工资批次',
            'COMPANY' => '本公司',
        ];
        $scopes = [];

        foreach ($rows as $row) {
            $anchor = (string) $row['anchor_type'];
            $scopes[$anchor] = ['anchor_type' => $anchor, 'label' => $labels[$anchor] ?? $anchor];
        }

        ksort($scopes);

        return array_values($scopes);
    }

    private function higherProfile(?string $current, string $candidate): string
    {
        if ($current === null) {
            return $candidate;
        }

        $currentProfile = FieldProfile::from($current);
        $candidateProfile = FieldProfile::from($candidate);

        return $candidateProfile->rank() > $currentProfile->rank() ? $candidateProfile->value : $currentProfile->value;
    }
}
