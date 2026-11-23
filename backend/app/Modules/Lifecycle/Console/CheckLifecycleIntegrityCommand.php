<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cross-module integrity check. It intentionally uses read-only SQL because it
 * spans People, Lifecycle and Talent tables and is an operations diagnostic,
 * not a business write path.
 */
final class CheckLifecycleIntegrityCommand extends Command
{
    protected $signature = 'lifecycle:integrity-check {--tenant= : Limit to a tenant}';

    protected $description = 'Detect overlapping primary assignments, active assignments on terminated employments, duplicate accepted offers and incomplete checklists.';

    public function handle(): int
    {
        $tenant = $this->option('tenant');
        $tenantFilter = is_numeric($tenant) ? (int) $tenant : null;
        $findings = [];

        $primary = DB::table('assignments as a')
            ->join('assignments as b', function ($join): void {
                $join->on('a.employment_id', '=', 'b.employment_id')
                    ->on('a.tenant_id', '=', 'b.tenant_id')
                    ->whereColumn('a.id', '<', 'b.id')
                    ->where('a.is_primary', '=', true)
                    ->where('b.is_primary', '=', true)
                    ->whereColumn('a.valid_from', '<', 'b.valid_to')
                    ->whereColumn('b.valid_from', '<', 'a.valid_to');
            })
            ->when($tenantFilter !== null, fn ($query) => $query->where('a.tenant_id', $tenantFilter))
            ->select(['a.employment_id', 'a.id as a_id', 'b.id as b_id'])
            ->get();

        foreach ($primary as $row) {
            $findings[] = "OVERLAPPING_PRIMARY employment={$row->employment_id} assignments={$row->a_id},{$row->b_id}";
        }

        $orphans = DB::table('assignments as a')
            ->join('employments as e', function ($join): void {
                $join->on('a.employment_id', '=', 'e.id')->on('a.tenant_id', '=', 'e.tenant_id');
            })
            ->when($tenantFilter !== null, fn ($query) => $query->where('e.tenant_id', $tenantFilter))
            ->where('e.status', 'TERMINATED')
            ->whereColumn('a.valid_to', '>', 'e.valid_to')
            ->select(['a.id as assignment_id', 'e.id as employment_id'])
            ->get();

        foreach ($orphans as $row) {
            $findings[] = "TERMINATED_EMPLOYMENT_ACTIVE_ASSIGNMENT employment={$row->employment_id} assignment={$row->assignment_id}";
        }

        $accepted = DB::table('offers')
            ->when($tenantFilter !== null, fn ($query) => $query->where('tenant_id', $tenantFilter))
            ->where('status', 'ACCEPTED')
            ->groupBy('tenant_id', 'candidate_id')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('tenant_id, candidate_id, COUNT(*) as accepted_count')
            ->get();

        foreach ($accepted as $row) {
            $findings[] = "MULTIPLE_ACCEPTED_OFFERS tenant={$row->tenant_id} candidate={$row->candidate_id} count={$row->accepted_count}";
        }

        $incomplete = DB::table('lifecycle_tasks as t')
            ->join('employments as e', function ($join): void {
                $join->on('t.employment_id', '=', 'e.id')->on('t.tenant_id', '=', 'e.tenant_id');
            })
            ->when($tenantFilter !== null, fn ($query) => $query->where('t.tenant_id', $tenantFilter))
            ->where('t.required', true)
            ->whereNotIn('t.status', ['DONE', 'WAIVED'])
            ->where('e.status', '!=', 'TERMINATED')
            ->count();

        if ($incomplete > 0) {
            $findings[] = "CHECKLIST_INCOMPLETE_ACTIVE_EMPLOYMENT count={$incomplete}";
        }

        if ($findings === []) {
            $this->info('Lifecycle integrity OK.');

            return self::SUCCESS;
        }

        $this->error('Lifecycle integrity findings: '.count($findings));
        foreach ($findings as $finding) {
            $this->line('  - '.$finding);
        }

        return self::FAILURE;
    }
}
