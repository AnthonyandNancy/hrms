<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

/**
 * Dashboard and drill-down must be projections of one predicate. The same
 * principal and as-of therefore cannot produce a KPI whose value disagrees
 * with the authorizer-constrained rows returned by the drill-down endpoint.
 */
final class DashboardConsistencyTest extends ReportingTestCase
{
    public function test_dashboard_totals_match_the_same_metric_drilldown_as_of_and_scope(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $dashboard = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');

        $this->assertSame(self::BUSINESS_DATE, $dashboard['asOf']);
        $this->assertSame(4, $this->metric($dashboard, 'headcount')['value']);
        $this->assertSame(2, $this->metric($dashboard, 'contract_expiring')['value']);
        $this->assertSame(840, $this->metric($dashboard, 'leave_liability')['value']);

        foreach (['headcount' => 4, 'contract_expiring' => 2, 'leave_liability' => 840] as $code => $expected) {
            $metric = $this->metric($dashboard, $code);

            foreach (['code', 'label', 'unit', 'status', 'reason', 'sourceHash', 'computedAt'] as $key) {
                $this->assertArrayHasKey($key, $metric, "Metric {$code} is missing {$key}.");
            }

            $this->assertSame($expected, $metric['value']);
            $this->assertSame('AVAILABLE', $metric['status']);
            $this->assertNull($metric['reason']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $metric['sourceHash']);
            $this->assertSame(
                (string) \Carbon\CarbonImmutable::parse((string) $metric['computedAt'])->utc()->toIso8601ZuluString(),
                (string) $metric['computedAt'],
            );

            $drilldown = $this->getJson(
                '/v1/reporting/dashboard/metrics/'.$code.'?as_of='.self::BUSINESS_DATE,
            )->assertOk()->json('data');

            $this->assertSame(self::BUSINESS_DATE, $drilldown['asOf'], "KPI and drill-down as-of differ for {$code}.");
            $this->assertSame($metric['value'], $drilldown['total'], "KPI and drill-down totals differ for {$code}.");
            $this->assertSame($metric['unit'], $drilldown['unit']);

            if ($code === 'leave_liability') {
                $drilldownSum = array_sum(array_map(
                    static fn (array $item): int => (int) $item['value'],
                    $drilldown['items'],
                ));
                $this->assertSame(840, $drilldownSum);
                $this->assertCount(2, $drilldown['items']);
            } else {
                $this->assertCount($drilldown['total'], $drilldown['items'], "Drill-down row count differs for {$code}.");
            }
        }

        $this->assertCount(4, $this->getJson(
            '/v1/reporting/dashboard/metrics/headcount?as_of='.self::BUSINESS_DATE,
        )->assertOk()->json('data.items'));

        $this->assertCount(2, $this->getJson(
            '/v1/reporting/dashboard/metrics/contract_expiring?as_of='.self::BUSINESS_DATE,
        )->assertOk()->json('data.items'));
    }

    public function test_narrowed_scope_reduces_both_paths_and_query_filters_cannot_widen_an_org_scope(): void
    {
        $ids = $this->seedSyntheticWorkforce();

        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );
        $broad = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');
        $broadHeadcount = (int) $this->metric($broad, 'headcount')['value'];
        $broadContracts = (int) $this->metric($broad, 'contract_expiring')['value'];
        $broadLeave = (int) $this->metric($broad, 'leave_liability')['value'];

        $this->authenticate(
            (int) $ids['narrow_user']->id,
            (int) $ids['employment_a2_id'],
            (int) $ids['narrow_user']->auth_version,
        );

        $dashboard = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');

        $this->assertLessThan($broadHeadcount, (int) $this->metric($dashboard, 'headcount')['value']);
        $this->assertLessThan($broadContracts, (int) $this->metric($dashboard, 'contract_expiring')['value']);
        $this->assertLessThan($broadLeave, (int) $this->metric($dashboard, 'leave_liability')['value']);

        $this->assertSame(2, $this->metric($dashboard, 'headcount')['value']);
        $this->assertSame(1, $this->metric($dashboard, 'contract_expiring')['value']);
        $this->assertSame(600, $this->metric($dashboard, 'leave_liability')['value']);

        $headcount = $this->getJson(
            '/v1/reporting/dashboard/metrics/headcount?as_of='.self::BUSINESS_DATE,
        )->assertOk()->json('data');
        $this->assertSame(2, $headcount['total']);
        $this->assertCount(2, $headcount['items']);

        $contracts = $this->getJson(
            '/v1/reporting/dashboard/metrics/contract_expiring?as_of='.self::BUSINESS_DATE,
        )->assertOk()->json('data');
        $this->assertSame(1, $contracts['total']);
        $this->assertCount(1, $contracts['items']);
        $this->assertSame((string) $ids['employment_a1_id'], $contracts['items'][0]['id']);

        $leave = $this->getJson(
            '/v1/reporting/dashboard/metrics/leave_liability?as_of='.self::BUSINESS_DATE,
        )->assertOk()->json('data');
        $this->assertSame(600, $leave['total']);
        $this->assertCount(1, $leave['items']);

        // Changing the client-supplied org filter to a unit outside the
        // caller's authorized org cannot broaden the Authorizer predicate.
        $blocked = $this->getJson(
            '/v1/reporting/dashboard/metrics/headcount?as_of='.self::BUSINESS_DATE
            .'&org_unit_id='.$ids['org_b_id'],
        )->assertOk()->json('data');
        $this->assertSame(0, $blocked['total']);
        $this->assertSame([], $blocked['items']);

        $allowed = $this->getJson(
            '/v1/reporting/dashboard/metrics/headcount?as_of='.self::BUSINESS_DATE
            .'&org_unit_id='.$ids['org_a_id'],
        )->assertOk()->json('data');
        $this->assertSame(2, $allowed['total']);
        $this->assertCount(2, $allowed['items']);
    }

    public function test_unavailable_metric_keeps_status_and_reason_instead_of_becoming_zero_pass(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $dashboard = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');

        $payroll = $this->metric($dashboard, 'payroll_cost_by_entity');

        $this->assertArrayHasKey('status', $payroll);
        $this->assertArrayHasKey('reason', $payroll);
        $this->assertArrayHasKey('sourceHash', $payroll);
        $this->assertArrayHasKey('computedAt', $payroll);
        $this->assertSame('UNAVAILABLE', $payroll['status']);
        $this->assertNotSame('AVAILABLE', $payroll['status']);
        $this->assertNotSame('PASS', $payroll['status']);
        $this->assertIsString($payroll['reason']);
        $this->assertNotSame('', trim((string) $payroll['reason']));
        $this->assertSame(0, $payroll['value']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $payroll['sourceHash']);
        $this->assertSame(
            (string) \Carbon\CarbonImmutable::parse((string) $payroll['computedAt'])->utc()->toIso8601ZuluString(),
            (string) $payroll['computedAt'],
        );

        $drilldown = $this->getJson(
            '/v1/reporting/dashboard/metrics/payroll_cost_by_entity?as_of='.self::BUSINESS_DATE,
        )->assertOk()->json('data');

        $this->assertSame(self::BUSINESS_DATE, $drilldown['asOf']);
        $this->assertSame('UNAVAILABLE', $drilldown['status']);
        $this->assertNotSame('AVAILABLE', $drilldown['status']);
        $this->assertNotSame('PASS', $drilldown['status']);
        $this->assertIsString($drilldown['reason']);
        $this->assertNotSame('', trim((string) $drilldown['reason']));
        $this->assertSame(0, $drilldown['total']);
        $this->assertSame([], $drilldown['items']);
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
