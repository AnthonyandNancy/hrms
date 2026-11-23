<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Engagement\Models\Notice;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Models\ProjectionRow;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\PeopleTestCase;

/**
 * The dashboard panels are previews of the exact authorized projection rows
 * used by the KPI queries. They must stay bounded and must never widen scope.
 */
final class DashboardPanelsTest extends PeopleTestCase
{
    public function test_dashboard_panels_are_bounded_previews_of_the_metric_rows_with_a_thirty_day_contract_window(): void
    {
        foreach (range(1, 7) as $index) {
            $this->approvalRow(1000 + $index, $index);
        }

        $contractIds = [];

        foreach (range(0, 7) as $index) {
            $contractIds[] = $this->contractRow(2000 + $index, $index);
        }

        // Outside the 30-day window: these must appear in neither the metric
        // nor the panel even though the projection stores a 90-day horizon.
        $this->contractRow(3001, 31);
        $this->contractRow(3002, 60);
        $this->contractRow(3003, 90);

        $response = $this->getJson('/v1/reporting/dashboard?as_of=2026-11-23');

        $response->assertOk()
            ->assertJsonCount(5, 'data.pendingApprovals')
            ->assertJsonCount(5, 'data.contractAlerts')
            ->assertJsonStructure([
                'data' => [
                    'pendingApprovals' => [[
                        'id',
                        'caseNo',
                        'title',
                        'status',
                        'ageDays',
                        'dueAt',
                    ]],
                    'contractAlerts' => [[
                        'employmentId',
                        'employeeName',
                        'orgName',
                        'endDate',
                        'daysRemaining',
                    ]],
                ],
            ]);

        $pendingMetric = $this->getJson('/v1/reporting/dashboard/metrics/pending_approvals?as_of=2026-11-23');
        $pendingMetric->assertOk()->assertJsonPath('data.total', 7);

        $contractMetric = $this->getJson('/v1/reporting/dashboard/metrics/contract_expiring?as_of=2026-11-23&window=30');
        $contractMetric->assertOk()->assertJsonPath('data.total', 8);

        $metricIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            (array) $contractMetric->json('data.rows'),
        );
        $panelIds = array_map(
            static fn (array $row): int => (int) $row['employmentId'],
            (array) $response->json('data.contractAlerts'),
        );

        $this->assertSame([], array_values(array_diff($panelIds, $metricIds)));
        $this->assertSame($contractIds[0], $panelIds[0]);

