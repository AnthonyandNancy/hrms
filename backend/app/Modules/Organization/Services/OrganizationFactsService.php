<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\LegalEntityInfo;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\Organization\Contracts\OrgUnitInfo;
use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Organization\Models\OrgUnit;

final class OrganizationFactsService implements OrganizationFacts
{
    public function legalEntity(int $tenantId, int $legalEntityId): ?LegalEntityInfo
    {
        $entity = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($legalEntityId)
            ->first();

        if ($entity === null) {
            return null;
        }

        return new LegalEntityInfo(
            id: (int) $entity->id,
            tenantId: (int) $entity->tenant_id,
            code: (string) $entity->code,
            name: (string) $entity->name,
            countryCode: (string) $entity->country_code,
            timezone: (string) $entity->timezone,
            currencyCode: (string) $entity->currency_code,
            status: (string) $entity->status,
        );
    }

    public function orgUnit(int $tenantId, int $orgUnitId): ?OrgUnitInfo
    {
        $unit = OrgUnit::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($orgUnitId)
            ->first();

        if ($unit === null) {
            return null;
        }

        return new OrgUnitInfo(
            id: (int) $unit->id,
            tenantId: (int) $unit->tenant_id,
            legalEntityId: (int) $unit->legal_entity_id,
            parentId: $unit->parent_id !== null ? (int) $unit->parent_id : null,
            code: (string) $unit->code,
            name: (string) $unit->name,
            type: (string) $unit->type,
            status: (string) $unit->status,
            validFrom: (string) $unit->valid_from,
            validTo: (string) $unit->valid_to,
        );
    }
}
