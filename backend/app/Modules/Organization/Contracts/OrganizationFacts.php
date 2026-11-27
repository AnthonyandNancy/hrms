<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Supplementary read contract for modules that need a single organization or
 * position fact without depending on Organization models.
 */
interface OrganizationFacts
{
    public function orgUnit(int $orgUnitId): ?OrgUnitInfo;

    public function position(int $positionId): ?PositionInfo;

    /**
     * Default calendar timezone for the single company, falling back to config
     * when no company profile exists yet.
     */
    public function defaultTimezone(): string;
}
