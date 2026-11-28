<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\Payroll\Contracts\PayrollCostDirectory;
use App\Modules\Payroll\Contracts\PayrollCostRow;
use App\Modules\Reporting\Models\ProjectionRow;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Support\Facades\Cache;

/**
 * T16 report reads are not merely masked: a field that the caller's profile
 * does not cover must be absent from columns, row attributes, aggregates and
 * exports. Missing money must never reappear as null or through totals.
 */
final class ReportFieldProjectionTest extends ReportingTestCase
{
    public function test_report_columns_and_attributes_are_filtered_by_live_field_profile(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->revokeToProfile($ids['broad_user']->id, 'reporting.report.read', FieldProfile::P1_INTERNAL);
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $this->app->instance(PayrollCostDirectory::class, new FieldProjectionPayrollDirectory([
            new PayrollCostRow(200000, 150000, 123456, 'CNY'),
        ]));

        $result = $this->postJson('/v1/reporting/run', [
            'definition_code' => 'PAYROLL_COST',
            'as_of' => self::BUSINESS_DATE,
            'filters' => [],
        ])->assertOk()->json('data');

        $this->assertSame(['employee', 'currency_code'], array_column($result['columns'], 'key'));
        $this->assertNotEmpty($result['items']);
        $this->assertArrayHasKey('employee', $result['items'][0]);
        $this->assertArrayHasKey('currency_code', $result['items'][0]);
        foreach (['gross_cents', 'net_cents', 'employer_cost_cents', 'salary'] as $key) {
            $this->assertArrayNotHasKey($key, $result['items'][0]);
        }
        $this->assertSame(0, (int) $result['total']);
        $this->assertSame(count($result['items']), (int) $result['totalRows']);
        $this->assertArrayNotHasKey('gross_cents', (array) $result['chart']);
    }

    public function test_aggregates_do_not_reconstruct_missing_money(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->revokeToProfile($ids['broad_user']->id, 'reporting.dashboard.read', FieldProfile::P1_INTERNAL);
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $this->app->instance(PayrollCostDirectory::class, new FieldProjectionPayrollDirectory([
            new PayrollCostRow(200000, 150000, 123456, 'CNY'),
        ]));

        $payload = $this->getJson('/v1/reporting/dashboard/metrics/payroll_cost_by_entity?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');

        $this->assertSame('FORBIDDEN', $payload['status']);
        $this->assertSame(0, $payload['total']);
        $this->assertStringContainsString('金额', (string) $payload['reason']);
        $this->assertSame([], $payload['items']);
        $this->assertSame([], $payload['columns']);
        $dashboard = $this->getJson('/v1/reporting/dashboard?as_of='.self::BUSINESS_DATE)
            ->assertOk()
            ->json('data');
        $card = $this->metric($dashboard, 'payroll_cost_by_entity');
        $this->assertSame('FORBIDDEN', $card['status']);
        $this->assertSame(0, $card['value']);
        $this->assertArrayNotHasKey('gross_cents', (array) $card);
        $this->assertSame([], $card['preview']);
    }

    public function test_export_reauthorizes_and_omits_uncovered_money_columns(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->revokeToProfile($ids['broad_user']->id, 'reporting.export', FieldProfile::P1_INTERNAL);
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $this->app->instance(PayrollCostDirectory::class, new FieldProjectionPayrollDirectory([
            new PayrollCostRow(200000, 150000, 123456, 'CNY'),
        ]));

        $this->postExport('PAYROLL_COST', [
            'fields' => ['gross_cents', 'net_cents', 'currency_code'],
        ])->assertStatus(422)
            ->assertJsonPath('data.reason', 'EXPORT_FIELD_FORBIDDEN');

        $this->assertSame(0, ProjectionRow::query()->where('source_projection', 'payroll_cost')->count());

        // The same request under a P2 grant may export, but the P3/P2 money
        // boundary still produces a whitelist even when the caller asks twice.
        $this->revokeToProfile($ids['broad_user']->id, 'reporting.export', FieldProfile::P2_SENSITIVE);
        $created = $this->postExport('PAYROLL_COST', [
            'fields' => ['gross_cents', 'currency_code'],
        ])->assertAccepted()->json('data');
        $this->assertSame(['gross_cents', 'currency_code'], $created['fields']);
    }

    public function test_report_and_detail_share_exactly_the_same_surviving_cells(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $this->app->instance(PayrollCostDirectory::class, new FieldProjectionPayrollDirectory([
            new PayrollCostRow(200000, 150000, 123456, 'CNY'),
        ]));

        $detail = $this->postJson('/v1/reporting/run', [
            'definition_code' => 'PAYROLL_COST',
            'as_of' => self::BUSINESS_DATE,
            'filters' => [],
        ])->assertOk()->json('data');

        $this->assertSame(
            array_map(static fn (array $row): array => array_keys($row), $detail['items']),
            array_fill(0, count($detail['items']), array_column($detail['columns'], 'key')),
        );

        $this->assertSame(count($detail['items']), $detail['totalRows']);
        $this->assertSame(
            array_sum(array_map(
                static fn (array $row): int => (int) $row['employer_cost_cents'],
                $detail['items'],
            )),
            $detail['total'],
        );
    }

    private function revokeToProfile(int $userId, string $capability, FieldProfile $profile): void
    {
        AuthorityBinding::query()
            ->where('principal_id', $userId)
            ->where('capability_code', $capability)
            ->delete();
        Cache::flush();
        $user = \App\Modules\IAM\Models\User::query()->whereKey($userId)->firstOrFail();
        $this->grant(
            $user,
            $capability,
            AnchorType::COMPANY,
            null,
            $profile,
        );
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function metric(array $dashboard, string $code): array
    {
        foreach ((array) $dashboard['metrics'] as $metric) {
            if (($metric['code'] ?? null) === $code) {
                return (array) $metric;
            }
        }

        $this->fail("Dashboard metric {$code} is missing.");
    }
}

final class FieldProjectionPayrollDirectory implements PayrollCostDirectory
{
    /**
     * @param  list<PayrollCostRow>  $rows
     */
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
