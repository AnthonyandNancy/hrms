<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\BindingValidity;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;

/**
 * Central guard for the last administrator and for the grant ceiling of an
 * actor who is allowed to manage roles.
 *
 * Administrator status is derived only from live data: an ENABLED user whose
 * current snapshot carries `iam.role.manage`, `iam.binding.manage` or the
 * wildcard under a COMPANY anchor. `users.is_privileged` is deliberately not
 * consulted; it is a step-up hint, never an authorization source. A privileged
 * but unbound principal therefore has no administrative authority.
 */
final class AdminProtectionGuard
{
    /** @var list<string> */
    public const ADMIN_CAPABILITIES = [
        'iam.role.manage',
        'iam.binding.manage',
        CapabilityWildcard::WILDCARD,
    ];

    /** The capability that defines an administrator for last-admin purposes. */
    public const ADMIN_CAPABILITY = 'iam.role.manage';

    public const REASON_LAST_ADMIN = 'LAST_ENABLED_ADMIN_PROTECTED';

    public const REASON_ESCALATION = 'PRIVILEGE_ESCALATION_FORBIDDEN';

    public const REASON_SUPER_ADMIN = 'SUPER_ADMIN_GRANT_FORBIDDEN';

    public function __construct(private readonly Clock $clock) {}

    /**
     * Reject disabling a user when that user is the last enabled administrator.
     */
    public function assertNotLastAdminDisable(int $userId): void
    {
        if (! $this->isEnabledRoleManager($userId)) {
            return;
        }

        if ($this->otherEnabledAdminCount($userId) === 0) {
            throw new ValidationException(
                ['status' => ['至少需要保留一名启用状态的管理员。']],
                '至少需要保留一名启用状态的管理员。',
                ['reason' => self::REASON_LAST_ADMIN],
            );
        }
    }

    /**
     * Reject removing the supplied role rows when doing so would strip the last
     * enabled administrator of every live role that carries administrative
     * authority. Removing a non-admin role from the last admin is allowed.
     *
     * @param  list<int>  $roleIds  user_roles.id values being removed
     */
    public function assertNotLastAdminRoleRemoval(int $userId, array $roleIds, ?int $actorUserId = null): void
    {
        if ($roleIds === [] || ! $this->isEnabledRoleManager($userId)) {
            return;
        }

        $user = User::query()->whereKey($userId)->first();

        if (! $user instanceof User) {
            return;
        }

        $liveRoleIds = $this->liveRoleIds($user);
        $removedRoleIds = array_values(array_intersect($liveRoleIds, array_map(static fn (mixed $id): int => (int) $id, $roleIds)));

        if ($removedRoleIds === []) {
            return;
        }

        $adminRoleCodes = $this->adminRoleCodes($liveRoleIds);

        if ($adminRoleCodes === []) {
            return;
        }

        $removedAdminRoleIds = UserRole::query()
            ->whereIn('id', $removedRoleIds)
            ->whereIn('role_code', $adminRoleCodes)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($removedAdminRoleIds === []) {
            return;
        }

        $survivingAdminRoleIds = array_values(array_diff(
            $this->liveRoleIds($user, $adminRoleCodes),
            $removedRoleIds,
        ));

        if ($survivingAdminRoleIds !== []) {
            return;
        }

        if ($this->otherEnabledAdminCount($userId) > 0) {
            return;
        }

        // Removing the final admin role is forbidden even for self-demotion.
        $field = $actorUserId === $userId ? 'role_code' : 'roles';

        throw new ValidationException(
            [$field => ['不能撤销最后一名启用管理员的管理角色。']],
            '不能撤销最后一名启用管理员的管理角色。',
            ['reason' => self::REASON_LAST_ADMIN],
        );
    }

