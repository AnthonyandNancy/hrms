<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Authorization\CapabilityCatalog;
use App\Modules\IAM\Authorization\CapabilityDefinition;
use App\Modules\IAM\Authorization\RoleTemplateCatalog;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\AuthorizationSnapshot;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Exception-role authoring. Capability semantics are read from the code-owned
 * catalog; this service only writes role/capability selections and replays the
 * affected users' snapshots in the same transaction.
 */
final class RoleAdminService
{
    public function __construct(
        private readonly CapabilityCatalog $catalog,
        private readonly AuthorizationSnapshot $snapshot,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array{code: string, name: string, purpose: string, owner: int, expires_at: string|null, capabilities: list<array{capability: string, anchor_type: string, field_profile: string}>}  $data
     * @return array<string, mixed>
     */
    public function create(int $tenantId, array $data): array
    {
        $code = strtoupper(trim($data['code']));

        if (! RoleTemplateCatalog::validateCode($code)) {
            throw new ValidationException(['code' => ['Role codes must be structural uppercase identifiers.']], 'The given data was invalid.', ['reason' => 'ROLE_CODE_INVALID']);
        }

        if (Role::query()->whereKey($code)->exists()) {
            throw new ConflictException('This role code already exists.', ['reason' => 'ROLE_CODE_EXISTS']);
        }

        $this->assertNonEmptyPurpose((string) $data['purpose']);
        $grants = $this->validateGrants($data['capabilities']);

        return DB::transaction(function () use ($tenantId, $data, $code, $grants): array {
            /** @var Role $role */
            $role = Role::query()->create([
                'code' => $code,
                'tenant_id' => $tenantId,
                'name' => trim((string) $data['name']),
                'is_template' => false,
                'owner' => (int) $data['owner'],
                'purpose' => trim((string) $data['purpose']),
                'expires_at' => $data['expires_at'] ?? $this->clock->now()->addYear(),
            ]);

            $this->replaceGrants($tenantId, $code, $grants);

            return $this->payload($role);
        });
    }

    /**
     * @param  array{code: string, name: string, purpose: string, owner: int}  $data
     * @return array<string, mixed>
     */
    public function copy(int $tenantId, string $sourceCode, array $data): array
    {
        /** @var Role|null $source */
        $source = Role::query()->whereKey($sourceCode)->first();

        if ($source === null) {
            throw new NotFoundOrForbiddenException;
        }

        $code = strtoupper(trim($data['code']));

        if (! RoleTemplateCatalog::validateCode($code)) {
            throw new ValidationException(['code' => ['Role codes must be structural uppercase identifiers.']], 'The given data was invalid.', ['reason' => 'ROLE_CODE_INVALID']);
        }

        if (Role::query()->whereKey($code)->exists()) {
            throw new ConflictException('This role code already exists.', ['reason' => 'ROLE_CODE_EXISTS']);
        }

        $this->assertNonEmptyPurpose((string) $data['purpose']);
        $grants = RoleCapability::query()
            ->where('role_code', $sourceCode)
            ->orderBy('capability_code')
            ->orderBy('anchor_type')
            ->get()
            ->map(static fn (RoleCapability $grant): array => [
                'capability' => (string) $grant->capability_code,
                'anchor_type' => (string) $grant->anchor_type,
                'field_profile' => (string) $grant->field_profile,
            ])->all();

        $validated = $this->validateGrants($grants);

        return DB::transaction(function () use ($tenantId, $data, $code, $validated): array {
            /** @var Role $role */
            $role = Role::query()->create([
                'code' => $code,
                'tenant_id' => $tenantId,
                'name' => trim((string) $data['name']),
                'is_template' => false,
                'owner' => (int) $data['owner'],
                'purpose' => trim((string) $data['purpose']),
                'expires_at' => $this->clock->now()->addYear(),
            ]);

            $this->replaceGrants($tenantId, $code, $validated);

            return $this->payload($role);
        });
    }

    /**
     * @param  list<array{capability: string, anchor_type: string, field_profile: string}>  $capabilities
     * @return array<string, mixed>
     */
    public function replaceCapabilities(int $tenantId, string $roleCode, array $capabilities): array
    {
        /** @var Role|null $role */
        $role = Role::query()->whereKey($roleCode)->first();

        if ($role === null) {
            throw new NotFoundOrForbiddenException;
        }

        if ((bool) $role->is_template) {
            throw new ConflictException('Template roles are code-owned and cannot be edited through the API.', ['reason' => 'TEMPLATE_ROLE_IMMUTABLE']);
        }

        $grants = $this->validateGrants($capabilities);

        return DB::transaction(function () use ($tenantId, $role, $grants): array {
            $this->replaceGrants($tenantId, (string) $role->code, $grants);

            UserRole::query()
                ->where('tenant_id', $tenantId)
                ->where('role_code', (string) $role->code)
                ->orderBy('user_id')
                ->pluck('user_id')
                ->unique()
                ->each(function (mixed $userId) use ($tenantId): void {
                    $user = User::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey((int) $userId)
                        ->first();

                    if ($user instanceof User) {
                        $this->snapshot->replay($user, 'role.capabilities.changed');
                    }
                });

            return $this->payload($role->refresh());
        });
    }

    /**
     * @param  list<array{capability: string, anchor_type: string, field_profile: string}>  $capabilities
     * @return list<array{capability: string, anchor_type: string, field_profile: string}>
     */
    private function validateGrants(array $capabilities): array
    {
        $validated = [];
        $seen = [];

        foreach ($capabilities as $index => $row) {
            $code = (string) ($row['capability'] ?? '');

            /** @var CapabilityDefinition|null $definition */
            $definition = $this->catalog->find($code);

            if ($definition === null) {
                throw new ValidationException(
                    ["capabilities.{$index}.capability" => ['Unknown capability code.']],
                    'The given data was invalid.',
                    ['reason' => 'UNKNOWN_CAPABILITY'],
                );
            }

            $anchor = AnchorType::tryFrom(strtoupper((string) ($row['anchor_type'] ?? '')));

            if ($anchor === null || ! in_array($anchor, $definition->anchorTypes, true)) {
                throw new ValidationException(
                    ["capabilities.{$index}.anchor_type" => ['This capability does not allow the requested anchor.']],
                    'The given data was invalid.',
                    ['reason' => 'CAPABILITY_ANCHOR_NOT_ALLOWED'],
                );
            }

            $profile = FieldProfile::tryFrom((string) ($row['field_profile'] ?? ''));

            if ($profile === null || $profile->rank() > $definition->fieldProfileMax->rank()) {
                throw new ValidationException(
                    ["capabilities.{$index}.field_profile" => ['The field profile exceeds the capability maximum.']],
                    'The given data was invalid.',
                    ['reason' => 'FIELD_PROFILE_EXCEEDS_CAPABILITY'],
                );
            }

            $key = $code.'|'.$anchor->value;

            if (isset($seen[$key])) {
                throw new ValidationException(
                    ["capabilities.{$index}.capability" => ['Duplicate capability and anchor pair.']],
                    'The given data was invalid.',
                    ['reason' => 'DUPLICATE_CAPABILITY_GRANT'],
                );
            }

            $seen[$key] = true;
            $validated[] = [
                'capability' => $code,
                'anchor_type' => $anchor->value,
                'field_profile' => $profile->value,
            ];
        }

        return $validated;
    }

    /**
     * @param  list<array{capability: string, anchor_type: string, field_profile: string}>  $grants
     */
    private function replaceGrants(int $tenantId, string $roleCode, array $grants): void
    {
        RoleCapability::query()->where('role_code', $roleCode)->delete();

        foreach ($grants as $grant) {
            RoleCapability::query()->create([
                'tenant_id' => $tenantId,
                'role_code' => $roleCode,
                'capability_code' => $grant['capability'],
                'anchor_type' => $grant['anchor_type'],
                'field_profile' => $grant['field_profile'],
            ]);
        }
    }

    private function assertNonEmptyPurpose(string $purpose): void
    {
        if (mb_strlen(trim($purpose)) < 8) {
            throw new ValidationException(['purpose' => ['A purpose of at least 8 characters is required.']], 'The given data was invalid.', ['reason' => 'ROLE_PURPOSE_REQUIRED']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Role $role): array
    {
        return [
            'code' => (string) $role->code,
            'name' => (string) $role->name,
            'is_template' => (bool) $role->is_template,
            'purpose' => (string) $role->purpose,
            'expires_at' => $role->getAttribute('expires_at') instanceof \Carbon\CarbonInterface
                ? $role->getAttribute('expires_at')->toIso8601ZuluString()
                : null,
            'capabilities' => RoleCapability::query()
                ->where('role_code', (string) $role->code)
                ->orderBy('capability_code')
                ->get()
                ->map(static fn (RoleCapability $grant): array => [
                    'capability' => (string) $grant->capability_code,
                    'anchor_type' => (string) $grant->anchor_type,
                    'field_profile' => (string) $grant->field_profile,
                ])->all(),
        ];
    }
}
