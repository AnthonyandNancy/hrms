<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\DepartmentStaffingDirectory;
use Illuminate\Support\Facades\DB;

final class DepartmentStaffingService implements DepartmentStaffingDirectory
{
    public function activeHeadcount(int $tenantId, array $orgUnitIds, string $on): array
    {
        if ($orgUnitIds === []) {
            return [];
        }

        return DB::table('assignments')
            ->where('tenant_id', $tenantId)
            ->whereIn('org_unit_id', $orgUnitIds)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->groupBy('org_unit_id')
            ->pluck(DB::raw('count(distinct employment_id)'), 'org_unit_id')
            ->mapWithKeys(static fn (mixed $count, mixed $id): array => [(int) $id => (int) $count])
            ->all();
    }

    public function members(int $tenantId, int $orgUnitId, string $on): array
    {
        return DB::table('assignments')
            ->where('tenant_id', $tenantId)
            ->where('org_unit_id', $orgUnitId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->orderBy('employment_id')
            ->get()
            ->map(static fn (object $row): array => [
                'employment_id' => (int) $row->employment_id,
                'assignment_id' => (int) $row->id,
                'assignment_type' => (string) $row->assignment_type,
                'is_primary' => (bool) $row->is_primary,
                'valid_from' => (string) $row->valid_from,
                'valid_to' => (string) $row->valid_to,
            ])
            ->all();
    }
}
