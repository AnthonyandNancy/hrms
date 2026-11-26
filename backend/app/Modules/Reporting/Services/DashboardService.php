<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Engagement\Contracts\NoticeWorkspaceDirectory;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Models\DashboardSnapshot;
use App\Shared\Authorization\Authorizer;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard snapshots are rebuildable projections. The source hash is derived
 * from the projection rows used to compute the metric so a reader can tell
 * whether the snapshot is stale.
 *
 * The preview panels deliberately reuse the metric query result instead of
 * running a second count with a different predicate. Each list is capped before
 * it leaves the service; the full list remains available through the metric
 * drill-down endpoint under the same authorization constraint.
 */
final class DashboardService
{
    private const PANEL_LIMIT = 5;

    private const CONTRACT_WINDOW_DAYS = 30;

    public function __construct(
        private readonly MetricQueryBuilder $metrics,
        private readonly Authorizer $authorizer,
        private readonly NoticeWorkspaceDirectory $notices,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Principal $principal, string $asOf): array
    {
        $codes = ['headcount', 'probation', 'contract_expiring', 'pending_approvals', 'leave_liability', 'payroll_cost_by_entity', 'recruitment_funnel'];
        $metrics = [];
        $contractAlerts = [];

        foreach ($codes as $code) {
            $filters = $code === 'contract_expiring' ? ['window' => self::CONTRACT_WINDOW_DAYS] : [];
            $result = $this->metrics->query($principal, $code, $asOf, $filters);
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

            if ($code === 'contract_expiring') {
                $contractAlerts = $this->contractAlerts($result);
            }
        }

        $pendingApprovals = $this->metrics->preview(
            $principal,
            'pending_approvals',
            $asOf,
            self::PANEL_LIMIT,
        );

        return [
            'asOf' => $asOf,
            'freshness' => $this->clock->now()->toIso8601ZuluString(),
            'greeting' => 'Welcome back',
            'metrics' => $metrics,
            'pendingApprovals' => $this->pendingApprovals($pendingApprovals),
            'recentNotices' => $this->recentNotices($principal, $asOf),
            'contractAlerts' => $contractAlerts,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{id: int, caseNo: string, title: string, status: string, ageDays: int, dueAt: string|null}>
     */
    private function pendingApprovals(array $result): array
    {
        if (($result['status'] ?? 'UNAVAILABLE') !== 'AVAILABLE') {
            return [];
        }

        // ReportingApprovalDirectory is tenant-wide and exposes no assignee
        // field, so this preview is not "assigned to me" at the source. Scope
        // is enforced on the Reporting projection by Authorizer::constrain():
        // SELF/MANAGED/COMPANY narrow employment_id and ORG_* narrow
        // org_unit_id. Workflow rows without an employment subject therefore
        // have no anchor and are excluded unless a caller's grant would admit
        // them through the same projection predicate as the metric.

        $rows = [];

        foreach (array_slice((array) ($result['items'] ?? []), 0, self::PANEL_LIMIT) as $item) {
            /** @var array<string, mixed> $item */
            $rows[] = [
                'id' => (int) ($item['id'] ?? 0),
                'caseNo' => (string) ($item['case_no'] ?? $item['secondary'] ?? ''),
                'title' => (string) ($item['title'] ?? $item['primary'] ?? ''),
                'status' => (string) ($item['status'] ?? ''),
                'ageDays' => (int) ($item['value'] ?? $item['age_days'] ?? 0),
                'dueAt' => isset($item['due_at']) && is_string($item['due_at']) ? $item['due_at'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{employmentId: int, employeeName: string, employeeNo: string, orgName: string, endDate: string, daysRemaining: int}>
     */
    private function contractAlerts(array $result): array
    {
        if (($result['status'] ?? 'UNAVAILABLE') !== 'AVAILABLE') {
            return [];
        }

        $rows = [];

        foreach (array_slice((array) ($result['items'] ?? []), 0, self::PANEL_LIMIT) as $item) {
            /** @var array<string, mixed> $item */
            $daysRemaining = (int) ($item['value'] ?? $item['days_remaining'] ?? 0);

            if ($daysRemaining < 0 || $daysRemaining > self::CONTRACT_WINDOW_DAYS) {
                continue;
            }

            $rows[] = [
                'employmentId' => (int) ($item['id'] ?? 0),
                'employeeName' => (string) ($item['primary'] ?? $item['employee'] ?? ''),
                'employeeNo' => (string) ($item['employee_no'] ?? ''),
                'orgName' => (string) ($item['org_path'] ?? $item['secondary'] ?? ''),
                'endDate' => (string) ($item['end_date'] ?? ''),
                'daysRemaining' => $daysRemaining,
            ];
        }

        return $rows;
    }

    /**
     * The workspace directory is the existing bounded, self-scoped notice read
     * seam. Route capability is checked here because the dashboard route only
     * requires reporting.dashboard.read. The directory is intentionally
     * conservative today: it exposes tenant-wide notices plus the caller's
     * acknowledgements. ROLE/ORG audience expansion remains Engagement-owned
     * and must be implemented there without widening Reporting's scope.
     *
     * @return list<array{id: int, title: string, publishedAt: string|null, requiresAck: bool, acknowledged: bool, authorName: string|null}>
     */
    private function recentNotices(Principal $principal, string $asOf): array
    {
        if (! $this->authorizer->permits($principal, 'engagement.notice.read', $asOf)) {
            return [];
        }

        return array_slice(
            $this->notices->forUser($principal->tenantId(), $principal->userId(), self::PANEL_LIMIT),
            0,
            self::PANEL_LIMIT,
        );
    }

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
