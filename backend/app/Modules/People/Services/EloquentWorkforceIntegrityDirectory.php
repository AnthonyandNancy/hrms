<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\WorkforceIntegrityDirectory;
use Illuminate\Support\Facades\DB;

/**
 * People-owned implementation of the integrity diagnostics. The raw SQL lives
 * here because People owns `assignments` and `employments`; consumers only see
 * the findings.
 */
final class EloquentWorkforceIntegrityDirectory implements WorkforceIntegrityDirectory
{
    public function overlappingPrimaryAssignments(): array
    {
        $rows = DB::table('assignments as a')
            ->join('assignments as b', function ($join): void {
                $join->on('a.employment_id', '=', 'b.employment_id')
                    ->whereColumn('a.id', '<', 'b.id')
                    ->where('a.is_primary', '=', true)
                    ->where('b.is_primary', '=', true)
                    ->whereColumn('a.valid_from', '<', 'b.valid_to')
                    ->whereColumn('b.valid_from', '<', 'a.valid_to');
            })
            ->select(['a.employment_id', 'a.id as a_id', 'b.id as b_id'])
            ->orderBy('a.employment_id')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $employmentId = (int) $row->employment_id;
            $grouped[$employmentId] ??= [];
            $grouped[$employmentId][] = (int) $row->a_id;
            $grouped[$employmentId][] = (int) $row->b_id;
        }

        $findings = [];

        foreach ($grouped as $employmentId => $ids) {
            $unique = array_values(array_unique($ids));
            sort($unique);
            $findings[] = ['employment_id' => (int) $employmentId, 'assignment_ids' => $unique];
        }

        return $findings;
    }

    public function assignmentsOnTerminatedEmployments(): array
    {
        $rows = DB::table('assignments as a')
            ->join('employments as e', function ($join): void {
                $join->on('a.employment_id', '=', 'e.id');
            })
            ->where('e.status', 'TERMINATED')
            ->whereColumn('a.valid_to', '>', 'e.valid_to')
            ->select(['a.id as assignment_id', 'e.id as employment_id'])
            ->orderBy('e.id')
            ->get();

        $findings = [];

        foreach ($rows as $row) {
            $findings[] = [
                'employment_id' => (int) $row->employment_id,
                'assignment_id' => (int) $row->assignment_id,
            ];
        }

        return $findings;
    }
}