    /**
     * A role manager may only grant what they themselves hold: the same
     * capability, no wider field profile and no broader anchor. The wildcard is
     * reserved for existing super administrators.
     */
    public function assertNoPrivilegeEscalation(
        int $actorUserId,
        string $capabilityCode,
        string $anchorType,
        string $fieldProfileMax,
    ): void {
        $anchor = AnchorType::tryFrom($anchorType);
        $profile = FieldProfile::tryFrom($fieldProfileMax);

        if ($anchor === null || $profile === null) {
            throw new ValidationException(
                ['capability' => ['Unknown anchor type or field profile.']],
                'The given data was invalid.',
                ['reason' => self::REASON_ESCALATION],
            );
        }

        if (CapabilityWildcard::is($capabilityCode) && ! $this->isSuperAdmin($actorUserId)) {
            $this->deny(self::REASON_ESCALATION, '只有现有超级管理员可以授予通配符权限。');
        }

        $grants = $this->effectiveGrants($actorUserId);

        if ($grants === []) {
            $this->deny(self::REASON_ESCALATION, '当前操作者没有任何有效授权，不能授予权限。');
        }

        $matching = array_values(array_filter(
            $grants,
            static fn (array $grant): bool => CapabilityWildcard::is($grant['capability_code'])
                || $grant['capability_code'] === $capabilityCode,
        ));

        if ($matching === []) {
            $this->deny(self::REASON_ESCALATION, '不能授予操作者自身不具备的能力。');
        }

        $profileBlocked = false;
        $anchorBlocked = false;

        foreach ($matching as $holder) {
            // Wildcard removes the capability check only. The binding's field
            // profile and anchor remain the ceiling; `*` at SELF must never be
            // promoted into a COMPANY grant.
            if ($profile->rank() > FieldProfile::from((string) $holder['field_profile'])->rank()) {
                $profileBlocked = true;

                continue;
            }

            if (! $this->anchorCovers((string) $holder['anchor_type'], $anchor)) {
                $anchorBlocked = true;

                continue;
            }

            return;
        }

        if ($profileBlocked) {
            $this->deny(self::REASON_ESCALATION, '不能授予超过操作者自身字段权限的字段档位。');
        }

        if ($anchorBlocked) {
            $this->deny(self::REASON_ESCALATION, '不能授予超过操作者自身范围的锚点。');
        }

        $this->deny(self::REASON_ESCALATION, '不能授予超过操作者自身范围的锚点。');
    }

    /**
     * Check every capability in a proposed role against the actor's ceiling.
     *
     * @param  list<array{capability: string, anchor_type: string, field_profile: string}>  $grants
     */
    public function assertRoleGrantListAllowed(int $actorUserId, array $grants): void
    {
        foreach ($grants as $grant) {
            $this->assertNoPrivilegeEscalation(
                $actorUserId,
                (string) $grant['capability'],
                (string) $grant['anchor_type'],
                (string) $grant['field_profile'],
            );
        }
    }

    /**
     * Check an existing role code before assigning it to a user.
     */
    public function assertRoleAssignmentAllowed(int $actorUserId, string $roleCode): void
    {
        $roleCode = strtoupper(trim($roleCode));

        $this->assertRoleGrantAllowed($actorUserId, $roleCode);

        $grants = RoleCapability::query()
            ->where('role_code', $roleCode)
            ->orderBy('capability_code')
            ->get()
            ->map(static fn (RoleCapability $grant): array => [
                'capability' => (string) $grant->capability_code,
                'anchor_type' => (string) $grant->anchor_type,
                'field_profile' => (string) $grant->field_profile,
            ])
            ->all();

        if ($grants === []) {
            return;
        }

        $this->assertRoleGrantListAllowed($actorUserId, $grants);
    }

    /**
     * Only an existing super administrator may grant the SUPER_ADMIN role.
     */
    public function assertRoleGrantAllowed(int $actorUserId, string $roleCode): void
    {
        if (strtoupper(trim($roleCode)) !== 'SUPER_ADMIN') {
            return;
        }

        if (! $this->isSuperAdmin($actorUserId)) {
            $this->deny(self::REASON_SUPER_ADMIN, '只有现有超级管理员可以授予超级管理员角色。');
        }
    }

    public function isEnabledRoleManager(int $userId): bool
    {
        $user = User::query()->whereKey($userId)->first();

        if (! $user instanceof User || ! $user->isActive()) {
            return false;
        }

        return BindingValidity::whereActiveOn(
            AuthorityBinding::query()
                ->where('principal_id', $userId)
                ->where('auth_version', (int) $user->auth_version)
                ->where('anchor_type', AnchorType::COMPANY->value)
                ->whereNull('anchor_id')
                ->whereIn('capability_code', self::ADMIN_CAPABILITIES),
            $this->clock->now()->toDateString(),
        )->exists();
    }

    public function isSuperAdmin(int $userId): bool
    {
        $user = User::query()->whereKey($userId)->first();

        if (! $user instanceof User || ! $user->isActive()) {
            return false;
        }

        return BindingValidity::whereActiveOn(
            AuthorityBinding::query()
                ->where('principal_id', $userId)
                ->where('auth_version', (int) $user->auth_version)
                ->where('anchor_type', AnchorType::COMPANY->value)
                ->whereNull('anchor_id')
                ->where('capability_code', CapabilityWildcard::WILDCARD),
            $this->clock->now()->toDateString(),
        )->exists();
    }

