<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Models\ProjectionRow;
use App\Shared\Authorization\Authorizer;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single query builder for metric totals and drill-down rows. Every read
 * starts with Authorizer::constrain(); cards and drill-down lists therefore
 * share one as-of, one predicate and one authorized scope.
 *
 * Business labels, units and predicates are owned by MetricDefinition. This
 * builder only returns DTO fields; it never emits technical unit codes as
 * user-facing copy.
 */
final class MetricQueryBuilder
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ProjectionBuilder $builder,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{metric: string, label: string, predicate: string, asOf: string, total: int, unit: string, unitLabel: string, status: string, reason: string|null, scope: string, scopeLabel: string, period: string, columns: list<array{key: string, label: string}>, items: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function query(Principal $principal, string $metric, string $asOf, array $filters = []): array
    {
        if (! MetricDefinition::exists($metric)) {
            throw new ValidationException(['metric' => ['Unknown dashboard metric.']], 'The given data was invalid.', ['reason' => 'UNKNOWN_METRIC']);
        }

        $this->builder->ensure($asOf);
        $unavailable = $this->unavailableIfNeeded($principal, $metric, $asOf);

        if ($unavailable !== null) {
            return $this->unavailable($metric, $asOf, $unavailable);
        }

        $rows = $this->metricQuery($principal, $metric, $asOf, $filters)->orderBy('id')->get();
        $items = $rows->map(fn (ProjectionRow $row): array => $this->drilldownRow($row))->all();
        $total = in_array($metric, ['leave_liability', 'payroll_cost_by_entity', 'recruitment_funnel'], true)
            ? (int) $rows->sum(static fn (ProjectionRow $row): int => (int) $row->numeric_value)
            : count($items);

        return [
            'metric' => $metric,
            'label' => MetricDefinition::label($metric),
            'predicate' => MetricDefinition::predicate($metric),
            'asOf' => $asOf,
            'total' => $total,
            'unit' => MetricDefinition::unit($metric),
            'unitLabel' => MetricDefinition::unitLabel($metric),
            'status' => 'AVAILABLE',
            'availability' => 'AVAILABLE',
            'reason' => null,
            'scope' => 'AUTHORIZED',
            'scopeLabel' => '按当前授权范围',
            'period' => 'AS_OF',
            'columns' => MetricDefinition::columns($metric),
            'items' => $items,
            'rows' => $items,
        ];
    }

    /**
     * Bounded preview using the exact same projection, as-of, filters and
     * Authorizer::constrain() predicate as query(). Dashboard panels must not
     * run a second count with a different scope.
     *
     * @param  array<string, mixed>  $filters
     * @return array{status: string, reason: string|null, items: list<array<string, mixed>>}
     */
    public function preview(Principal $principal, string $metric, string $asOf, int $limit, array $filters = []): array
    {
        if (! MetricDefinition::exists($metric)) {
            throw new ValidationException(['metric' => ['Unknown dashboard metric.']], 'The given data was invalid.', ['reason' => 'UNKNOWN_METRIC']);
        }

        $this->builder->ensure($asOf);
        $unavailable = $this->unavailableIfNeeded($principal, $metric, $asOf);

        if ($unavailable !== null) {
            return [
                'status' => $unavailable['status'],
                'reason' => $unavailable['reason'],
                'items' => [],
            ];
        }

        $rows = $this->metricQuery($principal, $metric, $asOf, $filters)
            ->orderBy('id')
            ->limit(max(1, min(20, $limit)))
            ->get();

        return [
            'status' => 'AVAILABLE',
            'availability' => 'AVAILABLE',
            'reason' => null,
            'items' => $rows->map(fn (ProjectionRow $row): array => $this->drilldownRow($row))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ProjectionRow>
     */
    private function metricQuery(Principal $principal, string $metric, string $asOf, array $filters): Builder
    {
        $query = $this->baseQuery($principal, MetricDefinition::source($metric), $asOf);

        if ($metric === 'contract_expiring') {
            $window = (int) ($filters['window'] ?? 30);

            if (! in_array($window, [30, 60, 90], true)) {
                throw new ValidationException(['window' => ['Expiry window must be 30, 60 or 90 days.']], 'The given data was invalid.', ['reason' => 'INVALID_WINDOW']);
            }

            $query->where('metric_code', 'contract_expiring')
                ->where('numeric_value', '<=', $window)
                ->where('numeric_value', '>=', 0);
        } else {
            $query->where('metric_code', MetricDefinition::metricCode($metric));
        }

        if (isset($filters['org_unit_id']) && $filters['org_unit_id'] !== '') {
            $query->where('org_unit_id', (int) $filters['org_unit_id']);
        }

        if (isset($filters['min_age_days']) && $filters['min_age_days'] !== '') {
            $query->where('numeric_value', '>=', (int) $filters['min_age_days']);
        }

        if (isset($filters['leave_type']) && $filters['leave_type'] !== '') {
            $query->where('status', (string) $filters['leave_type']);
        }

        return $query;
    }

    /**
     * Configured-but-empty metrics remain AVAILABLE and return a truthful
     * zero. Missing projections return UNCONFIGURED with a Chinese business
     * reason. Neither case returns a fabricated amount; the route capability
     * remains the only dashboard-level permission gate.
     *
     * @return array{status: string, reason: string}|null
     */
    private function unavailableIfNeeded(Principal $principal, string $metric, string $asOf): ?array
    {
        if ($metric === 'payroll_cost_by_entity' && ! $this->builder->payrollAvailable()) {
            return ['status' => 'UNCONFIGURED', 'reason' => MetricDefinition::reason($metric)];
        }

        if ($metric === 'recruitment_funnel' && ! $this->builder->recruitmentAvailable()) {
            return ['status' => 'UNCONFIGURED', 'reason' => MetricDefinition::reason($metric)];
        }

        if ($metric === 'turnover' && ! $this->builder->turnoverAvailable()) {
            return ['status' => 'UNCONFIGURED', 'reason' => MetricDefinition::reason($metric)];
        }

        return null;
    }

    /**
     * @return Builder<ProjectionRow>
     */
    public function baseQuery(Principal $principal, string $source, string $asOf): Builder
    {
        $query = ProjectionRow::query()
            ->where('source_projection', $source)
            ->where('business_date', $asOf);

        return $this->authorizer->constrain($query, $principal, 'reporting.dashboard.read', $asOf);
    }

    /**
     * @return array<string, mixed>
     */
    public function drilldownRow(ProjectionRow $row): array
    {
        $attributes = (array) $row->attributes_json;

        /** @var array<string, mixed> $attributes */
        return [
            'id' => (string) $row->entity_id,
            'primary' => (string) $row->label,
            'secondary' => (string) ($row->secondary_label ?? ''),
            'status' => (string) ($row->status ?? ''),
            'statusLabel' => MetricDefinition::statusLabel((string) ($row->status ?? '')),
            'detail' => $this->detail($row, $attributes),
            'value' => (int) $row->numeric_value,
            'link' => $row->employment_id !== null ? '/employees?employee='.$row->employment_id : null,
            ...$attributes,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function detail(ProjectionRow $row, array $attributes): string
    {
        if (isset($attributes['age_days'])) {
            return '已等待 '.$attributes['age_days'].' 天';
        }

        if (isset($attributes['days_remaining'], $attributes['end_date'])) {
            return $attributes['end_date'].'（剩余 '.$attributes['days_remaining'].' 天）';
        }

        if (isset($attributes['balance_minutes'])) {
            return '余额 '.$attributes['balance_minutes'].' 分钟';
        }

        return (string) ($row->secondary_label ?? '');
    }

    /**
     * @param  array{status: string, reason: string}  $state
     * @return array<string, mixed>
     */
    private function unavailable(string $metric, string $asOf, array $state): array
    {
        return [
            'metric' => $metric,
            'label' => MetricDefinition::label($metric),
            'predicate' => MetricDefinition::predicate($metric),
            'asOf' => $asOf,
            'total' => 0,
            'unit' => MetricDefinition::unit($metric),
            'unitLabel' => MetricDefinition::unitLabel($metric),
            'status' => $state['status'],
            'availability' => $state['status'],
            'reason' => $state['reason'],
            'scope' => 'AUTHORIZED',
            'scopeLabel' => '按当前授权范围',
            'period' => 'AS_OF',
            'columns' => MetricDefinition::columns($metric),
            'items' => [],
            'rows' => [],
        ];
    }
}
