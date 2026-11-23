<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Models\ReportDefinition;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Support\Facades\DB;

/**
 * Code-owned report catalog. Fields carry the FieldProfile that governs API
 * output, filtering and export. Only the column labels and profiles live here;
 * no report-specific SQL is accepted from callers.
 */
final class ReportDefinitionCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => 'WORKFORCE_HEADCOUNT',
                'name' => 'Workforce headcount',
                'description' => 'Active employments by organization as of the business date.',
                'source_projection' => 'workforce',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/headcount',
                'filters_json' => [
                    ['key' => 'org_unit_id', 'label' => 'Organization', 'type' => 'ORG'],
                ],
                'fields_json' => [
                    self::field('employee', 'Employee', FieldProfile::P1_INTERNAL),
                    self::field('employee_no', 'Employee no.', FieldProfile::P0_PUBLIC),
                    self::field('org_path', 'Organization', FieldProfile::P0_PUBLIC),
                    self::field('legal_entity', 'Legal entity', FieldProfile::P0_PUBLIC),
                    self::field('status', 'Status', FieldProfile::P0_PUBLIC),
                ],
            ],
            [
                'code' => 'CONTRACT_EXPIRY',
                'name' => 'Contract expiry',
                'description' => 'Contracts ending within 30, 60 or 90 days.',
                'source_projection' => 'workforce',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/contract_expiring',
                'filters_json' => [
                    ['key' => 'window', 'label' => 'Expiry window', 'type' => 'SELECT', 'options' => ['30', '60', '90']],
                ],
                'fields_json' => [
                    self::field('employee', 'Employee', FieldProfile::P1_INTERNAL),
                    self::field('org_path', 'Organization', FieldProfile::P0_PUBLIC),
                    self::field('end_date', 'End date', FieldProfile::P0_PUBLIC),
                    self::field('days_remaining', 'Days remaining', FieldProfile::P0_PUBLIC),
                    self::field('status', 'Contract status', FieldProfile::P0_PUBLIC),
                ],
            ],
            [
                'code' => 'PENDING_APPROVALS',
                'name' => 'Pending approvals by age',
                'description' => 'Open workflow tasks with elapsed business days.',
                'source_projection' => 'pending_approvals',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/pending_approvals',
                'filters_json' => [
                    ['key' => 'min_age_days', 'label' => 'Minimum age (days)', 'type' => 'SELECT', 'options' => ['0', '3', '7']],
                ],
                'fields_json' => [
                    self::field('case_no', 'Case no.', FieldProfile::P0_PUBLIC),
                    self::field('title', 'Case title', FieldProfile::P2_SENSITIVE),
                    self::field('status', 'Task status', FieldProfile::P0_PUBLIC),
                    self::field('age_days', 'Age (days)', FieldProfile::P0_PUBLIC),
                ],
            ],
            [
                'code' => 'LEAVE_LIABILITY',
                'name' => 'Leave liability',
                'description' => 'Remaining leave minutes by employment and leave type.',
                'source_projection' => 'leave_liability',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/leave_liability',
                'filters_json' => [
                    ['key' => 'leave_type', 'label' => 'Leave type', 'type' => 'SELECT'],
                ],
                'fields_json' => [
                    self::field('employee', 'Employee', FieldProfile::P1_INTERNAL),
                    self::field('employee_no', 'Employee no.', FieldProfile::P0_PUBLIC),
                    self::field('leave_type', 'Leave type', FieldProfile::P0_PUBLIC),
                    self::field('balance_minutes', 'Balance (minutes)', FieldProfile::P1_INTERNAL),
                ],
            ],
            [
                'code' => 'PAYROLL_COST',
                'name' => 'Payroll cost by legal entity',
                'description' => 'Payroll gross, net and employer cost by legal entity. Unavailable until payroll-run projections land.',
                'source_projection' => 'payroll_cost',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/payroll_cost_by_entity',
                'filters_json' => [
                    ['key' => 'legal_entity_id', 'label' => 'Legal entity', 'type' => 'SELECT'],
                ],
                'fields_json' => [
                    self::field('legal_entity', 'Legal entity', FieldProfile::P0_PUBLIC),
                    self::field('gross_cents', 'Gross (minor units)', FieldProfile::P2_SENSITIVE),
                    self::field('net_cents', 'Net (minor units)', FieldProfile::P2_SENSITIVE),
                    self::field('employer_cost_cents', 'Employer cost (minor units)', FieldProfile::P2_SENSITIVE),
                    self::field('salary', 'Restricted salary detail', FieldProfile::P3_RESTRICTED),
                ],
            ],
            [
                'code' => 'RECRUITMENT_FUNNEL',
                'name' => 'Recruitment funnel',
                'description' => 'Candidate counts by recruitment stage. Unavailable until Talent projections land.',
                'source_projection' => 'recruitment_funnel',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/recruitment_funnel',
                'filters_json' => [
                    ['key' => 'org_unit_id', 'label' => 'Organization', 'type' => 'ORG'],
                ],
                'fields_json' => [
                    self::field('stage', 'Stage', FieldProfile::P0_PUBLIC),
                    self::field('count', 'Count', FieldProfile::P0_PUBLIC),
                    self::field('org_path', 'Organization', FieldProfile::P0_PUBLIC),
                ],
            ],
            [
                'code' => 'TURNOVER',
                'name' => 'Voluntary turnover',
                'description' => 'Terminations and voluntary turnover. Unavailable until Lifecycle projections land.',
                'source_projection' => 'turnover',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/turnover',
                'filters_json' => [],
                'fields_json' => [
                    self::field('employee', 'Employee', FieldProfile::P1_INTERNAL),
                    self::field('terminated_on', 'Termination date', FieldProfile::P0_PUBLIC),
                    self::field('reason', 'Reason', FieldProfile::P2_SENSITIVE),
                ],
            ],
        ];
    }

    /**
     * Synchronise code-owned definitions into the tenant read model.
     */
    public function syncForTenant(int $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            foreach (self::definitions() as $definition) {
                ReportDefinition::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'code' => $definition['code']],
                    [
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'source_projection' => $definition['source_projection'],
                        'as_of_supported' => $definition['as_of_supported'],
                        'fields_json' => $definition['fields_json'],
                        'filters_json' => $definition['filters_json'],
                        'drilldown_route' => $definition['drilldown_route'],
                        'catalog_version' => 1,
                        'active' => true,
                    ],
                );
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloadForTenant(int $tenantId): array
    {
        $this->syncForTenant($tenantId);

        return ReportDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->map(static fn (ReportDefinition $definition): array => [
                'id' => (int) $definition->id,
                'code' => (string) $definition->code,
                'name' => (string) $definition->name,
                'description' => (string) ($definition->description ?? ''),
                'asOfSupported' => (bool) $definition->as_of_supported,
                'drilldownRoute' => $definition->drilldown_route,
                'fields' => array_map(static function (mixed $field): array {
                    /** @var array<string, mixed> $field */
                    $field = (array) $field;

                    return [
                        'key' => (string) ($field['key'] ?? ''),
                        'label' => (string) ($field['label'] ?? ''),
                        'profile' => (string) ($field['profile'] ?? ''),
                        'exportable' => (string) ($field['profile'] ?? '') !== FieldProfile::P3_RESTRICTED->value,
                    ];
                }, (array) $definition->fields_json),
                'filters' => (array) $definition->filters_json,
            ])
            ->all();
    }

    /**
     * @return array{key: string, label: string, profile: string, exportable: bool}
     */
    private static function field(string $key, string $label, FieldProfile $profile): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'profile' => $profile->value,
            'exportable' => ! $profile->isHidden(),
        ];
    }
}