    /**
     * Count remaining ENABLED administrators other than the supplied user.
     */
    public function otherEnabledAdminCount(int $exceptUserId): int
    {
        $businessDate = $this->clock->now()->toDateString();
        $candidateIds = AuthorityBinding::query()
            ->where('principal_id', '!=', $exceptUserId)
            ->whereIn('capability_code', self::ADMIN_CAPABILITIES)
            ->where('anchor_type', AnchorType::COMPANY->value)
            ->whereNull('anchor_id')
            ->distinct()
            ->pluck('principal_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $count = 0;

        foreach ($candidateIds as $candidateId) {
            $user = User::query()->whereKey($candidateId)->first();

            if (! $user instanceof User || ! $user->isActive()) {
                continue;
            }

            $holds = BindingValidity::whereActiveOn(
                AuthorityBinding::query()
                    ->where('principal_id', $candidateId)
                    ->where('auth_version', (int) $user->auth_version)
                    ->whereIn('capability_code', self::ADMIN_CAPABILITIES)
                    ->where('anchor_type', AnchorType::COMPANY->value)
                    ->whereNull('anchor_id'),
                $businessDate,
            )->exists();

            if ($holds) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<string>|null  $roleCodes
     * @return list<int>
     */
    private function liveRoleIds(User $user, ?array $roleCodes = null): array
    {
        $query = UserRole::query()
            ->where('user_id', (int) $user->id)
            ->where('auth_version', (int) $user->auth_version)
            ->where('valid_from', '<=', $this->clock->now())
            ->where('valid_to', '>', $this->clock->now());

        if ($roleCodes !== null) {
            $query->whereIn('role_code', $roleCodes);
        }

        return $query
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $liveRoleIds
     * @return list<string>
     */
    private function adminRoleCodes(array $liveRoleIds): array
    {
        if ($liveRoleIds === []) {
            return [];
        }

        $roleCodes = UserRole::query()
            ->whereIn('id', $liveRoleIds)
            ->pluck('role_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->unique()
            ->values()
            ->all();

        if ($roleCodes === []) {
            return [];
        }

        $adminRoleCodes = RoleCapability::query()
            ->whereIn('role_code', $roleCodes)
            ->whereIn('capability_code', self::ADMIN_CAPABILITIES)
            ->pluck('role_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->unique()
            ->values()
            ->all();

        if ($adminRoleCodes !== []) {
            return $adminRoleCodes;
        }

        // Code-owned administrator templates may predate role_capabilities in
        // a rebuilt read model; name recognition keeps their protection intact.
        return array_values(array_intersect($roleCodes, ['SUPER_ADMIN', 'SYSTEM_ADMIN']));
    }

    /**
     * @return list<array{capability_code: string, anchor_type: string, anchor_id: int|null, field_profile: string}>
     */
    private function effectiveGrants(int $userId): array
    {
        $user = User::query()->whereKey($userId)->first();

        if (! $user instanceof User || ! $user->isActive()) {
            return [];
        }

        return BindingValidity::whereActiveOn(
            AuthorityBinding::query()
                ->where('principal_id', $userId)
                ->where('auth_version', (int) $user->auth_version),
            $this->clock->now()->toDateString(),
        )
            ->orderBy('id')
            ->get()
            ->map(static fn (AuthorityBinding $binding): array => [
                'capability_code' => (string) $binding->capability_code,
                'anchor_type' => (string) $binding->anchor_type,
                'anchor_id' => $binding->anchor_id === null ? null : (int) $binding->anchor_id,
                'field_profile' => (string) $binding->field_profile,
            ])
            ->all();
    }

    private function anchorCovers(string $heldType, AnchorType $requested): bool
    {
        $held = AnchorType::tryFrom($heldType);

        if ($held === null) {
            return false;
        }

        // A holder may delegate at their own breadth or anything narrower;
        // they may never expand beyond it. COMPANY covers the whole company,
        // ORG_SUBTREE covers its own sub-tree units, MANAGED covers reports.
        return match ($held) {
            AnchorType::COMPANY => true,
            AnchorType::ORG_SUBTREE => in_array($requested, [AnchorType::ORG_SUBTREE, AnchorType::ORG_UNIT], true),
            AnchorType::ORG_UNIT => $requested === AnchorType::ORG_UNIT,
            AnchorType::MANAGED => in_array($requested, [AnchorType::MANAGED, AnchorType::SELF], true),
            AnchorType::PAYROLL_RUN => $requested === AnchorType::PAYROLL_RUN,
            AnchorType::CASE => $requested === AnchorType::CASE,
            AnchorType::SELF => $requested === AnchorType::SELF,
        };
    }

    private function deny(string $reason, string $message): never
    {
        throw new AdminProtectionException($reason, $message);
    }
}
