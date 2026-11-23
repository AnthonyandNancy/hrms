<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Console;

use App\Modules\People\Contracts\WorkforceIntegrityDirectory;
use App\Modules\Talent\Contracts\OfferIntegrityReader;
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

    public function handle(OfferIntegrityReader $offers, WorkforceIntegrityDirectory $workforce): int
    {
        $tenant = $this->option('tenant');
        $tenantFilter = is_numeric($tenant) ? (int) $tenant : null;
        $findings = [];

        // People owns assignments/employments; this command asks through the
        // People contract instead of running cross-module SQL. A global run
        // fans out over the tenants that actually have employments.
        $tenantIds = $tenantFilter !== null ? [$tenantFilter] : $workforce->tenantIds();

        foreach ($tenantIds as $tenantId) {
            foreach ($workforce->overlappingPrimaryAssignments($tenantId) as $row) {
                $findings[] = "OVERLAPPING_PRIMARY employment={$row['employment_id']} assignments=".implode(',', $row['assignment_ids']);
            }

            foreach ($workforce->assignmentsOnTerminatedEmployments($tenantId) as $row) {
                $findings[] = "TERMINATED_EMPLOYMENT_ACTIVE_ASSIGNMENT employment={$row['employment_id']} assignment={$row['assignment_id']}";
            }
        }

        foreach ($offers->candidatesWithMultipleAcceptedOffers($tenantFilter) as $row) {
            $findings[] = "MULTIPLE_ACCEPTED_OFFERS tenant={$row['tenant_id']} candidate={$row['candidate_id']} count={$row['accepted_count']}";
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
