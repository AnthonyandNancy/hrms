<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Services;

use App\Modules\Platform\Migration\Dto\FilePlan;
use App\Modules\Platform\Migration\Models\MigrationIssue;
use App\Modules\Platform\Migration\Models\MigrationRun;
use App\Modules\Platform\Migration\Models\StagingRow;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Produces a reviewable inverse plan for a migration run. It never executes
 * SQL: destructive rollback is a human-controlled operation with a separate
 * snapshot, stop-writes step and approval. The plan includes a digest so an
 * operator can prove the reviewed plan is the one that was applied elsewhere.
 */
final class MigrationRollbackPlanner
{
    public function __construct(private readonly MigrationTargetRegistry $targets) {}

    public function plan(MigrationRun $run, bool $execute = false): FilePlan
    {
        if (! $run->status->isSuccessful()) {
            throw new RuntimeException("Run {$run->id} is not in a reconcilable state ({$run->status->value}).");
        }

        $usedShadow = ($run->report_json['target'] ?? null) === 'SHADOW';
        $target = $this->targets->target($run->domain, allowShadow: true, preferShadow: $usedShadow);
        $statements = $target->rollbackStatements($run->tenant_id, (int) $run->id);

        $issued = StagingRow::forDomain($run->domain)
            ->newQuery()
            ->where('run_id', $run->id)
            ->whereNotNull('target_id')
            ->count();
        $issues = MigrationIssue::query()->where('run_id', $run->id)->where('severity', 'ERROR')->count();

        $body = [
            'plan_version' => '1',
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'run_id' => (int) $run->id,
            'tenant_id' => $run->tenant_id,
            'domain' => $run->domain->value,
            'status' => $run->status->value,
            'target_rows' => $issued,
            'unresolved_errors' => $issues,
            'execute_requested' => $execute,
            'executed' => false,
            'irreversible' => $this->irreversibleNote($run),
            'preconditions' => [
                'Take a database snapshot or PITR marker before executing any statement.',
                'Stop writes for the legal entity and set risky feature flags off.',
                'Two-person approval recorded in the incident/cutover log.',
                'Reconcile the inverse against source data before restoring legacy routing.',
            ],
            'statements' => $statements,
            'checksum_inputs' => [
                'run' => (int) $run->id,
                'domain' => $run->domain->value,
                'staged_rows' => $issued,
                'statements' => $statements,
            ],
        ];

        $body['plan_digest'] = hash('sha256', json_encode($body['checksum_inputs'], JSON_THROW_ON_ERROR));

        if ($execute) {
            $body['executed'] = false;
            $body['execution_note'] = 'Plan-only tool: execute through the reviewed runbook under DBA supervision.';
        }

        return new FilePlan($body);
    }

    private function irreversibleNote(MigrationRun $run): string
    {
        return match ($run->domain->value) {
            'leave_openings' => 'Opening GRANTs may have been consumed by approved leave; reverse only unconsumed entries.',
            'payroll_ytd' => 'YTD figures may already be included in a closed payroll run; restore only with payroll sign-off.',
            default => 'Downstream rows may reference migrated ids; delete only after dependency checks.',
        };
    }
}
