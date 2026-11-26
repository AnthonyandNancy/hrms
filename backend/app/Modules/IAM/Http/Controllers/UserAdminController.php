<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Modules\IAM\Services\PasswordHasher;
use App\Modules\IAM\Services\SecurityEventService;
use App\Modules\IAM\Services\SessionManager;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\People\Contracts\PeopleWorkspaceDirectory;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\AuthorizationSnapshot;
use App\Shared\Authorization\SodRules;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class UserAdminController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly PasswordHasher $passwords,
        private readonly SecurityEventService $events,
        private readonly SessionManager $sessions,
        private readonly Clock $clock,
        private readonly AuthorizationSnapshot $snapshot,
        private readonly PeopleDirectory $people,
        private readonly PeopleWorkspaceDirectory $workspaceProfiles,
        private readonly PeopleEmploymentWriter $employments,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(User::STATUS_ACTIVE, User::STATUS_LOCKED, User::STATUS_DISABLED)],
            'role_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            ...$this->scopeFieldRules(['page', 'page_size', 'q', 'status', 'role_code']),
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);

        $query = User::query()->orderBy('id');

        $search = isset($validated['q']) ? trim((string) $validated['q']) : '';
        if ($search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('username', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if (isset($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        $now = $this->clock->now();
        $businessDate = $this->businessDates->today()->value();

        $roleCode = isset($validated['role_code']) ? strtoupper(trim((string) $validated['role_code'])) : '';
        if ($roleCode !== '') {
            $query->whereExists(function ($sub) use ($roleCode, $now): void {
                $sub->selectRaw('1')
                    ->from('user_roles')
                    ->whereColumn('user_roles.user_id', 'users.id')
                    ->where('user_roles.role_code', $roleCode)
                    ->whereColumn('user_roles.auth_version', 'users.auth_version')
                    ->where('user_roles.valid_from', '<=', $now)
                    ->where('user_roles.valid_to', '>', $now);
            });
        }

        $total = (int) (clone $query)->count();
        /** @var list<array<string, mixed>> $items */
        $items = [];

        foreach ($query->forPage($page, $pageSize)->get() as $user) {
            /** @var list<array{role_code: string, valid_from: string|null, valid_to: string|null}> $roles */
            $roles = [];
            $rows = UserRole::query()
                ->where('user_id', (int) $user->id)
                ->where('auth_version', (int) $user->auth_version)
                ->where('valid_from', '<=', $now)
                ->where('valid_to', '>', $now)
                ->orderBy('role_code')
                ->get();
            foreach ($rows as $role) {
                $from = $role->getAttribute('valid_from');
                $to = $role->getAttribute('valid_to');
                $roles[] = [
                    'role_code' => (string) $role->role_code,
                    'valid_from' => $from instanceof \Carbon\CarbonInterface ? $from->toIso8601ZuluString() : null,
                    'valid_to' => $to instanceof \Carbon\CarbonInterface ? $to->toIso8601ZuluString() : null,
                ];
            }

            $employmentId = $this->people->employmentForUser((int) $user->id);
            $binding = null;
            $displayName = (string) $user->username;

            if ($employmentId !== null) {
                $snapshot = $this->people->employmentSnapshot($employmentId, $businessDate);

                if ($snapshot !== null) {
                    $profile = $this->workspaceProfiles->profile($employmentId, $businessDate);
                    $personName = $profile === null ? null : $profile->personName;
                    $binding = [
                        'employment_id' => $snapshot->employmentId,
                        'employee_no' => $snapshot->employeeNo,
                        'person_name' => $personName ?? $snapshot->personName,
                    ];
                    $displayName = $personName ?? $snapshot->personName ?? $displayName;
                }
            }

            $items[] = [
                'id' => (int) $user->id,
                'username' => $user->username,
                'display_name' => $displayName,
                'email' => $user->email,
                'status' => $user->status,
                'is_privileged' => (bool) $user->is_privileged,
                'mfa_enrolled' => $user->hasConfirmedMfa(),
                'auth_version' => (int) $user->auth_version,
                'last_login_at' => $user->last_login_at instanceof \Carbon\CarbonInterface
                    ? $user->last_login_at->toIso8601ZuluString()
                    : null,
                'roles' => $roles,
                'employment_binding' => $binding,
            ];
        }

        return ApiResponse::ok(ApiResponse::list($items, $page, $pageSize, $total));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:12', 'max:1024'],
            'is_privileged' => ['sometimes', 'boolean'],
            'employment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string', 'min:3', 'max:255'],
            ...$this->scopeFieldRules([
                'username', 'email', 'display_name', 'password',
                'is_privileged', 'employment_id', 'reason',
            ]),
        ]);

        // The cutover removed tenant/legal-entity dimensions from the user
        // workbench. The rules above reject any stale client payload that still
        // tries to choose a scope instead of silently ignoring it.

        $username = mb_strtolower((string) $validated['username']);

        if (User::query()->where('username', $username)->exists()) {
            throw new ValidationException(['username' => ['The username has already been taken.']]);
        }

        $employmentId = isset($validated['employment_id']) ? (int) $validated['employment_id'] : null;

        if ($employmentId !== null && ! $this->people->employmentExistsInTenant($employmentId)) {
            throw new ValidationException(['employment_id' => ['The selected employment does not exist.']]);
        }

        $displayName = isset($validated['display_name']) ? trim((string) $validated['display_name']) : '';

        $user = DB::transaction(function () use ($validated, $username, $principal, $request, $employmentId, $displayName): User {
            /** @var User $created */
            $created = User::query()->create([
                'username' => $username,
                'email' => $validated['email'] ?? null,
                'password_hash' => $this->passwords->hash((string) $validated['password']),
                'status' => User::STATUS_ACTIVE,
                'auth_version' => 1,
                'is_privileged' => (bool) ($validated['is_privileged'] ?? false),
            ]);

            if ($employmentId !== null) {
                $this->employments->linkUser(
                    userId: (int) $created->id,
                    employmentId: $employmentId,
                    validFrom: $this->businessDates->today()->value(),
                    validTo: null,
                    actorUserId: $principal->userId(),
                );
            }

            $this->events->record($principal->userId(), 'user.created', 'SUCCESS', [
                'target_user_id' => (int) $created->id,
                'reason' => $displayName !== '' ? $displayName : $username,
            ], $request);

            return $created;
        });

        return ApiResponse::created([
            'id' => (int) $user->id,
            'username' => $user->username,
            'display_name' => $displayName !== '' ? $displayName : $user->username,
            'status' => $user->status,
            'employment_id' => $employmentId,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $validated = $request->validate([
            'status' => ['required', Rule::in(User::STATUS_ACTIVE, User::STATUS_LOCKED, User::STATUS_DISABLED)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        /** @var User|null $user */
        $user = User::query()->whereKey($id)->first();

        if ($user === null) {
            throw new NotFoundOrForbiddenException;
        }

        /** @var array{status: string, auth_version: int} $result */
        $result = DB::transaction(function () use ($user, $validated, $principal, $request): array {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->assertNotLastActiveAdmin($locked, (string) $validated['status']);

            $old = $locked->status;
            $locked->forceFill([
                'status' => (string) $validated['status'],
            ])->save();

            $this->sessions->revokeAllForUser((int) $locked->id);
            $version = $this->snapshot->replay($locked, 'user.status.changed');
            $this->events->record($principal->userId(), 'user.status.changed', 'SUCCESS', [
                'target_user_id' => (int) $locked->id,
                'reason' => (string) $validated['reason'],
                'role_code' => $old.'->'.$locked->status,
            ], $request);

            return ['status' => (string) $locked->status, 'auth_version' => $version];
        });

        return ApiResponse::ok([
            'id' => (int) $user->id,
            'status' => $result['status'],
            'auth_version' => $result['auth_version'],
        ]);
    }

    public function assignRole(Request $request, int $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $validated = $request->validate([
            'role_code' => ['required', 'string', 'max:64'],
            'valid_from' => ['sometimes', 'date'],
            'valid_to' => ['sometimes', 'date', 'after:valid_from'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'binding_anchors' => ['sometimes', 'array'],
            'binding_anchors.*' => ['integer', 'min:1'],
            'acknowledge_sod_risk' => ['sometimes', 'boolean'],
        ]);

        if (! RoleTemplateCatalog::validateCode((string) $validated['role_code'])) {
            throw new ValidationException(['role_code' => ['Role codes must be structural uppercase identifiers, not person or department names.']]);
        }

        /** @var User|null $target */
        $target = User::query()->whereKey($id)->first();
        /** @var Role|null $role */
        $role = Role::query()->whereKey((string) $validated['role_code'])->first();

        if ($target === null || $role === null) {
            throw new NotFoundOrForbiddenException;
        }

        if (! $role->is_template) {
            if ($role->owner === null || $role->purpose === '' || $role->expires_at === null) {
                throw new ValidationException(['role_code' => ['Exception roles require owner, purpose and expiry.']]);
            }
        }

        $grants = RoleCapability::query()->where('role_code', $role->code)->get();
        $anchors = $this->resolveRoleAnchors($request, $grants);
        /** @var list<string> $capabilities */
        $capabilities = $grants->pluck('capability_code')->map(static fn (mixed $code): string => (string) $code)->all();
        $combined = array_values(array_unique([...$capabilities, ...$this->currentCapabilityCodes((int) $target->id)]));
        $risks = SodRules::risks($combined);

        if ($risks !== [] && ! $request->boolean('acknowledge_sod_risk')) {
            throw new ValidationException(
                ['acknowledge_sod_risk' => ['This grant creates a separation-of-duties risk. Confirm to proceed.']],
                'The given data was invalid.',
                ['reason' => 'SOD_RISK_NOT_ACKNOWLEDGED', 'risks' => $risks],
            );
        }

        // Bindings are evaluated per business day, so the stored window starts
        // at the day boundary. A grant created at 00:42 must be usable today.
        $now = $this->clock->now();
        $from = isset($validated['valid_from'])
            ? CarbonImmutable::parse((string) $validated['valid_from'], 'UTC')->startOfDay()
            : $now->startOfDay();
        $to = isset($validated['valid_to'])
            ? CarbonImmutable::parse((string) $validated['valid_to'], 'UTC')->startOfDay()
            : $from->addYear();

        if ($role->expires_at instanceof \Carbon\CarbonInterface && $role->expires_at->lessThan($to)) {
            $to = $role->expires_at;
        }

        DB::transaction(function () use ($target, $principal, $role, $grants, $anchors, $validated, $risks, $from, $to, $request): void {
            $user = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $currentVersion = (int) $user->auth_version;

            UserRole::query()->create([
                'user_id' => (int) $user->id,
                'role_code' => (string) $role->code,
                'valid_from' => $from,
                'valid_to' => $to,
                'granted_by' => $principal->userId(),
                'reason' => (string) $validated['reason'],
                'auth_version' => $currentVersion,
                'created_at' => $this->clock->now(),
            ]);

            foreach ($grants as $grant) {
                AuthorityBinding::query()->create([
                    'principal_id' => (int) $user->id,
                    'capability_code' => (string) $grant->capability_code,
                    'anchor_type' => (string) $grant->anchor_type,
                    'anchor_id' => $anchors[(string) $grant->anchor_type] ?? null,
                    'anchor_key' => $anchors[(string) $grant->anchor_type] ?? 0,
                    'field_profile' => (string) $grant->field_profile,
                    'purpose_code' => 'ROLE_'.$role->code,
                    'valid_from' => $from,
                    'valid_to' => $to,
                    'source_type' => 'ROLE',
                    'source_id' => null,
                    'auth_version' => $currentVersion,
                    'created_at' => $this->clock->now(),
                ]);
            }

            $this->snapshot->replay($user, 'role.assigned');
            $this->sessions->revokeAllForUser((int) $user->id);
            $this->events->record($principal->userId(), 'role.assigned', 'SUCCESS', [
                'target_user_id' => (int) $user->id,
                'role_code' => (string) $role->code,
                'reason' => (string) $validated['reason'],
            ], $request);

            if ($risks !== []) {
                $this->events->record($principal->userId(), 'security.sod_risk_accepted', 'SUCCESS', [
                    'target_user_id' => (int) $user->id,
                    'role_code' => (string) $role->code,
                    'reason' => (string) $validated['reason'],
                    'risks' => $risks,
                ], $request);
            }
        });

        $payload = ['user_id' => (int) $target->id, 'role_code' => $role->code];

        if ($risks !== []) {
            $payload['warnings'] = $risks;
        }

        return ApiResponse::created($payload);
    }

    public function revokeRole(Request $request, int $id, string $roleCode): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        /** @var User|null $target */
        $target = User::query()->whereKey($id)->first();

        if ($target === null) {
            throw new NotFoundOrForbiddenException;
        }

        $roleCode = strtoupper(trim($roleCode));

        DB::transaction(function () use ($target, $roleCode, $principal, $validated, $request): void {
            $user = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $currentVersion = (int) $user->auth_version;

            $this->assertNotLastAdminRoleRemoval($user, $roleCode);

            $deleted = UserRole::query()
                ->where('user_id', (int) $user->id)
                ->where('role_code', $roleCode)
                ->where('auth_version', $currentVersion)
                ->delete();

            if ($deleted === 0) {
                throw new NotFoundOrForbiddenException;
            }

            // Remove the capability bundle this role granted in this snapshot.
            AuthorityBinding::query()
                ->where('principal_id', (int) $user->id)
                ->where('auth_version', $currentVersion)
                ->where('purpose_code', 'ROLE_'.$roleCode)
                ->delete();

            $this->snapshot->replay($user, 'role.revoked');
            $this->sessions->revokeAllForUser((int) $user->id);
            $this->events->record($principal->userId(), 'role.revoked', 'SUCCESS', [
                'target_user_id' => (int) $user->id,
                'role_code' => $roleCode,
                'reason' => (string) $validated['reason'],
            ], $request);
        });

        return ApiResponse::ok([
            'user_id' => (int) $target->id,
            'role_code' => $roleCode,
            'revoked' => true,
        ]);
    }

    /**
     * The single company must retain at least one usable system administrator.
     * Administrator identity is live authority, not the display-only
     * `is_privileged` flag: only an ENABLED user holding a COMPANY-scoped
     * admin capability counts, and disabling the last one is a business
     * conflict rather than a silent lockout.
     */
    private function assertNotLastActiveAdmin(User $user, string $nextStatus): void
    {
        if ($nextStatus === User::STATUS_ACTIVE || ! $user->isActive()) {
            return;
        }

        $now = $this->clock->now();
        $adminCapabilities = ['iam.role.manage', 'iam.binding.manage', '*'];

        $heldCapabilities = AuthorityBinding::query()
            ->where('principal_id', (int) $user->id)
            ->where('auth_version', (int) $user->auth_version)
            ->where('anchor_type', AnchorType::COMPANY->value)
            ->whereNull('anchor_id')
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now)
            ->whereIn('capability_code', $adminCapabilities)
            ->exists();

        if (! $heldCapabilities) {
            return;
        }

        $otherActiveAdmins = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereKeyNot((int) $user->id)
            ->whereExists(function ($sub) use ($now, $adminCapabilities): void {
                $sub->selectRaw('1')
                    ->from('authority_bindings')
                    ->whereColumn('authority_bindings.principal_id', 'users.id')
                    ->whereColumn('authority_bindings.auth_version', 'users.auth_version')
                    ->where('authority_bindings.anchor_type', AnchorType::COMPANY->value)
                    ->whereNull('authority_bindings.anchor_id')
                    ->where('authority_bindings.valid_from', '<=', $now)
                    ->where('authority_bindings.valid_to', '>', $now)
                    ->whereIn('authority_bindings.capability_code', $adminCapabilities);
            })
            ->count();

        if ($otherActiveAdmins === 0) {
            throw new ConflictException(
                'The last active administrator cannot be disabled.',
                ['reason' => 'LAST_ACTIVE_ADMIN'],
            );
        }
    }

    /**
     * Revoking the only live administrator role from the only enabled
     * administrator would leave the company with no manageable authority.
     * The role catalog decides which grants carry admin authority.
     */
    private function assertNotLastAdminRoleRemoval(User $user, string $roleCode): void
    {
        $grants = RoleCapability::query()
            ->where('role_code', $roleCode)
            ->whereIn('capability_code', ['iam.role.manage', 'iam.binding.manage', '*'])
            ->exists();

        if (! $grants) {
            return;
        }

        $now = $this->clock->now();
        $adminRoleCodes = RoleCapability::query()
            ->whereIn('capability_code', ['iam.role.manage', 'iam.binding.manage', '*'])
            ->distinct()
            ->pluck('role_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        $remainingAdminRoles = UserRole::query()
            ->where('user_id', (int) $user->id)
            ->where('auth_version', (int) $user->auth_version)
            ->whereIn('role_code', $adminRoleCodes)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now)
            ->where('role_code', '!=', $roleCode)
            ->count();

        if ($remainingAdminRoles > 0) {
            return;
        }

        $otherActiveAdmins = User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereKeyNot((int) $user->id)
            ->whereExists(function ($sub) use ($now, $adminRoleCodes): void {
                $sub->selectRaw('1')
                    ->from('user_roles')
                    ->whereColumn('user_roles.user_id', 'users.id')
                    ->whereColumn('user_roles.auth_version', 'users.auth_version')
                    ->whereIn('user_roles.role_code', $adminRoleCodes)
                    ->where('user_roles.valid_from', '<=', $now)
                    ->where('user_roles.valid_to', '>', $now);
            })
            ->count();

        if ($otherActiveAdmins === 0) {
            throw new ConflictException(
                'The last active administrator role cannot be revoked.',
                ['reason' => 'LAST_ACTIVE_ADMIN'],
            );
        }
    }

    /**
     * Role templates can span anchors. Every anchor that requires an id must
     * be supplied explicitly; COMPANY is the whole single-company instance
     * and never carries an anchor id.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, RoleCapability>  $grants
     * @return array<string, int>
     */
    private function resolveRoleAnchors(Request $request, $grants): array
    {
        $provided = $request->input('binding_anchors');
        $provided = is_array($provided) ? $provided : [];
        $resolved = [];

        foreach ($grants as $grant) {
            $anchor = AnchorType::tryFrom(strtoupper((string) $grant->anchor_type));

            if ($anchor === null) {
                throw new ValidationException(['binding_anchors' => ['A role contains an unknown anchor type.']]);
            }

            // COMPANY is the whole single-company instance: anchor_id is null,
            // anchor_key is 0, and no client header can widen or narrow it.
            if (! $anchor->requiresAnchorId() || $anchor === AnchorType::COMPANY) {
                continue;
            }

            $value = $provided[$anchor->value] ?? null;

            if (! is_numeric($value) || (int) $value <= 0) {
                throw new ValidationException([
                    'binding_anchors.'.$anchor->value => ['This role requires an anchor id for '.$anchor->value.'.'],
                ], 'The given data was invalid.', ['reason' => 'ROLE_ANCHOR_REQUIRED']);
            }

            $resolved[$anchor->value] = (int) $value;
        }

        return $resolved;
    }

    /**
     * Single-company deployment: request bodies are whitelisted, so a stale
     * multi-company client payload is rejected instead of being silently
     * ignored. Query-string values are excluded from this check because they
     * are governed by their own rules above.
     *
     * @param  list<string>  $allowed
     * @return array<string, list<string>>
     */
    private function scopeFieldRules(array $allowed): array
    {
        $rules = [];
        foreach (array_keys(request()->request->all()) as $field) {
            if (! in_array($field, $allowed, true)) {
                $rules[(string) $field] = ['prohibited'];
            }
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function currentCapabilityCodes(int $userId): array
    {
        return AuthorityBinding::query()
            ->where('principal_id', $userId)
            ->where('valid_from', '<=', $this->clock->now())
            ->where('valid_to', '>', $this->clock->now())
            ->pluck('capability_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
    }
}
