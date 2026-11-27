<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use InvalidArgumentException;

/**
 * Frozen business dictionary for dashboard metrics.
 *
 * Reporting owns the labels, units, predicates and drill-down destinations so
 * every read path renders the same business meaning. Technical codes may be
 * used as identifiers, but they are never the user-facing copy.
 */
final class MetricDefinition
{
    /**
     * @var array<string, array{
     *     label: string,
     *     source: string,
     *     metric: string,
     *     unit: string,
     *     unit_label: string,
     *     predicate: string,
     *     drilldown: string
     * }>
     */
    private const DEFINITIONS = [
        'headcount' => [
            'label' => '在职人数',
            'source' => 'workforce',
            'metric' => 'headcount',
            'unit' => 'PERSON',
            'unit_label' => '人',
            'predicate' => '统计日有效任职且在当前授权组织范围内',
            'drilldown' => '/hr/employees',
        ],
        'probation' => [
            'label' => '试用期人数',
            'source' => 'workforce',
            'metric' => 'probation',
            'unit' => 'PERSON',
            'unit_label' => '人',
            'predicate' => '统计日处于试用期且在当前授权组织范围内',
            'drilldown' => '/hr/employees',
        ],
        'contract_expiring' => [
            'label' => '即将到期合同',
            'source' => 'workforce',
            'metric' => 'contract_expiring',
            'unit' => 'CONTRACT',
            'unit_label' => '份',
            'predicate' => '未来 30 天内到期且在当前授权组织范围内',
            'drilldown' => '/hr/contracts',
        ],
        'pending_approvals' => [
            'label' => '待审批事项',
            'source' => 'pending_approvals',
            'metric' => 'pending_approvals',
            'unit' => 'ITEM',
            'unit_label' => '项',
            'predicate' => '当前用户有权办理的未完成审批',
            'drilldown' => '/workflow',
        ],
        'leave_liability' => [
            'label' => '假期负债',
            'source' => 'leave_liability',
            'metric' => 'leave_liability',
            'unit' => 'MINUTE',
            'unit_label' => '分钟',
            'predicate' => '统计日剩余假期账本分钟数',
            'drilldown' => '/leave',
        ],
        'payroll_cost_by_entity' => [
            'label' => '薪酬成本',
            'source' => 'payroll_cost',
            'metric' => 'payroll_cost_by_entity',
            'unit' => 'CNY_CENT',
            'unit_label' => '元',
            'predicate' => '已发布工资批次的雇主成本',
            'drilldown' => '/payroll',
        ],
        'recruitment_funnel' => [
            'label' => '招聘漏斗',
            'source' => 'recruitment_funnel',
            'metric' => 'recruitment_funnel',
            'unit' => 'PERSON',
            'unit_label' => '人',
            'predicate' => '各招聘阶段的候选人数量',
            'drilldown' => '/recruitment/candidates',
        ],
        'turnover' => [
            'label' => '主动离职人数',
            'source' => 'turnover',
            'metric' => 'turnover',
            'unit' => 'PERSON',
            'unit_label' => '人',
            'predicate' => '统计年度内主动离职且在当前授权组织范围内',
            'drilldown' => '/hr/movements',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function dashboardCodes(): array
    {
        return [
            'headcount',
            'probation',
            'contract_expiring',
            'pending_approvals',
            'leave_liability',
            'payroll_cost_by_entity',
            'recruitment_funnel',
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function exists(string $code): bool
    {
        return isset(self::DEFINITIONS[$code]);
    }

    public static function label(string $code): string
    {
        return self::definition($code)['label'];
    }

    public static function unit(string $code): string
    {
        return self::definition($code)['unit'];
    }

    public static function unitLabel(string $code): string
    {
        return self::definition($code)['unit_label'];
    }

    public static function predicate(string $code): string
    {
        return self::definition($code)['predicate'];
    }

    public static function source(string $code): string
    {
        return self::definition($code)['source'];
    }

    public static function metricCode(string $code): string
    {
        return self::definition($code)['metric'];
    }

    /**
     * @return array{route: string, query: array{as_of: string}}
     */
    public static function drilldown(string $code, string $asOf): array
    {
        return [
            'route' => self::definition($code)['drilldown'],
            'query' => ['as_of' => $asOf],
        ];
    }

    public static function reason(string $code): string
    {
        return match ($code) {
            'payroll_cost_by_entity' => '薪酬成本投影尚未配置：仅统计已发布工资批次的雇主成本，当前不提供估算值。',
            'recruitment_funnel' => '招聘漏斗投影尚未配置：需由招聘模块发布真实候选人阶段数量，当前不提供推断值。',
            'turnover' => '离职投影尚未配置：当前不提供推断值。',
            default => '该指标在当前授权范围内尚未配置，未以估算值替代。',
        };
    }

    public static function forbiddenReason(string $code): string
    {
        return match ($code) {
            'payroll_cost_by_entity' => '当前账号无权查看薪酬成本。',
            'recruitment_funnel' => '当前账号无权查看招聘漏斗。',
            default => '当前账号无权查看该指标。',
        };
    }

    public static function stageLabel(string $stage): string
    {
        return match (mb_strtoupper(trim($stage))) {
            'NEW' => '新申请',
            'SCREENING' => '筛选',
            'INTERVIEW' => '面试',
            'OFFER' => '录用',
            'OFFER_ACCEPTED' => '已接受录用',
            'CONVERTED' => '已转入职',
            'REJECTED' => '已淘汰',
            'HIRED' => '已入职',
            default => '其他阶段',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match (mb_strtoupper(trim($status))) {
            'ACTIVE' => '在职',
            'PROBATION' => '试用期',
            'LEAVE' => '休假中',
            'SUSPENDED' => '停职',
            'TERMINATED' => '已离职',
            'PENDING' => '待处理',
            'OPEN' => '待处理',
            'IN_APPROVAL' => '审批中',
            'APPROVED' => '已批准',
            'REJECTED' => '已驳回',
            'CANCELLED' => '已取消',
            'COMPLETED' => '已完成',
            'CLOSED' => '已关闭',
            'EXPIRING' => '即将到期',
            default => '其他状态',
        };
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function columns(string $code): array
    {
        return match ($code) {
            'pending_approvals' => [
                ['key' => 'primary', 'label' => '审批事项'],
                ['key' => 'status', 'label' => '状态'],
                ['key' => 'value', 'label' => '已等待天数'],
            ],
            'leave_liability' => [
                ['key' => 'primary', 'label' => '假期类型'],
                ['key' => 'secondary', 'label' => '员工'],
                ['key' => 'value', 'label' => '分钟'],
            ],
            'contract_expiring' => [
                ['key' => 'primary', 'label' => '员工'],
                ['key' => 'secondary', 'label' => '组织'],
                ['key' => 'value', 'label' => '剩余天数'],
            ],
            'payroll_cost_by_entity' => [
                ['key' => 'primary', 'label' => '薪酬范围'],
                ['key' => 'secondary', 'label' => '币种'],
                ['key' => 'value', 'label' => '雇主成本（元）'],
            ],
            'recruitment_funnel' => [
                ['key' => 'primary', 'label' => '招聘阶段'],
                ['key' => 'value', 'label' => '候选人数'],
            ],
            default => [
                ['key' => 'primary', 'label' => '姓名'],
                ['key' => 'secondary', 'label' => '组织/编号'],
                ['key' => 'status', 'label' => '状态'],
            ],
        };
    }

    /**
     * @return array{
     *     label: string,
     *     source: string,
     *     metric: string,
     *     unit: string,
     *     unit_label: string,
     *     predicate: string,
     *     drilldown: string
     * }
     */
    private static function definition(string $code): array
    {
        if (! isset(self::DEFINITIONS[$code])) {
            throw new InvalidArgumentException("Unknown metric definition: {$code}");
        }

        return self::DEFINITIONS[$code];
    }
}
