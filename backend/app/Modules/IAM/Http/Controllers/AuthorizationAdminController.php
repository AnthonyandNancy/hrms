<?php

declare(strict_types=1);

namespace App\Modules\IAM\Http\Controllers;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Capability;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\UserRole;
use App\Modules\IAM\Services\SecurityEventService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\AuthorizationSnapshot;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\SodRules;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuthorizationAdminController
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly SecurityEventService $events,
        private readonly Clock $clock,
        private readonly AuthorizationSnapshot $snapshot,
    ) {}

    public function roles(): JsonResponse
    {
        $roles = Role::query()
            ->orderBy('is_template', 'desc')
            ->orderBy('code')
            ->get()
            ->map(function (Role $role): array {
                /** @var Role $role */
                $grants = RoleCapability::query()
                    ->where('role_code', $role->code)
                    ->orderBy('capability_code')
                    ->get()
                    ->map(static fn (RoleCapability $grant): array => [
                        'capability' => $grant->capability_code,
                        'anchor_type' => $grant->anchor_type,
                        'field_profile' => $grant->field_profile,
                    ])->all();

                return [
                    'code' => $role->code,
                    'name' => $role->name,
                    'is_template' => (bool) $role->is_template,
                    'purpose' => $role->purpose,
                    'expires_at' => $role->expires_at instanceof \Carbon\CarbonInterface ? $role->expires_at->toIso8601ZuluString() : null,
                    'capabilities' => $grants,
                    'page_count' => RolePage::query()->where('role_code', $role->code)->count(),
                    'member_count' => UserRole::query()
                        ->where('role_code', $role->code)
                        ->distinct()
                        ->count('user_id'),
                ];
            })
            ->all();

        return ApiResponse::ok(['roles' => $roles]);
    }

    public function capabilities(): JsonResponse
    {
        $capabilities = Capability::query()
            ->orderBy('code')
            ->get()
            ->map(static fn (Capability $capability): array => [
                'code' => $capability->code,
                'resource' => $capability->resource,
                'actions' => $capability->actions_json,
                'anchor_types' => $capability->anchor_types_json,
                'field_profile_max' => $capability->field_profile_max,
                'risk' => $capability->risk,
                'sod_group' => $capability->sod_group,
                'description' => $capability->description,
            ])
            ->all();

        return ApiResponse::ok(['capabilities' => $capabilities, 'read_only' => true]);
    }

    public function bindings(Request $request, int $userId): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $target = \App\Modules\IAM\Models\User::query()
            ->whereKey($userId)
            ->first();

        if ($target === null) {
            throw new NotFoundOrForbiddenException;
        }

        $bindings = AuthorityBinding::query()
            ->where('principal_id', $userId)
            ->orderBy('capability_code')
            ->orderBy('anchor_type')
            ->orderBy('anchor_id')
            ->get()
            ->map(fn (AuthorityBinding $binding): array => [
                'id' => (int) $binding->id,
                'capability' => $binding->capability_code,
                'anchor_type' => $binding->anchor_type,
                'anchor_id' => $binding->anchor_id,
                'field_profile' => $binding->field_profile,
                'purpose_code' => $binding->purpose_code,
                'valid_from' => $binding->valid_from->toIso8601ZuluString(),
                'valid_to' => $binding->valid_to->toIso8601ZuluString(),
                'active' => $binding->valid_from->lessThanOrEqualTo($this->clock->now())
                    && $binding->valid_to->greaterThan($this->clock->now()),
            ])
            ->all();

        return ApiResponse::ok(['bindings' => $bindings]);
    }

    public function storeBinding(Request $request, int $userId): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        $validated = $request->validate([
            'capability_code' => ['required', 'string', 'max:120'],
            'anchor_type' => ['required', 'string', 'max:24'],
            'anchor_id' => ['nullable', 'integer', 'min:1'],
            'field_profile' => ['required', 'string', 'max:24'],
            'purpose_code' => ['required', 'string', 'max:48'],
            'valid_from' => ['sometimes', 'date'],
            'valid_to' => ['sometimes', 'date', 'after:valid_from'],
            'acknowledge_sod_risk' => ['sometimes', 'boolean'],
        ]);

        if (CapabilityWildcard::is((string) $validated['capability_code'])) {
            throw new ValidationException(
                ['capability_code' => ['The wildcard capability cannot be granted through the API.']],
                'The given data was invalid.',
                ['reason' => 'WILDCARD_NOT_GRANTABLE'],
            );
        }

        $target = \App\Modules\IAM\Models\User::query()
            ->whereKey($userId)
            ->first();

        if ($target === null) {
            throw new NotFoundOrForbiddenException;
        }

        $anchor = AnchorType::tryFrom(strtoupper((string) $validated['anchor_type']));
        $profile = FieldProfile::tryFrom((string) $validated['field_profile']);
        $capability = Capability::query()->whereKey((string) $validated['capability_code'])->first();

        if ($anchor === null) {
            throw new ValidationException(['anchor_type' => ['Unknown anchor type.']], 'The given data was invalid.', ['reason' => 'INVALID_ANCHOR']);
        }

        if ($capability === null || ! in_array($anchor->value, $capability->anchor_types_json ?? [], true)) {
            throw new ValidationException(['anchor_type' => ['This capability does not support the anchor type.']]);
        }

        if ($profile === null || $capability === null || $profile->rank() > FieldProfile::from((string) $capability->field_profile_max)->rank()) {
            throw new ValidationException(['field_profile' => ['The field profile exceeds the capability maximum.']]);
        }

        if ($anchor->requiresAnchorId() && ! isset($validated['anchor_id'])) {
            throw new ValidationException(['anchor_id' => ['This anchor type requires an id.']]);
        }

        if (! $anchor->requiresAnchorId() && isset($validated['anchor_id'])) {
            throw new ValidationException(['anchor_id' => ['This anchor type must not carry an id.']]);
        }

        $existing = AuthorityBinding::query()
            ->where('principal_id', $userId)
            ->where('valid_from', '<=', $this->clock->now())
            ->where('valid_to', '>', $this->clock->now())
            ->pluck('capability_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        $combined = array_values(array_unique([...$existing, (string) $validated['capability_code']]));
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

        $binding = DB::transaction(function () use ($target, $principal, $validated, $anchor, $profile, $risks, $from, $to, $request): AuthorityBinding {
            $user = \App\Modules\IAM\Models\User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $currentVersion = (int) $user->auth_version;

            /** @var AuthorityBinding $created */
            $created = AuthorityBinding::query()->create([
                'principal_id' => (int) $user->id,
                'capability_code' => (string) $validated['capability_code'],
                'anchor_type' => $anchor->value,
                'anchor_id' => $anchor->requiresAnchorId() ? (int) $validated['anchor_id'] : null,
                'anchor_key' => $anchor->requiresAnchorId() ? (int) $validated['anchor_id'] : 0,
                'field_profile' => $profile->value,
                'purpose_code' => (string) $validated['purpose_code'],
                'valid_from' => $from,
                'valid_to' => $to,
                'source_type' => 'MANUAL',
                'source_id' => null,
                'auth_version' => $currentVersion,
                'created_at' => $this->clock->now(),
            ]);

            $this->snapshot->replay($user, 'binding.created');

            $this->events->record($principal->userId(), 'binding.created', 'SUCCESS', [
                'target_user_id' => (int) $user->id,
                'capability' => (string) $validated['capability_code'],
                'anchor_type' => $anchor->value,
                'anchor_id' => $anchor->requiresAnchorId() ? (int) $validated['anchor_id'] : null,
                'binding_id' => (int) $created->id,
            ], $request);

            if ($risks !== []) {
                $this->events->record($principal->userId(), 'security.sod_risk_accepted', 'SUCCESS', [
                    'target_user_id' => (int) $user->id,
                    'capability' => (string) $validated['capability_code'],
                    'anchor_type' => $anchor->value,
                    'anchor_id' => $anchor->requiresAnchorId() ? (int) $validated['anchor_id'] : null,
                    'binding_id' => (int) $created->id,
                    'risks' => $risks,
                ], $request);
            }

            return $created;
        });

        $payload = [
            'id' => (int) $binding->id,
            'user_id' => (int) $binding->principal_id,
            'capability' => $binding->capability_code,
            'anchor_type' => $binding->anchor_type,
            'anchor_id' => $binding->anchor_id,
            'field_profile' => $binding->field_profile,
        ];

        if ($risks !== []) {
            $payload['warnings'] = $risks;
        }

        return ApiResponse::created($payload);
    }

    public function destroyBinding(Request $request, int $id): JsonResponse
    {
        $principal = $this->context->requirePrincipal();
        /** @var AuthorityBinding|null $binding */
        $binding = AuthorityBinding::query()
            ->whereKey($id)
            ->first();

        if ($binding === null) {
            throw new NotFoundOrForbiddenException;
        }

        DB::transaction(function () use ($binding, $principal, $request): void {
            $user = \App\Modules\IAM\Models\User::query()->whereKey($binding->principal_id)->lockForUpdate()->firstOrFail();
            $binding->delete();
            $this->snapshot->replay($user, 'binding.revoked');

            $this->events->record($principal->userId(), 'binding.revoked', 'SUCCESS', [
                'target_user_id' => (int) $binding->principal_id,
                'capability' => $binding->capability_code,
                'anchor_type' => $binding->anchor_type,
                'binding_id' => (int) $binding->id,
            ], $request);
        });

        return ApiResponse::ok(['revoked' => true, 'binding_id' => $id]);
    }

    public function authEvents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);
        $principal = $this->context->requirePrincipal();

        $query = \App\Modules\IAM\Models\AuthEvent::query()
            ->orderByDesc('id');
        $total = (int) (clone $query)->count();

        $items = $query->forPage($page, $pageSize)->get()->map(static fn (\App\Modules\IAM\Models\AuthEvent $event): array => [
            'id' => (int) $event->id,
            'user_id' => $event->user_id,
            'event_type' => $event->event_type,
            'result' => $event->result,
            'metadata' => $event->metadata_json,
            'request_id' => $event->request_id,
            'occurred_at' => $event->occurred_at->toIso8601ZuluString(),
        ])->all();

        return ApiResponse::ok(ApiResponse::list($items, $page, $pageSize, $total));
    }
}
