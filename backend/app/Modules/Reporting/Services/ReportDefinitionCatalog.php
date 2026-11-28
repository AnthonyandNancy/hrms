<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Models\ReportDefinition;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
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
                'name' => '人力资源报表',
                'domain' => 'PEOPLE',
                'description' => '按统计日与组织范围统计在职员工及其组织、状态分布。',
                'source_projection' => 'workforce',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/headcount',
                'filters_json' => [
                    ['key' => 'org_unit_id', 'label' => '组织', 'type' => 'ORG'],
                ],
                'fields_json' => [
                    self::field('employee', '员工', FieldProfile::P1_INTERNAL),
                    self::field('employee_no', '工号', FieldProfile::P0_PUBLIC),
                    self::field('org_path', '组织', FieldProfile::P0_PUBLIC),
                    self::field('status', '状态', FieldProfile::P0_PUBLIC),
                ],
            ],
            [
                'code' => 'CONTRACT_EXPIRY',
                'name' => '合同到期报表',
                'domain' => 'PEOPLE',
                'description' => '统计未来 30、60 或 90 天内到期的劳动合同。',
                'source_projection' => 'workforce',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/contract_expiring',
                'filters_json' => [
                    ['key' => 'window', 'label' => '到期窗口', 'type' => 'SELECT', 'options' => ['30', '60', '90']],
                ],
                'fields_json' => [
                    self::field('employee', '员工', FieldProfile::P1_INTERNAL),
                    self::field('org_path', '组织', FieldProfile::P0_PUBLIC),
                    self::field('end_date', '到期日', FieldProfile::P0_PUBLIC),
                    self::field('days_remaining', '剩余天数', FieldProfile::P0_PUBLIC),
                    self::field('status', '合同状态', FieldProfile::P0_PUBLIC),
                ],
            ],
            [
                'code' => 'PENDING_APPROVALS',
                'name' => '待审批时效报表',
                'domain' => 'PEOPLE',
                'description' => '统计未完成审批事项及其已等待天数。',
                'source_projection' => 'pending_approvals',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/pending_approvals',
                'filters_json' => [
                    ['key' => 'min_age_days', 'label' => '最短等待天数', 'type' => 'SELECT', 'options' => ['0', '3', '7']],
                ],
                'fields_json' => [
                    self::field('case_no', '事项编号', FieldProfile::P0_PUBLIC),
                    self::field('title', '事项标题', FieldProfile::P2_SENSITIVE),
                    self::field('status', '任务状态', FieldProfile::P0_PUBLIC),
                    self::field('age_days', '已等待天数', FieldProfile::P0_PUBLIC),
                ],
            ],
            [
                'code' => 'LEAVE_LIABILITY',
                'name' => '假期负债报表',
                'domain' => 'ATTENDANCE',
                'description' => '按员工与假期类型统计剩余假期账本分钟数。',
                'source_projection' => 'leave_liability',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/leave_liability',
                'filters_json' => [
                    ['key' => 'leave_type', 'label' => '假期类型', 'type' => 'SELECT'],
                ],
                'fields_json' => [
                    self::field('employee', '员工', FieldProfile::P1_INTERNAL),
                    self::field('employee_no', '工号', FieldProfile::P0_PUBLIC),
                    self::field('leave_type', '假期类型', FieldProfile::P0_PUBLIC),
                    self::field('balance_minutes', '余额（分钟）', FieldProfile::P1_INTERNAL),
                ],
            ],
            [
                'code' => 'PAYROLL_COST',
                'name' => '工资成本报表',
                'domain' => 'PAYROLL',
                'description' => '统计工资批次的应发、实发与雇主成本；投影未发布时返回不可用，不估算。',
                'source_projection' => 'payroll_cost',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/payroll_cost_by_entity',
                'filters_json' => [],
                'fields_json' => [
                    self::field('employee', '员工', FieldProfile::P1_INTERNAL),
                    self::field('currency_code', '币种', FieldProfile::P0_PUBLIC),
                    self::field('gross_cents', '应发工资（元）', FieldProfile::P2_SENSITIVE),
                    self::field('net_cents', '实发工资（元）', FieldProfile::P2_SENSITIVE),
                    self::field('employer_cost_cents', '雇主成本（元）', FieldProfile::P2_SENSITIVE),
                    self::field('salary', '受限薪资明细', FieldProfile::P3_RESTRICTED),
                ],
            ],
            [
                'code' => 'RECRUITMENT_FUNNEL',
                'name' => '招聘转化报表',
                'domain' => 'RECRUITMENT',
                'description' => '按招聘阶段统计候选人数；招聘投影未发布时返回不可用，不推算。',
                'source_projection' => 'recruitment_funnel',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/recruitment_funnel',
                'filters_json' => [
                    ['key' => 'org_unit_id', 'label' => '组织', 'type' => 'ORG'],
                ],
                'fields_json' => [
                    self::field('stage', '阶段', FieldProfile::P0_PUBLIC),
                    self::field('count', '人数', FieldProfile::P0_PUBLIC),
                    self::field('org_path', '组织', FieldProfile::P0_PUBLIC),
                ],
            ],
            [
                'code' => 'TURNOVER',
                'name' => '主动离职报表',
                'domain' => 'PEOPLE',
                'description' => '统计主动离职人数与原因；离职投影未发布时返回不可用，不估算。',
                'source_projection' => 'turnover',
                'as_of_supported' => true,
                'drilldown_route' => '/dashboard/metric/turnover',
                'filters_json' => [],
                'fields_json' => [
                    self::field('employee', '员工', FieldProfile::P1_INTERNAL),
                    self::field('terminated_on', '离职日期', FieldProfile::P0_PUBLIC),
                    self::field('reason', '离职原因', FieldProfile::P2_SENSITIVE),
                ],
            ],
        ];
    }

    /**
     * Whitelisted projection fields, keyed by source projection. A custom
     * definition may only select a subset of this map; it can never add a
     * field or a predicate that is not declared here.
     *
     * @return array<string, array<string, array{key: string, label: string, profile: string, exportable: bool}>>
     */
    public static function whitelist(): array
    {
        $whitelist = [];

        foreach (self::definitions() as $definition) {
            $fields = [];

            foreach ((array) $definition['fields_json'] as $field) {
                $fields[(string) $field['key']] = $field;
            }

            $source = (string) $definition['source_projection'];
            $whitelist[$source] = [...($whitelist[$source] ?? []), ...$fields];
        }

        return $whitelist;
    }

    /**
     * Whitelisted filters, keyed by source projection.
     *
     * @return array<string, list<string>>
     */
    public static function filterWhitelist(): array
    {
        $whitelist = [];

        foreach (self::definitions() as $definition) {
            $source = (string) $definition['source_projection'];
            $keys = array_map(
                static fn (array $filter): string => (string) $filter['key'],
                (array) $definition['filters_json'],
            );
            $whitelist[$source] = array_values(array_unique([...($whitelist[$source] ?? []), ...$keys]));
        }

        return $whitelist;
    }

    /**
     * Validate a custom definition against the approved whitelist. Requests
     * containing SQL are rejected with a stable reason before field lookup so
     * operators can tell an injection attempt from a typo.
     *
     * @param  array<string, mixed>  $input
     * @return array{code: string, name: string, description: string, source_projection: string, fields_json: list<array<string, mixed>>, filters_json: list<array<string, mixed>>}
     */
    public function validateCustomDefinition(array $input): array
    {
        $filterInput = (array) ($input['filters'] ?? []);
        // Filter keys are validated against the approved filter whitelist
        // below, so a key named `where` yields UNKNOWN_REPORT_FILTER rather
        // than the ambiguous SQL reason. Values are still scanned: a value
        // containing SQL remains a hard SQL rejection.
        $filterText = array_is_list($filterInput)
            ? array_map('strval', $filterInput)
            : array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                array_values($filterInput),
            );
        $sqlPayload = [
            (string) ($input['code'] ?? ''),
            (string) ($input['name'] ?? ''),
            (string) ($input['description'] ?? ''),
            (string) ($input['source_projection'] ?? ''),
            ...array_map('strval', (array) ($input['fields'] ?? [])),
            ...$filterText,
        ];
        $sqlPattern = '/\b(select|insert|update|delete|drop|alter|union|join|where|exec|truncate|create|grant|revoke)\b|--|\/\*|\*\/|;|`|\bfrom\s+\w+|\b(?:sleep|benchmark)\s*\(/i';

        foreach ($sqlPayload as $value) {
            if (preg_match($sqlPattern, $value) === 1) {
                throw new ValidationException(
                    ['fields' => ['Custom report definitions may only reference approved whitelist fields; SQL and free-form predicates are not allowed.']],
                    'The given data was invalid.',
                    ['reason' => 'REPORT_SQL_NOT_ALLOWED'],
                );
            }
        }

        $source = (string) ($input['source_projection'] ?? '');
        $whitelist = self::whitelist();

        if (! isset($whitelist[$source])) {
            throw new ValidationException(
                ['source_projection' => ['Unknown report source projection.']],
                'The given data was invalid.',
                ['reason' => 'UNKNOWN_REPORT_SOURCE'],
            );
        }

        $fieldKeys = array_values(array_map('strval', (array) ($input['fields'] ?? [])));

        if ($fieldKeys === []) {
            throw new ValidationException(
                ['fields' => ['At least one whitelisted field is required.']],
                'The given data was invalid.',
                ['reason' => 'REPORT_FIELD_REQUIRED'],
            );
        }

        $selectedFields = [];

        foreach ($fieldKeys as $key) {
            $field = $whitelist[$source][$key] ?? null;

            if ($field === null) {
                throw new ValidationException(
                    ['fields' => ["Field {$key} is not in the approved whitelist for {$source}."]],
                    'The given data was invalid.',
                    ['reason' => 'UNKNOWN_REPORT_FIELD'],
                );
            }

            $selectedFields[$key] = $field;
        }

        $filterKeys = array_is_list($filterInput)
            ? array_values(array_map('strval', $filterInput))
            : array_values(array_map('strval', array_keys($filterInput)));
        $allowedFilters = self::filterWhitelist()[$source] ?? [];
        $selectedFilters = [];

        foreach ($filterKeys as $key) {
            if (! in_array($key, $allowedFilters, true)) {
                throw new ValidationException(
                    ['filters' => ["Filter {$key} is not an approved filter for {$source}."]],
                    'The given data was invalid.',
                    ['reason' => 'UNKNOWN_REPORT_FILTER'],
                );
            }

            $selectedFilters[] = ['key' => $key];
        }

        return [
            'code' => strtoupper(trim((string) ($input['code'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'source_projection' => $source,
            'fields_json' => array_values($selectedFields),
            'filters_json' => $selectedFilters,
        ];
    }

    /**
     * Store a company-wide custom definition. Only the validated whitelist
     * selection is persisted; no SQL, expression or free-form predicate can
     * enter the row.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createCustom(int $actorUserId, array $input): array
    {
        $definition = $this->validateCustomDefinition($input);

        if ($definition['code'] === '' || $definition['name'] === '') {
            throw new ValidationException(['code' => ['Code and name are required.']], 'The given data was invalid.', ['reason' => 'INVALID_REPORT_DEFINITION']);
        }

        $this->syncSystemDefinitions();

        if (ReportDefinition::query()
            ->where('code', $definition['code'])
            ->exists()) {
            throw new ConflictException('A report definition with this code already exists.', ['reason' => 'REPORT_CODE_RESERVED']);
        }

        $row = ReportDefinition::query()->create([
            'is_system' => false,
            'code' => $definition['code'],
            'name' => $definition['name'],
            'description' => $definition['description'] !== '' ? $definition['description'] : null,
            'source_projection' => $definition['source_projection'],
            'as_of_supported' => true,
            'fields_json' => $definition['fields_json'],
            'filters_json' => $definition['filters_json'],
            'drilldown_route' => null,
            'catalog_version' => 1,
            'active' => true,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);

        return $this->payload($row);
    }

    /**
     * Synchronise code-owned definitions into the company read model.
     */
    public function syncSystemDefinitions(): void
    {
        DB::transaction(function (): void {
            foreach (self::definitions() as $definition) {
                $existing = ReportDefinition::query()
                    ->where('code', $definition['code'])
                    ->first();

                // Custom definitions can never be shadowed by a
                // code-owned definition added later with the same code.
                if ($existing !== null && ! (bool) $existing->is_system) {
                    continue;
                }

                // A pre-upgrade system row may still carry the old English
                // labels. Replace only those technical copies; any later
                // admin-edited Chinese row remains untouched.
                $fields = (array) $definition['fields_json'];

                if ($existing !== null) {
                    $stored = array_map(
                        static fn (mixed $field): string => (string) (((array) $field)['label'] ?? ''),
                        (array) $existing->fields_json,
                    );
                    $technical = array_filter(
                        $stored,
                        static fn (string $label): bool => $label !== ''
                            && preg_match('/[A-Za-z]/', $label) === 1
                            && preg_match('/[\x{4e00}-\x{9fff}]/u', $label) !== 1,
                    );

                    if ($technical === []) {
                        $fields = (array) $existing->fields_json;
                    }
                }

                ReportDefinition::query()->updateOrCreate(
                    ['code' => $definition['code']],
                    [
                        'is_system' => true,
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'source_projection' => $definition['source_projection'],
                        'as_of_supported' => $definition['as_of_supported'],
                        'fields_json' => $fields,
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
    public function allActive(): array
    {
        $this->syncSystemDefinitions();

        return ReportDefinition::query()
            ->where('active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (ReportDefinition $definition): array => $this->payload($definition))
            ->all();
    }

    /**
     * Code-owned definition lookup for contract tests and diagnostics. The
     * returned shape mirrors the public business definition and never exposes
     * the internal source projection.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        foreach (self::definitions() as $definition) {
            if ((string) $definition['code'] !== $code) {
                continue;
            }

            return [
                'id' => 0,
                'code' => (string) $definition['code'],
                'name' => (string) $definition['name'],
                'domain' => (string) ($definition['domain'] ?? $this->domainFor((string) $definition['code'], (string) $definition['source_projection'])),
                'description' => (string) $definition['description'],
                'asOfSupported' => (bool) $definition['as_of_supported'],
                'drilldownRoute' => $definition['drilldown_route'],
                'fields' => array_map(static function (mixed $field): array {
                    /** @var array<string, mixed> $field */
                    $field = (array) $field;
                    $profile = FieldProfile::from((string) ($field['profile'] ?? FieldProfile::P0_PUBLIC->value));

                    return [
                        'key' => (string) ($field['key'] ?? ''),
                        'label' => (string) ($field['label'] ?? ''),
                        'profile' => $profile->value,
                        'exportable' => ! $profile->isHidden(),
                    ];
                }, (array) $definition['fields_json']),
                'filters' => (array) $definition['filters_json'],
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ReportDefinition $definition): array
    {
        return [
            'id' => (int) $definition->id,
            'code' => (string) $definition->code,
            'name' => (string) $definition->name,
            'domain' => $this->domainFor((string) $definition->code, (string) $definition->source_projection),
            'description' => (string) ($definition->description ?? ''),
            // Internal seam for ReportService's custom-definition mapping.
            // ReportingController removes it before the business API response.
            'sourceProjection' => (string) $definition->source_projection,
            'isSystem' => (bool) $definition->is_system,
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
        ];
    }

    /**
     * Stable business-domain grouping. Technical projection names never leave
     * the server, so the UI receives a business enum instead.
     */
    private function domainFor(string $code, string $sourceProjection): string
    {
        return match ($code) {
            'WORKFORCE_HEADCOUNT', 'CONTRACT_EXPIRY', 'PENDING_APPROVALS', 'TURNOVER' => 'PEOPLE',
            'LEAVE_LIABILITY' => 'ATTENDANCE',
            'PAYROLL_COST' => 'PAYROLL',
            'RECRUITMENT_FUNNEL' => 'RECRUITMENT',
            default => match ($sourceProjection) {
                'leave_liability' => 'ATTENDANCE',
                'payroll_cost' => 'PAYROLL',
                'recruitment_funnel' => 'RECRUITMENT',
                default => 'PEOPLE',
            },
        };
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
