<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Payroll\Contracts\PerformanceBonusDirectory;
use App\Modules\Talent\Models\PerformanceBonusMapping;
use App\Modules\Talent\Models\PerformanceResultSnapshot;

/**
 * Payroll input adapter: only APPROVED mappings with a published performance
 * snapshot and the exact payroll period are visible. DRAFT, revoked, future
 * and cross-period mappings are excluded.
 */
final class PerformanceBonusDirectoryService implements PerformanceBonusDirectory
{
    public function frozenForPeriod(int $employmentId, string $period): array
    {
        $mappings = PerformanceBonusMapping::query()
            ->where('employment_id', $employmentId)
            ->where('period', $period)
            ->where('status', 'APPROVED')
            ->orderBy('id')
            ->get();

        if ($mappings->isEmpty()) {
            return [];
        }

        $snapshotIds = $mappings->pluck('performance_snapshot_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $publishedIds = PerformanceResultSnapshot::query()
            ->whereIn('id', $snapshotIds)
            ->whereNotNull('published_at')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $mappings
            ->filter(static fn (PerformanceBonusMapping $mapping): bool => in_array((int) $mapping->performance_snapshot_id, $publishedIds, true))
            ->map(static fn (PerformanceBonusMapping $mapping): array => [
                'item_code' => (string) $mapping->payroll_item_code,
                'label' => 'Performance bonus '.$mapping->payroll_item_code,
                'amount_cents' => (int) $mapping->amount_cents,
                'source_type' => 'performance_bonus_mapping',
                'source_id' => (string) $mapping->id,
                'taxable' => true,
                'sort_order' => 700,
            ])
            ->values()
            ->all();
    }
}
