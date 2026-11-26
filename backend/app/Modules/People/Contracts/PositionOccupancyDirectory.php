<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Organization-owned position reads People-owned assignment facts only through
 * this contract. Returns active incumbents by position for one legal entity.
 */
interface PositionOccupancyDirectory
{
    /**
     * @param  list<int>  $positionIds
     * @return array<int, int> position id => active incumbent count
     */
    public function occupancy(array $positionIds, string $on): array;
}
