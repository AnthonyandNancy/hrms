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
 * Dashboard snapshots are rebuildable Reporting projections. Business labels,
 * units, predicates and Chinese availability reasons come from
 * MetricDefinition; this service never emits English debug copy.
 *
 * Panel previews reuse the metric query so cards and drill-downs cannot
 * diverge. Department and workforce section aggregates are also computed here
 * from authorized rows; the frontend only renders the returned semantics.
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
        $metrics = [];
        $metricResults = [];
        $contractAlerts = [];

        foreach (MetricDefinition::dashboardCodes() as $code) {
            $filters = $code === 'contract_expiring' ? ['window' => self::CONTRACT_WINDOW_DAYS] : [];
            $result = $this->metrics->query($principal, $code, $asOf, $filters);
            $metricResults[$code] = $result;
            $snapshot = $this->snapshot($principal, $result);
            $computedAt = $snapshot->computed_at instanceof \Carbon\CarbonInterface
                ? $snapshot->computed_at->toIso8601ZuluString()
                : (string) $snapshot->computed_at;

            $metrics[] = [
                'code' => $code,
                'label' => $result['label'],
                'value' => $result['total'],
                'unit' => $result['unit'],
                'unitLabel' => $result['unitLabel'],
                'status' => $result['status'],
                'availability' => $result['status'],
                'reason' => $result['reason'],
                'scope' => $result['scope'],
                'scopeLabel' => $result['scopeLabel'],
                'period' => $result['period'],
                'predicate' => $result['predicate'],
                'asOf' => $result['asOf'],
                'drilldown' => MetricDefinition::drilldown($code, $result['asOf']),
                'trend' => null,
                'sourceHash' => $snapshot->source_hash,
                'computedAt' => $computedAt,
                'preview' => $this->previewRows($result, $code),
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
            'freshnessState' => 'LIVE',
            'metrics' => $metrics,
            'sections' => [
                'departments' => $this->departmentBreakdown($metricResults['headcount']),
                'workforce' => $this->workforceBreakdown($metricResults['headcount']),
                'recruitment' => $this->recruitmentBreakdown($metricResults['recruitment_funnel']),
            ],
            'pendingApprovals' => $this->pendingApprovals($pendingApprovals),
            'recentNotices' => $this->recentNotices($principal, $asOf),
            'contractAlerts' => $contractAlerts,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function previewRows(array $result, string $code): array
    {
        if (($result['status'] ?? 'UNCONFIGURED') !== 'AVAILABLE') {
            return [];
        }

        if (! in_array($code, ['headcount', 'probation', 'recruitment_funnel'], true)) {
            return [];
        }

        return array_slice((array) ($result['items'] ?? []), 0, 20);
    }

    /**
     * Department headcount is aggregated from authorized headcount rows, not
     * from an organization directory or a second SQL count.
     *
     * @param  array<string, mixed>  $result
     * @return list<array{label: string, count: int}>
     */
    private function departmentBreakdown(array $result): array
    {
        if (($result['status'] ?? 'UNCONFIGURED') !== 'AVAILABLE') {
            return [];
        }

        $groups = [];

        foreach ((array) ($result['items'] ?? []) as $item) {
            /** @var array<string, mixed> $item */
            $orgPath = trim((string) ($item['org_path'] ?? ''));

            if ($orgPath === '') {
                $secondary = (string) ($item['secondary'] ?? '');
                $orgPath = trim(explode(' · ', $secondary, 2)[0]);
            }

            $label = $orgPath !== '' ? $orgPath : '未返回组织';
            $groups[$label] = ($groups[$label] ?? 0) + 1;
        }

        arsort($groups);

        return array_map(
            static fn (string $label, int $count): array => ['label' => $label, 'count' => $count],
            array_keys($groups),
            array_values($groups),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{key: string, label: string, count: int}>
     */
    private function workforceBreakdown(array $result): array
    {
        if (($result['status'] ?? 'UNCONFIGURED') !== 'AVAILABLE') {
            return [];
        }

        $groups = [];

        foreach ((array) ($result['items'] ?? []) as $item) {
            /** @var array<string, mixed> $item */
            $status = (string) ($item['status'] ?? '');
            $label = MetricDefinition::statusLabel($status);
            $groups[$status] = ($groups[$status] ?? 0) + 1;
        }

        $rows = [];

        foreach ($groups as $status => $count) {
            $rows[] = [
                'key' => $status,
                'label' => MetricDefinition::statusLabel((string) $status),
                'count' => $count,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{stage: string, label: string, count: int}>
     */
    private function recruitmentBreakdown(array $result): array
    {
        if (($result['status'] ?? 'UNCONFIGURED') !== 'AVAILABLE') {
            return [];
        }

        $rows = [];

        foreach ((array) ($result['items'] ?? []) as $item) {
            /** @var array<string, mixed> $item */
            $stage = (string) ($item['stage'] ?? $item['status'] ?? '');
            $rows[] = [
                'stage' => $stage,
                'label' => MetricDefinition::stageLabel($stage),
                'count' => (int) ($item['value'] ?? $item['count'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{id: int, caseNo: string, title: string, status: string, statusLabel: string, ageDays: int, dueAt: string|null}>
     */
    private function pendingApprovals(array $result): array
    {
        if (($result['status'] ?? 'UNCONFIGURED') !== 'AVAILABLE') {
            return [];
        }

        $rows = [];

        foreach (array_slice((array) ($result['items'] ?? []), 0, self::PANEL_LIMIT) as $item) {
            /** @var array<string, mixed> $item */
            $status = (string) ($item['status'] ?? '');
            $rows[] = [
                'id' => (int) ($item['id'] ?? 0),
                'caseNo' => (string) ($item['case_no'] ?? $item['secondary'] ?? ''),
                'title' => (string) ($item['title'] ?? $item['primary'] ?? ''),
                'status' => $status,
                'statusLabel' => MetricDefinition::statusLabel($status),
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
        if (($result['status'] ?? 'UNCONFIGURED') !== 'AVAILABLE') {
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
     * conservative today: it exposes company-wide notices plus the caller's
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
            $this->notices->forUser($principal->userId(), self::PANEL_LIMIT),
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

        return DB::transaction(function () use ($result, $scopeHash, $sourceHash): DashboardSnapshot {
            $snapshot = DashboardSnapshot::query()->firstOrNew([
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
