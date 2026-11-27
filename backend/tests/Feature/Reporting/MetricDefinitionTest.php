<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Services\MetricDefinition;

/**
 * The metric dictionary is a frozen product contract. Labels, units,
 * predicates, reasons and drill-downs must be business-readable Chinese and
 * must never leak technical unit codes or debug predicates.
 */
final class MetricDefinitionTest extends ReportingTestCase
{
    public function test_metric_definitions_are_chinese_and_have_units(): void
    {
        $this->assertSame('在职人数', MetricDefinition::label('headcount'));
        $this->assertSame('人', MetricDefinition::unitLabel('headcount'));
        $this->assertSame('试用期人数', MetricDefinition::label('probation'));
        $this->assertSame('人', MetricDefinition::unitLabel('probation'));
        $this->assertSame('项', MetricDefinition::unitLabel('pending_approvals'));
        $this->assertSame('份', MetricDefinition::unitLabel('contract_expiring'));
        $this->assertSame('分钟', MetricDefinition::unitLabel('leave_liability'));
        $this->assertSame('薪酬成本', MetricDefinition::label('payroll_cost_by_entity'));
        $this->assertSame('CNY_CENT', MetricDefinition::unit('payroll_cost_by_entity'));
        $this->assertSame('元', MetricDefinition::unitLabel('payroll_cost_by_entity'));
        $this->assertSame('招聘漏斗', MetricDefinition::label('recruitment_funnel'));
        $this->assertSame('人', MetricDefinition::unitLabel('recruitment_funnel'));
    }

    public function test_predicates_and_reasons_are_business_chinese_without_debug_terms(): void
    {
        foreach (MetricDefinition::codes() as $code) {
            $predicate = MetricDefinition::predicate($code);
            $this->assertMatchesRegularExpression('/[\x{3400}-\x{9fff}]/u', $predicate, $code);
            $this->assertStringNotContainsString('authorized org scope', $predicate);
            $this->assertStringNotContainsString('as_of', $predicate);
            $this->assertStringNotContainsString('employment.status', $predicate);
        }

        $reason = MetricDefinition::reason('payroll_cost_by_entity');
        $this->assertStringContainsString('薪酬成本', $reason);
        $this->assertStringNotContainsString('Payroll run tables', $reason);
        $this->assertStringNotContainsString('projection publishes', $reason);
    }

    public function test_drilldown_carries_the_same_as_of_and_whitelisted_route(): void
    {
        $this->assertSame([
            'route' => '/hr/employees',
            'query' => ['as_of' => '2026-11-23'],
        ], MetricDefinition::drilldown('headcount', '2026-11-23'));

        foreach (MetricDefinition::codes() as $code) {
            $drilldown = MetricDefinition::drilldown($code, '2026-11-23');
            $this->assertArrayHasKey('route', $drilldown);
            $this->assertArrayHasKey('query', $drilldown);
            $this->assertArrayHasKey('as_of', $drilldown['query']);
            $this->assertMatchesRegularExpression('#^/[a-z0-9/\\-]+$#', $drilldown['route']);
        }
    }

    public function test_stage_and_status_labels_are_chinese(): void
    {
        $this->assertSame('面试', MetricDefinition::stageLabel('INTERVIEW'));
        $this->assertSame('新申请', MetricDefinition::stageLabel('NEW'));
        $this->assertSame('在职', MetricDefinition::statusLabel('ACTIVE'));
        $this->assertSame('试用期', MetricDefinition::statusLabel('PROBATION'));
        $this->assertSame('待处理', MetricDefinition::statusLabel('PENDING'));
        $this->assertSame('其他状态', MetricDefinition::statusLabel('SOMETHING_UNKNOWN'));
    }
}