        foreach ((array) $response->json('data.contractAlerts') as $row) {
            $this->assertLessThanOrEqual(30, (int) $row['daysRemaining']);
            $this->assertGreaterThanOrEqual(0, (int) $row['daysRemaining']);
        }
    }

    public function test_dashboard_panels_apply_the_same_authorizer_constraint_as_the_metrics(): void
    {
        $this->app->instance(Authorizer::class, new OrgScopedProjectionAuthorizer(10));

        $this->approvalRow(4001, 1, orgUnitId: 10);
        $this->approvalRow(4002, 2, orgUnitId: 20);
        $this->contractRow(4003, 5, orgUnitId: 10);
        $this->contractRow(4004, 6, orgUnitId: 20);

        $response = $this->getJson('/v1/reporting/dashboard?as_of=2026-11-23');

        $response->assertOk()
            ->assertJsonCount(1, 'data.pendingApprovals')
            ->assertJsonPath('data.pendingApprovals.0.id', 4001)
            ->assertJsonCount(1, 'data.contractAlerts')
            ->assertJsonPath('data.contractAlerts.0.employmentId', 4003);

        $this->assertSame(1, collect((array) $response->json('data.metrics'))
            ->firstWhere('code', 'pending_approvals')['value']);
        $this->assertSame(1, collect((array) $response->json('data.metrics'))
            ->firstWhere('code', 'contract_expiring')['value']);
    }

    public function test_recent_notices_require_notice_read_and_are_published_and_bounded(): void
    {
        foreach (range(1, 6) as $index) {
            $this->notice("Synthetic dashboard notice {$index}", '2026-11-22T0'.$index.':00:00Z');
        }

        $this->notice('Synthetic draft must stay hidden', '2026-11-22T08:00:00Z', Notice::STATUS_DRAFT);
        $this->notice('Synthetic future notice must stay hidden', '2026-11-24T08:00:00Z');

        $allowed = $this->getJson('/v1/reporting/dashboard?as_of=2026-11-23');
        $allowed->assertOk()->assertJsonCount(5, 'data.recentNotices');

        $titles = array_column((array) $allowed->json('data.recentNotices'), 'title');
        $this->assertNotContains('Synthetic draft must stay hidden', $titles);
        $this->assertNotContains('Synthetic future notice must stay hidden', $titles);

        $this->authorizer->deniedCapabilities = ['engagement.notice.read'];

        $denied = $this->getJson('/v1/reporting/dashboard?as_of=2026-11-23');
        $denied->assertOk()->assertJsonCount(0, 'data.recentNotices');
    }

    private function approvalRow(int $caseId, int $ageDays, int $orgUnitId = 10): ProjectionRow
    {
        return ProjectionRow::query()->create([
            'tenant_id' => 1,
            'source_projection' => 'pending_approvals',
            'business_date' => '2026-11-23',
            'metric_code' => 'pending_approvals',
            'entity_type' => 'CASE',
            'entity_id' => (string) $caseId,
            'employment_id' => 5000 + $caseId,
            'org_unit_id' => $orgUnitId,
            'legal_entity_id' => null,
            'label' => 'Synthetic approval case '.$caseId,
            'secondary_label' => 'CASE-'.$caseId,
            'status' => 'PENDING',
            'numeric_value' => $ageDays,
            'attributes_json' => [
                'case_no' => 'CASE-'.$caseId,
                'title' => 'Synthetic approval case '.$caseId,
                'status' => 'PENDING',
                'age_days' => $ageDays,
                'node_key' => 'approval',
                'due_at' => '2026-11-24T01:00:00Z',
            ],
            'source_hash' => str_repeat('a', 64),
            'computed_at' => $this->clock->now(),
        ]);
    }

    private function contractRow(int $employmentId, int $daysRemaining, int $orgUnitId = 10): int
    {
        $endDate = \Carbon\CarbonImmutable::parse('2026-11-23', 'UTC')
            ->addDays($daysRemaining)
            ->toDateString();

        ProjectionRow::query()->create([
            'tenant_id' => 1,
            'source_projection' => 'workforce',
            'business_date' => '2026-11-23',
            'metric_code' => 'contract_expiring',
            'entity_type' => 'CONTRACT',
            'entity_id' => (string) $employmentId,
            'employment_id' => $employmentId,
            'org_unit_id' => $orgUnitId,
            'legal_entity_id' => 1,
            'label' => 'Synthetic Employee '.$employmentId,
            'secondary_label' => 'Demo Org · DEMO-'.$employmentId,
            'status' => 'EXPIRING',
            'numeric_value' => $daysRemaining,
            'attributes_json' => [
                'employee' => 'Synthetic Employee '.$employmentId,
                'org_path' => 'Demo Org',
                'end_date' => $endDate,
                'days_remaining' => $daysRemaining,
                'status' => 'EXPIRING',
            ],
            'source_hash' => str_repeat('b', 64),
            'computed_at' => $this->clock->now(),
        ]);

        return $employmentId;
    }

    private function notice(string $title, string $publishedAt, string $status = Notice::STATUS_PUBLISHED): Notice
    {
        return Notice::query()->create([
            'tenant_id' => 1,
            'title' => $title,
            'body' => 'Synthetic dashboard notice body.',
            'audience_json' => ['type' => 'ALL', 'label' => 'All employees'],
            'publish_at' => $publishedAt,
            'retract_at' => null,
            'created_by' => 9001,
            'status' => $status,
            'requires_ack' => false,
            'audience_count' => 1,
        ]);
    }
}

/**
 * Test-only authorizer that narrows the reporting projection to one org unit
 * while allowing the route capability.
 */
final class OrgScopedProjectionAuthorizer implements Authorizer
{
    public function __construct(private readonly int $orgUnitId) {}

    public function constrain(Builder $query, Principal $p, string $capability, string $businessDate): Builder
    {
        return $query->where('org_unit_id', $this->orgUnitId);
    }

    public function permits(Principal $p, string $capability, string $businessDate): bool
    {
        return true;
    }

    public function allows(Principal $p, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        return true;
    }

    public function fields(Principal $p, string $capability, ResourceRef $row): FieldSet
    {
        return new FieldSet([]);
    }
}
