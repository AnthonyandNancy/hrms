<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\PositionOccupancyDirectory;
use Illuminate\Support\Facades\DB;

final class PositionOccupancyService implements PositionOccupancyDirectory
{
    public function occupancy(int $tenantId, array $positionIds, string $on): array
    {
        if ($positionIds === []) {
            return [];
        }

        return DB::table('assignments')
            ->where('tenant_id', $tenantId)
            ->whereIn('position_id', $positionIds)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->groupBy('position_id')
            ->pluck(DB::raw('count(distinct employment_id)'), 'position_id')
            ->mapWithKeys(static fn (mixed $count, mixed $id): array => [(int) $id => (int) $count])
            ->all();
    }
}
