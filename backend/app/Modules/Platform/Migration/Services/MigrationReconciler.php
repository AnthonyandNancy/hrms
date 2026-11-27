<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Services;

use App\Modules\Platform\Migration\Dto\ReconciliationReport;
use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;
use App\Modules\Platform\Migration\Enums\MigrationRunStatus;
use App\Modules\Platform\Migration\Models\MigrationIssue;
use App\Modules\Platform\Migration\Models\MigrationRun;
use App\Modules\Platform\Migration\Models\StagingRow;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Compares staged source rows (typed normalized JSON) to target metrics. Any
 * unexplained count or sum difference produces a persisted issue and a failing
 * report; the caller turns that into a non-zero process exit.
 */
final class MigrationReconciler
{
    public function __construct(private readonly MigrationTargetRegistry $targets) {}

    public function reconcile(MigrationRun $run): ReconciliationReport
    {
        if (! $run->status->isSuccessful()) {
            throw new RuntimeException("Run {$run->id} did not complete validation or apply successfully.");
        }

        $domain = $run->domain;
        $rows = StagingRow::forDomain($domain)
            ->newQuery()
            ->where('run_id', $run->id)
            ->where('validation_status', '!=', 'FAILED')
            ->get();

        $sourceCount = 0;
        $sourceSums = array_fill_keys($domain->reconciliationSums(), 0);

        foreach ($rows as $row) {
            $sourceCount++;
            $data = $row->normalized_json ?? [];

            foreach ($domain->reconciliationSums() as $field) {
                $sourceSums[$field] += (int) ($data[$field] ?? 0);
            }
        }

        $usedShadow = ($run->report_json['target'] ?? null) === 'SHADOW';
        $target = $this->targets->target($domain, allowShadow: true, preferShadow: $usedShadow);
        $metrics = $target->metrics();
        $targetSums = [];

        foreach ($domain->reconciliationSums() as $field) {
            $targetSums[$field] = $metrics->sums[$field] ?? 0;
        }

        $differences = [];

        if ($sourceCount !== $metrics->count) {
            $differences['count'] = $metrics->count - $sourceCount;
        }

        foreach ($sourceSums as $field => $value) {
            $targetValue = $targetSums[$field] ?? 0;
            if ($value !== $targetValue) {
                $differences[$field] = $targetValue - $value;
            }
        }

        $passed = $differences === [];
        $now = CarbonImmutable::now('UTC')->toIso8601ZuluString();

        if (! $passed) {
            foreach ($differences as $metric => $difference) {
                MigrationIssue::query()->create([
                    'run_id' => $run->id,
                    'severity' => IssueSeverity::ERROR->value,
                    'code' => $metric === 'count'
                        ? IssueCode::RECONCILIATION_COUNT_MISMATCH->value
                        : IssueCode::RECONCILIATION_SUM_MISMATCH->value,
                    'message' => "Reconciliation {$metric} differs by {$difference} (target minus source).",
                    'created_at' => CarbonImmutable::now('UTC'),
                ]);
            }
        }

        $report = new ReconciliationReport(
            runId: (int) $run->id,
            domain: $domain->value,
            sourceCount: $sourceCount,
            targetCount: $metrics->count,
            sourceSums: $sourceSums,
            targetSums: $targetSums,
            differences: $differences,
            passed: $passed,
            generatedAt: $now,
        );

        $run->forceFill([
            'report_json' => [
                ...($run->report_json ?? []),
                'reconciliation' => $report->toArray(),
            ],
        ])->save();

        return $report;
    }

    /**
     * @return list<ReconciliationReport>
     */
    public function reconcileAll(): array
    {
        $reports = [];

        foreach (MigrationRun::query()
            ->where('status', MigrationRunStatus::APPLIED->value)
            ->orderBy('id')
            ->get() as $run) {
            $reports[] = $this->reconcile($run);
        }

        return $reports;
    }
}
