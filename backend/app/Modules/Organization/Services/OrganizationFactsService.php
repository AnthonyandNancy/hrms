<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\Organization\Contracts\OrgUnitInfo;
use App\Modules\Organization\Contracts\PositionInfo;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\Position;

final class OrganizationFactsService implements OrganizationFacts
{
    public function __construct(private readonly CompanyProfileReader $profiles) {}

    public function defaultTimezone(): string
    {
        $timezone = $this->profiles->current()->timezone;

        return $timezone !== ''
            ? $timezone
            : (string) config('hrms.default_timezone', 'Asia/Shanghai');
    }

    public function orgUnit(int $orgUnitId): ?OrgUnitInfo
    {
        $unit = OrgUnit::query()->whereKey($orgUnitId)->first();

        if ($unit === null) {
            return null;
        }

        return new OrgUnitInfo(
            id: (int) $unit->id,
            parentId: $unit->parent_id !== null ? (int) $unit->parent_id : null,
            code: (string) $unit->code,
            name: (string) $unit->name,
            type: (string) $unit->type,
            status: (string) $unit->status,
            validFrom: (string) $unit->valid_from,
            validTo: (string) $unit->valid_to,
        );
    }

    public function position(int $positionId): ?PositionInfo
    {
        $position = Position::query()->whereKey($positionId)->first();

        if ($position === null) {
            return null;
        }

        return new PositionInfo(
            id: (int) $position->id,
            orgUnitId: $position->org_unit_id !== null ? (int) $position->org_unit_id : null,
            code: (string) $position->code,
            title: (string) $position->title,
            grade: $position->grade !== null ? (string) $position->grade : null,
        );
    }
}
