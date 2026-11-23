<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Models\ProjectionRow;
use App\Shared\Authorization\Authorizer;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single query builder for metric totals and drill-down rows. Callers must
 * never compute a count with a separate predicate; every read here starts with
 * the same tenant predicate and Authorizer::constrain().
 */
final class MetricQueryBuilder
{
    /**
     * @var array<string, array{label: string, source: string, metric: string, unit: string, predicate: string}>
     */
    public const METRICS = [
        'headcount' => [
            'label' => 'Headcount',
            'source' => 'workforce',
            'metric' => 'headcount',
            'unit' => 'COUNT',
            'predicate' => 'employment active on as_of ∧ authorized org scope',
        ],
        'probation' => [
            'label' => 'Probation',
            'source' => 'workforce',
            'metric' => 'probation',
            'unit' => 'COUNT',
            'predicate' => 'employment.status = PROBATION ∧ authorized org scope',
        ],
        'turnover' => [
            'label' => 'Voluntary turnover',
            'source' => 'turnover',
            'metric' => 'turnover',
            'unit' => 'COUNT',
            'predicate' => 'voluntary termination in as_of year ∧ authorized scope (Lifecycle projection unavailable)',
        ],
        'pending_approvals' => [
            'label' => 'Pending approvals',
            'source' => 'pending_approvals',
            'metric' => 'pending_approvals',
            'unit' => 'COUNT',
            'predicate' => 'open workflow task on as_of ∧ authorized org scope',
        ],
        'contract_expiring' => [
            'label' => 'Contract expiring',
            'source' => 'workforce',
            'metric' => 'contract_expiring',
            'unit' => 'COUNT',
            'predicate' => 'contract ends within selected 30/60/90 day window ∧ authorized org scope',
        ],
        'leave_liability' => [
            'label' => 'Leave liability',
            'source' => 'leave_liability',
            'metric' => 'leave_liability',
            'unit' => 'MINUTES',
            'predicate' => 'remaining leave ledger minutes on as_of ∧ authorized org scope',
        ],
        'payroll_cost_by_entity' => [
            'label' => 'Payroll cost by entity',
            'source' => 'payroll_cost',
            'metric' => 'payroll_cost_by_entity',
            'unit' => 'MINOR_UNITS',
            'predicate' => 'published payroll cost by legal entity on as_of (unavailable on this branch)',
        ],
        'recruitment_funnel' => [
            'label' => 'Recruitment funnel',
            'source' => 'recruitment_funnel',
            'metric' => 'recruitment_funnel',
            'unit' => 'COUNT',
            'predicate' => 'candidates by recruitment stage on as_of (unavailable on this branch)',
        ],
    ];

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ProjectionBuilder $builder,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{metric: string, label: string, predicate: string, asOf: string, total: int, items: list<array<string, mixed>>, unit: string, status: string, reason: string|null}
     */
    public function query(Principal $principal, string $metric, string $asOf, array $filters = []): array
    {
        $definition = self::METRICS[$metric] ?? null;

        if ($definition === null) {
            throw new ValidationException(['metric' => ['Unknown dashboard metric.']], 'The given data was invalid.', ['reason' => 'UNKNOWN_METRIC']);
        }

        $this->builder->ensure($principal->tenantId(), $asOf);
        $unavailable = $this->unavailableIfNeeded($principal, $metric, $definition, $asOf);

        if ($unavailable !== null) {
            return $this->unavailable($metric, $definition, $asOf, $unavailable);
        }

        $rows = $this->metricQuery($principal, $metric, $definition, $asOf, $filters)->orderBy('id')->get();
        $items = $rows->map(fn (ProjectionRow $row): array => $this->drilldownRow($row))->all();
        $total = $metric === 'leave_liability'
            ? (int) $rows->sum(static fn (ProjectionRow $row): int => (int) $row->numeric_value)
            : count($items);

        return [
            'metric' => $metric,
            'label' => $definition['label'],
            'predicate' => $definition['predicate'],
            'asOf' => $asOf,
            'total' => $total,
            'unit' => $definition['unit'],
            'status' => 'AVAILABLE',
            'reason' => null,
            'columns' => $this->columns($metric),
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
        $definition = self::METRICS[$metric] ?? null;

        if ($definition === null) {
            throw new ValidationException(['metric' => ['Unknown dashboard metric.']], 'The given data was invalid.', ['reason' => 'UNKNOWN_METRIC']);
        }

        $this->builder->ensure($principal->tenantId(), $asOf);
        $unavailable = $this->unavailableIfNeeded($principal, $metric, $definition, $asOf);

        if ($unavailable !== null) {
            return [
                'status' => 'UNAVAILABLE',
                'reason' => $unavailable,
                'items' => [],
            ];
        }

        $rows = $this->metricQuery($principal, $metric, $definition, $asOf, $filters)
            ->orderBy('id')
            ->limit(max(1, min(20, $limit)))
            ->get();

        return [
            'status' => 'AVAILABLE',
            'reason' => null,
            'items' => $rows->map(fn (ProjectionRow $row): array => $this->drilldownRow($row))->all(),
        ];
    }

    /**
     * @param  array{label: string, source: string, metric: string, unit: string, predicate: string}  $definition
     * @param  array<string, mixed>  $filters
     * @return Builder<ProjectionRow>
     */
    private function metricQuery(Principal $principal, string $metric, array $definition, string $asOf, array $filters): Builder
    {
        $query = $this->baseQuery($principal, $definition['source'], $asOf);

        if ($metric === 'contract_expiring') {
            $window = (int) ($filters['window'] ?? 30);

            if (! in_array($window, [30, 60, 90], true)) {
                throw new ValidationException(['window' => ['Expiry window must be 30, 60 or 90 days.']], 'The given data was invalid.', ['reason' => 'INVALID_WINDOW']);
            }

            $query->where('metric_code', 'contract_expiring')
                ->where('numeric_value', '<=', $window)
                ->where('numeric_value', '>=', 0);
        } else {
            $query->where('metric_code', $definition['metric']);
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
     * @param  array{label: string, source: string, metric: string, unit: string, predicate: string}  $definition
     */
    private function unavailableIfNeeded(Principal $principal, string $metric, array $definition, string $asOf): ?string
    {
        if ($metric === 'payroll_cost_by_entity' && ! $this->builder->payrollAvailable($principal->tenantId())) {
            return $this->builder->payrollReason($principal->tenantId())
                ?? 'This metric is unavailable on the current deployment.';
        }

        if ($metric === 'recruitment_funnel' && ! $this->builder->recruitmentAvailable($principal->tenantId())) {
            return $this->builder->recruitmentReason($principal->tenantId())
                ?? 'This metric is unavailable on the current deployment.';
        }

        if ($metric === 'turnover' && ! $this->builder->turnoverAvailable($principal->tenantId())) {
            return $this->builder->turnoverReason($principal->tenantId())
                ?? 'This metric is unavailable on the current deployment.';
        }

        return null;
    }

    /**
     * @return Builder<ProjectionRow>
     */
    public function baseQuery(Principal $principal, string $source, string $asOf): Builder
    {
        $query = ProjectionRow::query()
            ->where('tenant_id', $principal->tenantId())
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
            'detail' => $this->detail($row, $attributes),
            'value' => (int) $row->numeric_value,
            'link' => $row->employment_id !== null ? '/employees?employee='.$row->employment_id : null,
            ...$attributes,
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function columns(string $metric): array
    {
        return match ($metric) {
            'pending_approvals' => [
                ['key' => 'primary', 'label' => 'Case'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'value', 'label' => 'Age (days)'],
            ],
            'leave_liability' => [
                ['key' => 'primary', 'label' => 'Leave type'],
                ['key' => 'secondary', 'label' => 'Employee'],
                ['key' => 'value', 'label' => 'Minutes'],
            ],
            'contract_expiring' => [
                ['key' => 'primary', 'label' => 'Employee'],
                ['key' => 'secondary', 'label' => 'Organization'],
                ['key' => 'value', 'label' => 'Days remaining'],
            ],
            default => [
                ['key' => 'primary', 'label' => 'Name'],
                ['key' => 'secondary', 'label' => 'Identifier'],
                ['key' => 'status', 'label' => 'Status'],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function detail(ProjectionRow $row, array $attributes): string
    {
        if (isset($attributes['age_days'])) {
            return $attributes['age_days'].' days';
        }

        if (isset($attributes['days_remaining'])) {
            return $attributes['end_date'].' ('.$attributes['days_remaining'].' days)';
        }

        if (isset($attributes['balance_minutes'])) {
            return $attributes['balance_minutes'].' minutes';
        }

        return (string) ($row->secondary_label ?? '');
    }

    /**
     * @param  array{label: string, source: string, metric: string, unit: string, predicate: string}  $definition
     * @return array<string, mixed>
     */
    private function unavailable(string $metric, array $definition, string $asOf, ?string $reason): array
    {
        return [
            'metric' => $metric,
            'label' => $definition['label'],
            'predicate' => $definition['predicate'],
            'asOf' => $asOf,
            'total' => 0,
            'unit' => $definition['unit'],
            'status' => 'UNAVAILABLE',
            'reason' => $reason ?? 'This metric is unavailable on the current deployment.',
            'columns' => $this->columns($metric),
            'items' => [],
            'rows' => [],
        ];
    }
}
