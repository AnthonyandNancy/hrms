<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\BindingGrant;
use App\Shared\Authorization\Contracts\AuthorityBindingRepository;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Support\Facades\Cache;

/**
 * SQL-backed, tenant-safe binding repository with a short per-principal cache.
 * Revocation is expressed by auth_version, so an auth_version mismatch can
 * never be served from a stale cache entry.
 */
final class EloquentAuthorityBindingRepository implements AuthorityBindingRepository
{
    public function effectiveBindings(
        int $tenantId,
        int $principalId,
        string $capabilityCode,
        int $authVersion,
        string $businessDate,
    ): array {
        $cacheKey = sprintf('iam:bindings:%d:%d:%d:%d:%s', $tenantId, $principalId, $authVersion, $capabilityCode === '' ? 0 : crc32($capabilityCode), $businessDate);
        $ttl = (int) config('iam.authorization.binding_cache_seconds', 60);

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember($cacheKey, $ttl, function () use ($tenantId, $principalId, $capabilityCode, $authVersion, $businessDate): array {
            $start = $businessDate.' 00:00:00';

            return AuthorityBinding::query()
                ->where('tenant_id', $tenantId)
                ->where('principal_id', $principalId)
                ->where('capability_code', $capabilityCode)
                ->where('auth_version', $authVersion)
                ->where('valid_from', '<=', $start)
                ->where('valid_to', '>', $start)
                ->orderBy('id')
                ->get()
                ->map(fn (AuthorityBinding $binding): array => [
                    'id' => (int) $binding->id,
                    'tenant_id' => (int) $binding->tenant_id,
                    'principal_id' => (int) $binding->principal_id,
                    'capability_code' => (string) $binding->capability_code,
                    'anchor_type' => (string) $binding->anchor_type,
                    'anchor_id' => $binding->anchor_id === null ? null : (int) $binding->anchor_id,
                    'field_profile' => (string) $binding->field_profile,
                    'valid_from' => $binding->valid_from->utc()->toDateTimeString(),
                    'valid_to' => $binding->valid_to->utc()->toDateTimeString(),
                    'auth_version' => (int) $binding->auth_version,
                    'purpose_code' => (string) $binding->purpose_code,
                ])
                ->all();
        });

        return array_map(static fn (array $row): BindingGrant => new BindingGrant(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            principalId: (int) $row['principal_id'],
            capabilityCode: (string) $row['capability_code'],
            anchorType: AnchorType::from((string) $row['anchor_type']),
            anchorId: $row['anchor_id'] === null ? null : (int) $row['anchor_id'],
            fieldProfile: FieldProfile::from((string) $row['field_profile']),
            validFrom: (string) $row['valid_from'],
            validTo: (string) $row['valid_to'],
            authVersion: (int) $row['auth_version'],
            purposeCode: (string) $row['purpose_code'],
        ), $rows);
    }
}
