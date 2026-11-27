<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Payroll\Contracts\PayrollCostDirectory;
use App\Modules\Payroll\Contracts\PayrollCostRow;
use App\Modules\Reporting\Models\ProjectionRow;
use App\Modules\Reporting\Services\ProjectionBuilder;
use App\Modules\Talent\Contracts\RecruitmentFunnelDirectory;
use App\Modules\Talent\Contracts\RecruitmentFunnelRow;

/**
 * Payroll cost and recruitment funnel are unlocked through their owning
 * modules' Contracts. The test fakes model published payroll totals and
 * distinct candidates by stage; Reporting only writes its own projection.
 */
final class PayrollRecruitmentMetricTest extends ReportingTestCase
{
    public function test_payroll_cost_metric_uses_only_published_runs_from_the_contract(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $this->app->instance(PayrollCostDirectory::class, new FakePayrollCostDirectory([
            new PayrollCostRow(200000, 150000, 123456, 'CNY'),
        ]));

        $response = $this->getJson(
            '/v1/reporting/dashboard/metrics/payroll_cost_by_entity?as_of='.self::BUSINESS_DATE,
        )->assertOk();

        $this->assertSame('AVAILABLE', $response->json('data.status'));
        $this->assertSame(123456, $response->json('data.total'));
        $this->assertSame('CNY_CENT', $response->json('data.unit'));
        $this->assertSame('元', $response->json('data.unitLabel'));
        $this->assertSame('薪酬成本', $response->json('data.label'));

        $dashboard = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');
        $payrollCard = $this->metric($dashboard, 'payroll_cost_by_entity');
        $this->assertSame('AVAILABLE', $payrollCard['status']);
        $this->assertSame(123456, $payrollCard['value']);
        $this->assertSame('元', $payrollCard['unitLabel']);

        $this->assertSame(1, ProjectionRow::query()
            ->where('business_date', self::BUSINESS_DATE)
            ->where('source_projection', 'payroll_cost')
            ->where('entity_type', 'PAYROLL_COST')
            ->where('numeric_value', 123456)
            ->count());
    }

    public function test_recruitment_funnel_counts_distinct_candidates_per_stage(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $this->app->instance(RecruitmentFunnelDirectory::class, new FakeRecruitmentFunnelDirectory([
            new RecruitmentFunnelRow('SCREENING', 3, (int) $ids['org_a_id']),
            new RecruitmentFunnelRow('INTERVIEW', 1, (int) $ids['org_a_id']),
        ]));

        $response = $this->getJson(
            '/v1/reporting/dashboard/metrics/recruitment_funnel?as_of='.self::BUSINESS_DATE,
        )->assertOk();

        $this->assertSame('AVAILABLE', $response->json('data.status'));
        $this->assertSame(4, $response->json('data.total'));
        $this->assertSame('PERSON', $response->json('data.unit'));
        $this->assertSame('人', $response->json('data.unitLabel'));
        $this->assertSame(2, count((array) $response->json('data.items')));

        $items = (array) $response->json('data.items');
        $this->assertSame('筛选', $items[0]['primary']);
        $this->assertSame('面试', $items[1]['primary']);
        $this->assertSame(3, $items[0]['value']);
        $this->assertSame(1, $items[1]['value']);

        $dashboard = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');

        $recruitmentCard = $this->metric($dashboard, 'recruitment_funnel');
        $this->assertSame(4, $recruitmentCard['value']);
        $this->assertSame('AVAILABLE', $recruitmentCard['status']);
        $this->assertSame([
            ['stage' => 'SCREENING', 'label' => '筛选', 'count' => 3],
            ['stage' => 'INTERVIEW', 'label' => '面试', 'count' => 1],
        ], array_map(static fn (array $row): array => [
            'stage' => $row['stage'],
            'label' => $row['label'],
            'count' => $row['count'],
        ], $dashboard['sections']['recruitment']));
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function metric(array $dashboard, string $code): array
    {
        foreach ((array) $dashboard['metrics'] as $metric) {
            if (($metric['code'] ?? null) === $code) {
                return $metric;
            }
        }

        $this->fail("Dashboard metric {$code} is missing.");
    }

    public function test_metric_endpoint_matches_dashboard_card_and_drilldown_scope(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $this->app->instance(PayrollCostDirectory::class, new FakePayrollCostDirectory([
            new PayrollCostRow(1000, 900, 800, 'CNY'),
        ]));
        $this->app->instance(RecruitmentFunnelDirectory::class, new FakeRecruitmentFunnelDirectory([
            new RecruitmentFunnelRow('INTERVIEW', 2, null),
        ]));

        $cards = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');
        $this->assertSame(800, $this->metric($cards, 'payroll_cost_by_entity')['value']);
        $this->assertSame(2, $this->metric($cards, 'recruitment_funnel')['value']);
        $this->assertSame(800, $this->getJson('/v1/reporting/dashboard/metrics/payroll_cost_by_entity?as_of='.self::BUSINESS_DATE)
            ->assertOk()->json('data.total'));
        $this->assertSame(2, $this->getJson('/v1/reporting/dashboard/metrics/recruitment_funnel?as_of='.self::BUSINESS_DATE)
            ->assertOk()->json('data.total'));

        $this->app->make(ProjectionBuilder::class)->rebuild(self::BUSINESS_DATE);

        $this->assertSame(2, ProjectionRow::query()
            ->where('business_date', self::BUSINESS_DATE)
            ->whereIn('source_projection', ['payroll_cost', 'recruitment_funnel'])
            ->where('entity_type', '!=', 'SOURCE_SYNC')
            ->count());
    }
}

final class FakePayrollCostDirectory implements PayrollCostDirectory
{
    /** @param list<PayrollCostRow> $rows */
    public function __construct(private readonly array $rows) {}

    public function available(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    /** @return list<PayrollCostRow> */
    public function costByEntity(string $asOf): array
    {
        return $this->rows;
    }
}

final class FakeRecruitmentFunnelDirectory implements RecruitmentFunnelDirectory
{
    /** @param list<RecruitmentFunnelRow> $rows */
    public function __construct(private readonly array $rows) {}

    public function available(): bool
    {
        return true;
    }

    public function unavailableReason(): string
    {
        return '';
    }

    /** @return list<RecruitmentFunnelRow> */
    public function funnel(string $asOf): array
    {
        return $this->rows;
    }
}
