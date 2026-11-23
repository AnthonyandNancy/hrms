<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Models\DashboardSnapshot;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard snapshots are rebuildable projections. The source hash is derived
 * from the projection rows used to compute the metric so a reader can tell
 * whether the snapshot is stale.
 */
final class DashboardService
{
    public function __construct(
        private readonly MetricQueryBuilder $metrics,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Principal $principal, string $asOf): array
    {
        $codes = ['headcount', 'probation', 'contract_expiring', 'pending_approvals', 'leave_liability', 'payroll_cost_by_entity', 'recruitment_funnel'];
        $metrics = [];

        foreach ($codes as $code) {
            $result = $this->metrics->query($principal, $code, $asOf);
            $snapshot = $this->snapshot($principal, $result);
            $metrics[] = [
                'code' => $code,
                'label' => $result['label'],
                'value' => $result['total'],
                'unit' => $result['unit'],
                'status' => $result['status'],
                'reason' => $result['reason'],
                'trend' => null,
                'trendDirection' => 'FLAT',
                'drilldownLabel' => 'View details',
                'sourceHash' => $snapshot->source_hash,
                'computedAt' => $snapshot->computed_at instanceof \Carbon\CarbonInterface
                    ? $snapshot->computed_at->toIso8601ZuluString()
                    : (string) $snapshot->computed_at,
            ];
        }

        return [
            'asOf' => $asOf,
            'freshness' => $this->clock->now()->toIso8601ZuluString(),
            'greeting' => 'Welcome back',
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    /**
     * @param  array<string, mixed>  $result
     */
    private function snapshot(Principal $principal, array $result): DashboardSnapshot
    {
        $scopeHash = hash('sha256', json_encode([
            'tenant' => $principal->tenantId(),
            'user' => $principal->userId(),
            'employment' => $principal->employmentId(),
            'metric' => $result['metric'],
        ], JSON_THROW_ON_ERROR));
        $sourceHash = hash('sha256', json_encode([
            'metric' => $result['metric'],
            'asOf' => $result['asOf'],
            'total' => $result['total'],
            'items' => array_map(static fn (array $item): string => (string) $item['id'], $result['items']),
            'status' => $result['status'],
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($principal, $result, $scopeHash, $sourceHash): DashboardSnapshot {
            $snapshot = DashboardSnapshot::query()->firstOrNew([
                'tenant_id' => $principal->tenantId(),
                'metric_code' => $result['metric'],
                'as_of' => $result['asOf'],
                'scope_hash' => $scopeHash,
            ]);

            if (! $snapshot->exists || $snapshot->source_hash !== $sourceHash) {
                $snapshot->fill([
                    'value' => $result['total'],
                    'unit' => $result['unit'],
                    'status' => $result['status'],
                    'reason' => $result['reason'],
                    'source_hash' => $sourceHash,
                    'payload_json' => ['item_ids' => array_map(static fn (array $item): string => (string) $item['id'], $result['items'])],
                    'computed_at' => $this->clock->now(),
                ])->save();
            }

            return $snapshot;
        });
    }
}
