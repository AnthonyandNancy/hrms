<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\OrganizationBootstrap;
use App\Modules\Organization\Models\CompanyProfile;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Shared\Time\Clock;

final class OrganizationBootstrapService implements OrganizationBootstrap
{
    public function __construct(private readonly Clock $clock) {}

    public function ensureCompanyWithRoot(
        string $code,
        string $name,
        string $countryCode,
        string $timezone,
        string $currencyCode,
        string $validFrom = '2026-01-01',
    ): array {
        $profile = CompanyProfile::query()->firstOrCreate(
            ['id' => 1],
            [
                'name' => $name,
                'country_code' => strtoupper($countryCode),
                'timezone' => $timezone,
                'currency_code' => strtoupper($currencyCode),
            ],
        );

        if (! $profile->wasRecentlyCreated) {
            $profile->fill([
                'name' => $name,
                'country_code' => strtoupper($countryCode),
                'timezone' => $timezone,
                'currency_code' => strtoupper($currencyCode),
            ])->save();
        }

        $root = OrgUnit::query()->firstOrCreate(
            ['code' => $code.'-HQ'],
            [
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
            ['ancestor_id' => (int) $root->id, 'descendant_id' => (int) $root->id, 'valid_from' => $validFrom],
            ['depth' => 0, 'valid_to' => '9999-12-31', 'recorded_at' => $this->clock->now(), 'source_type' => 'INSTALL'],
        );

        return [
            'company_profile_id' => (int) $profile->id,
            'company_profile_created' => (bool) $profile->wasRecentlyCreated,
            'root_org_unit_id' => (int) $root->id,
            'root_org_unit_created' => (bool) $root->wasRecentlyCreated,
        ];
    }
}
