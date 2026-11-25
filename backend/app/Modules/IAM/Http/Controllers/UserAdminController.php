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
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\AuthorizationSnapshot;
use App\Shared\Authorization\SodRules;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);
        $principal = $this->context->requirePrincipal();

        $query = User::query()->where('tenant_id', $principal->tenantId())->orderBy('id');
        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get()->map(fn (User $user): array => [
            'id' => (int) $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'status' => $user->status,
            'is_privileged' => (bool) $user->is_privileged,
            'mfa_enrolled' => $user->hasConfirmedMfa(),
            'auth_version' => (int) $user->auth_version,
        ])->all();

        return ApiResponse::ok(ApiResponse::list($items, $page, $pageSize, $total));
    }

    public function store(Request $request): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:1024'],
            'is_privileged' => ['sometimes', 'boolean'],
        ]);

        $username = mb_strtolower((string) $validated['username']);

        if (User::query()->where('tenant_id', $principal->tenantId())->where('username', $username)->exists()) {
            throw new ValidationException(['username' => ['The username has already been taken.']]);
        }

        $user = DB::transaction(function () use ($validated, $username, $principal, $request): User {
            /** @var User $created */
            $created = User::query()->create([
                'tenant_id' => $principal->tenantId(),
                'username' => $username,
                'email' => $validated['email'] ?? null,
                'password_hash' => $this->passwords->hash((string) $validated['password']),
                'status' => User::STATUS_ACTIVE,
                'auth_version' => 1,
                'is_privileged' => (bool) ($validated['is_privileged'] ?? false),
            ]);

            $this->events->record($principal->tenantId(), $principal->userId(), 'user.created', 'SUCCESS', [
                'target_user_id' => (int) $created->id,
            ], $request);

            return $created;
        });

        return ApiResponse::created([
            'id' => (int) $user->id,
            'username' => $user->username,
            'status' => $user->status,
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
        $user = User::query()->where('tenant_id', $principal->tenantId())->whereKey($id)->first();

        if ($user === null) {
            throw new NotFoundOrForbiddenException;
        }

        DB::transaction(function () use ($user, $validated, $principal, $request): void {
            $old = $user->status;
            $user->forceFill([
                'status' => (string) $validated['status'],
            ])->save();

            $this->sessions->revokeAllForUser((int) $user->tenant_id, (int) $user->id);
            $this->snapshot->replay($user, 'user.status.changed');
            $this->events->record((int) $user->tenant_id, $principal->userId(), 'user.status.changed', 'SUCCESS', [
                'target_user_id' => (int) $user->id,
                'reason' => (string) $validated['reason'],
                'role_code' => $old.'->'.$user->status,
            ], $request);
        });

        return ApiResponse::ok([
            'id' => (int) $user->id,
            'status' => $user->status,
            'auth_version' => (int) $user->auth_version,
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
        $target = User::query()->where('tenant_id', $principal->tenantId())->whereKey($id)->first();
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
                'tenant_id' => (int) $user->tenant_id,
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
                    'tenant_id' => (int) $user->tenant_id,
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
            $this->sessions->revokeAllForUser((int) $user->tenant_id, (int) $user->id);
            $this->events->record((int) $user->tenant_id, $principal->userId(), 'role.assigned', 'SUCCESS', [
                'target_user_id' => (int) $user->id,
                'role_code' => (string) $role->code,
                'reason' => (string) $validated['reason'],
            ], $request);

            if ($risks !== []) {
                $this->events->record((int) $user->tenant_id, $principal->userId(), 'security.sod_risk_accepted', 'SUCCESS', [
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

    /**
     * Role templates can span anchors. Every anchor that requires an id must
     * be supplied explicitly, except LEGAL_ENTITY which may come from the
     * route's legal entity header.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, RoleCapability>  $grants
     * @return array<string, int>
     */
    private function resolveRoleAnchors(Request $request, $grants): array
    {
        $provided = $request->input('binding_anchors');
        $provided = is_array($provided) ? $provided : [];
        $legalEntity = $request->header('X-Legal-Entity-Id') ?? $request->input('legal_entity_id');
        $resolved = [];

        foreach ($grants as $grant) {
            $anchor = AnchorType::tryFrom(strtoupper((string) $grant->anchor_type));

            if ($anchor === null) {
                throw new ValidationException(['binding_anchors' => ['A role contains an unknown anchor type.']]);
            }

            if (! $anchor->requiresAnchorId()) {
                continue;
            }

            $value = $provided[$anchor->value] ?? null;

            if ($value === null && $anchor === AnchorType::LEGAL_ENTITY && is_numeric($legalEntity)) {
                $value = (int) $legalEntity;
            }

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
