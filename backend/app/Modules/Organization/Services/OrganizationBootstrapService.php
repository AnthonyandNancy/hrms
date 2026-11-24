<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\OrganizationBootstrap;
use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Shared\Time\Clock;

final class OrganizationBootstrapService implements OrganizationBootstrap
{
    public function __construct(private readonly Clock $clock) {}

    public function ensureLegalEntityWithRoot(
        int $tenantId,
        string $code,
        string $name,
        string $countryCode,
        string $timezone,
        string $currencyCode,
        string $validFrom = '2026-01-01',
    ): array {
        $entity = LegalEntity::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => $code],
            [
                'name' => $name,
                'country_code' => strtoupper($countryCode),
                'timezone' => $timezone,
                'currency_code' => strtoupper($currencyCode),
                'status' => 'ACTIVE',
            ],
        );

        $root = OrgUnit::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => $code.'-HQ'],
            [
                'legal_entity_id' => (int) $entity->id,
                'parent_id' => null,
                'name' => $name.' Headquarters',
                'type' => 'COMPANY',
                'status' => 'ACTIVE',
                'valid_from' => $validFrom,
                'valid_to' => '9999-12-31',
                'recorded_at' => $this->clock->now(),
                'source_type' => 'INSTALL',
            ],
        );

        OrgUnitClosure::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'ancestor_id' => (int) $root->id, 'descendant_id' => (int) $root->id, 'valid_from' => $validFrom],
            ['depth' => 0, 'valid_to' => '9999-12-31', 'recorded_at' => $this->clock->now(), 'source_type' => 'INSTALL'],
        );

        return [
            'legal_entity_id' => (int) $entity->id,
            'legal_entity_created' => (bool) $entity->wasRecentlyCreated,
            'root_org_unit_id' => (int) $root->id,
            'root_org_unit_created' => (bool) $root->wasRecentlyCreated,
        ];
    }
}
