<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

/**
 * Dashboard payload contract: business-semantic DTO, Chinese reasons, and
 * availability that distinguishes configured, unavailable and forbidden.
 */
final class DashboardContractTest extends ReportingTestCase
{
    public function test_dashboard_returns_chinese_labels_units_and_no_debug_copy(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $body = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');

        $headcount = $this->metric($body, 'headcount');
        $this->assertSame('在职人数', $headcount['label']);
        $this->assertSame('PERSON', $headcount['unit']);
        $this->assertSame('人', $headcount['unitLabel']);
        $this->assertSame('AVAILABLE', $headcount['status']);
        $this->assertNull($headcount['reason']);
        $this->assertSame('AS_OF', $headcount['period']);
        $this->assertSame('AUTHORIZED', $headcount['scope']);
        $this->assertSame('按当前授权范围', $headcount['scopeLabel']);
        $this->assertSame(self::BUSINESS_DATE, $headcount['asOf']);
        $this->assertSame('/hr/employees', $headcount['drilldown']['route']);
        $this->assertSame(self::BUSINESS_DATE, $headcount['drilldown']['query']['as_of']);

        foreach (['probation', 'contract_expiring', 'pending_approvals', 'leave_liability', 'payroll_cost_by_entity', 'recruitment_funnel'] as $code) {
            $metric = $this->metric($body, $code);
            $this->assertArrayHasKey('label', $metric);
            $this->assertArrayHasKey('unitLabel', $metric);
            $this->assertArrayHasKey('availability', $metric);
            $this->assertArrayHasKey('scope', $metric);
            $this->assertArrayHasKey('period', $metric);
            $this->assertMatchesRegularExpression('/[\x{3400}-\x{9fff}]/u', (string) $metric['label']);
            $this->assertMatchesRegularExpression('/[\x{3400}-\x{9fff}]/u', (string) $metric['unitLabel']);
        }

        $encoded = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Welcome back', $encoded);
        $this->assertStringNotContainsString('View details', $encoded);
        $this->assertStringNotContainsString('authorized org scope', $encoded);
        $this->assertStringNotContainsString('最小货币单位', $encoded);
        $this->assertStringNotContainsString('MINOR_UNITS', $encoded);
    }

    public function test_dashboard_sections_are_derived_from_authorized_metric_rows(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $body = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');

        $departments = $body['sections']['departments'];
        $this->assertIsArray($departments);
        $this->assertSame(4, array_sum(array_column($departments, 'count')));
        $this->assertNotEmpty($body['sections']['workforce']);
        $this->assertSame(4, array_sum(array_column($body['sections']['workforce'], 'count')));
        $this->assertSame([], $body['sections']['recruitment']);

        foreach ($departments as $row) {
            $this->assertNotSame('', trim((string) $row['label']));
            $this->assertDoesNotMatchRegularExpression('/^#?\d+$/', (string) $row['label']);
        }
    }

    public function test_configured_but_unpublished_metrics_are_unconfigured_not_zero_passes(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $body = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');

        foreach (['payroll_cost_by_entity', 'recruitment_funnel'] as $code) {
            $metric = $this->metric($body, $code);
            $this->assertSame('UNCONFIGURED', $metric['status']);
            $this->assertSame('UNCONFIGURED', $metric['availability']);
            // The numeric transport value is still zero, but the status and
            // reason are what stop the UI from presenting it as a real zero.
            $this->assertSame(0, $metric['value']);
            $this->assertIsString($metric['reason']);
            $this->assertNotSame('', trim((string) $metric['reason']));
            $this->assertMatchesRegularExpression('/[\x{3400}-\x{9fff}]/u', (string) $metric['reason']);
            $this->assertStringNotContainsString('tables exist', (string) $metric['reason']);
        }
    }

    public function test_metric_endpoint_and_dashboard_share_the_same_business_semantics(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $dashboardMetric = $this->metric(
            $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)->assertOk()->json('data'),
            'leave_liability',
        );

        $drilldown = $this->getJson('/v1/reporting/dashboard/metrics/leave_liability?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');

        foreach (['label', 'unit', 'unitLabel', 'status', 'reason', 'scope', 'period'] as $key) {
            $this->assertSame($dashboardMetric[$key], $drilldown[$key], "Field {$key} differs between card and drill-down.");
        }

        $this->assertSame($dashboardMetric['value'], $drilldown['total']);
        $this->assertSame(self::BUSINESS_DATE, $drilldown['asOf']);
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function metric(array $dashboard, string $code): array
    {
        foreach ((array) $dashboard['metrics'] as $metric) {
            /** @var array<string, mixed> $metric */
            if (($metric['code'] ?? null) === $code) {
                return $metric;
            }
        }

        $this->fail("Dashboard metric {$code} is missing.");
    }
}
