<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Payroll\Models\BenefitEnrollment;
use App\Modules\Payroll\Models\BenefitPlan;
use App\Shared\Architecture\ModuleBoundaryScanner;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Support\Facades\DB;
use Tests\Support\PeopleTestCase;

/**
 * Benefits and statutory enrollment acceptance tests.
 *
 * Amounts are integer minor units. Rates are exact decimal strings at scale
 * >= 6. Enrollment base changes append history and never rewrite a prior row.
 */
final class BenefitTest extends PeopleTestCase
{
    public function test_plan_versions_and_enrollment_base_changes_are_append_only(): void
    {
        $this->benefitEmployment();

        $firstPlan = $this->postJson('/v1/benefits/plans', [
            'code' => 'SOCIAL_INSURANCE',
            'name' => 'Social insurance',
            'employer_rate' => '0.160000',
            'employee_rate' => '0.080000',
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-plan-1'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SOCIAL_INSURANCE')
            ->assertJsonPath('data.version', 1)
            ->json('data');

        $this->postJson('/v1/benefits/plans', [
            'code' => 'SOCIAL_INSURANCE',
            'name' => 'Social insurance',
            'employer_rate' => '0.170000',
            'employee_rate' => '0.090000',
            'effective_from' => '2026-07-01',
        ], ['Idempotency-Key' => 'benefit-plan-2'])
            ->assertCreated()
            ->assertJsonPath('data.version', 2);

        $this->assertSame(2, BenefitPlan::query()->count());
        $this->assertSame('0.160000', (string) BenefitPlan::query()->whereKey($firstPlan['id'])->value('employer_rate'));

        $planId = (int) $firstPlan['id'];

        $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => $planId,
            'employment_id' => $this->benefitEmploymentId,
            'base_amount_cents' => 1_000_000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-enrollment-1'])
            ->assertCreated()
            ->assertJsonPath('data.base_amount_cents', 1_000_000)
            ->assertJsonPath('data.employee_contribution_cents', 80_000)
            ->assertJsonPath('data.employer_contribution_cents', 160_000)
            ->assertJsonPath('data.version', 1);

        $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => $planId,
            'employment_id' => $this->benefitEmploymentId,
            'base_amount_cents' => 1_200_000,
            'effective_from' => '2026-07-01',
        ], ['Idempotency-Key' => 'benefit-enrollment-2'])
            ->assertCreated()
            ->assertJsonPath('data.base_amount_cents', 1_200_000)
            ->assertJsonPath('data.employee_contribution_cents', 96_000)
            ->assertJsonPath('data.employer_contribution_cents', 192_000)
            ->assertJsonPath('data.version', 2);

        $this->assertSame(2, BenefitEnrollment::query()->count());
        $this->assertSame([1_000_000, 1_200_000], BenefitEnrollment::query()
            ->orderBy('effective_from')
            ->pluck('base_amount_cents')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all());
    }

    public function test_rates_below_six_decimal_places_and_fractional_cents_are_rejected(): void
    {
        $this->benefitEmployment();

        $this->postJson('/v1/benefits/plans', [
            'code' => 'HOUSING_FUND',
            'name' => 'Housing fund',
            'employer_rate' => '0.07',
            'employee_rate' => '0.070000',
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-rate-invalid'])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'BENEFIT_RATE_SCALE');

        $planId = (int) $this->postJson('/v1/benefits/plans', [
            'code' => 'SOCIAL_INSURANCE',
            'name' => 'Social insurance',
            'employer_rate' => '0.160000',
            'employee_rate' => '0.080000',
        ], ['Idempotency-Key' => 'benefit-plan-for-cents'])->json('data.id');

        $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => $planId,
            'employment_id' => $this->benefitEmploymentId,
            'base_amount_cents' => 100_000.5,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-fractional-cents'])
            ->assertStatus(422);
    }

    public function test_multiple_plans_and_periods_can_share_a_payroll_run_but_duplicate_record_is_rejected(): void
    {
        $this->benefitEmployment();

        $planId = (int) $this->postJson('/v1/benefits/plans', [
            'code' => 'SOCIAL_INSURANCE',
            'name' => 'Social insurance',
            'employer_rate' => '0.160000',
            'employee_rate' => '0.080000',
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-plan-tx'])->json('data.id');

        $enrollmentId = (int) $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => $planId,
            'employment_id' => $this->benefitEmploymentId,
            'base_amount_cents' => 1_000_000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-enrollment-tx'])->json('data.id');

        $this->postJson('/v1/benefits/transactions', [
            'enrollment_id' => $enrollmentId,
            'period' => '2026-01',
            'payroll_run_id' => 77,
        ], ['Idempotency-Key' => 'benefit-transaction-1'])
            ->assertCreated()
            ->assertJsonPath('data.period', '2026-01')
            ->assertJsonPath('data.employee_amount_cents', 80_000)
            ->assertJsonPath('data.employer_amount_cents', 160_000)
            ->assertJsonPath('data.total_amount_cents', 240_000)
            ->assertJsonPath('data.payroll_run_id', 77)
            ->assertJsonPath('data.entry_type', 'RECORD');

        $this->postJson('/v1/benefits/transactions', [
            'enrollment_id' => $enrollmentId,
            'period' => '2026-01',
            'payroll_run_id' => 77,
        ], ['Idempotency-Key' => 'benefit-transaction-duplicate'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'BENEFIT_PERIOD_DUPLICATE');

        $secondPlanId = (int) $this->postJson('/v1/benefits/plans', [
            'code' => 'SUPPLEMENTARY_MEDICAL',
            'name' => 'Supplementary medical',
            'employer_rate' => '0.030000',
            'employee_rate' => '0.020000',
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-plan-tx-2'])->json('data.id');
        $secondEnrollmentId = (int) $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => $secondPlanId,
            'employment_id' => $this->benefitEmploymentId,
            'base_amount_cents' => 1_000_000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-enrollment-tx-2'])->json('data.id');

        $this->postJson('/v1/benefits/transactions', [
            'enrollment_id' => $secondEnrollmentId,
            'period' => '2026-01',
            'payroll_run_id' => 77,
        ], ['Idempotency-Key' => 'benefit-transaction-2-plan'])
            ->assertCreated()
            ->assertJsonPath('data.employee_amount_cents', 20_000)
            ->assertJsonPath('data.employer_amount_cents', 30_000);

        $this->postJson('/v1/benefits/transactions', [
            'enrollment_id' => $enrollmentId,
            'period' => '2026-02',
            'payroll_run_id' => 77,
        ], ['Idempotency-Key' => 'benefit-transaction-2'])
            ->assertCreated()
            ->assertJsonPath('data.period', '2026-02');

        $this->getJson('/v1/benefits/transactions?employment_id='.$this->benefitEmploymentId)
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash', 'partial', 'partial_reason', 'drilldown']])
            ->assertJsonPath('data.total', 3);
    }

    public function test_write_requires_idempotency_and_plan_catalogue_is_instance_wide(): void
    {
        $this->benefitEmployment();

        $this->postJson('/v1/benefits/plans', [
            'code' => 'SOCIAL_INSURANCE',
            'name' => 'Social insurance',
            'employer_rate' => '0.160000',
            'employee_rate' => '0.080000',
            'effective_from' => '2026-01-01',
        ])->assertStatus(422)->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        BenefitPlan::query()->create([
            'code' => 'MANUAL_PLAN',
            'name' => 'Manually recorded plan',
            'employer_rate' => '0.100000',
            'employee_rate' => '0.100000',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'version' => 1,
            'created_by' => 1,
            'created_at' => $this->clock->now(),
        ]);

        $this->getJson('/v1/benefits/plans')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonFragment(['code' => 'MANUAL_PLAN']);
    }

    public function test_benefit_amounts_are_masked_when_the_caller_lacks_p3(): void
    {
        $this->benefitEmployment();
        $planId = (int) $this->postJson('/v1/benefits/plans', [
            'code' => 'MEDICAL_P3',
            'name' => 'Medical P3',
            'employer_rate' => '0.100000',
            'employee_rate' => '0.050000',
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-p3-plan'])->json('data.id');
        $enrollmentId = (int) $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => $planId,
            'employment_id' => $this->benefitEmploymentId,
            'base_amount_cents' => 1_000_000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-p3-enrollment'])->json('data.id');
        $this->postJson('/v1/benefits/transactions', [
            'enrollment_id' => $enrollmentId,
            'period' => '2026-01',
        ], ['Idempotency-Key' => 'benefit-p3-transaction'])->assertCreated();

        $this->authorizer->fieldProfiles['benefit.read'] = FieldProfile::P2_SENSITIVE;

        $enrollments = $this->getJson('/v1/benefits/enrollments')->assertOk();
        $this->assertArrayNotHasKey('base_amount_cents', $enrollments->json('data.items.0'));
        $this->assertArrayNotHasKey('employee_contribution_cents', $enrollments->json('data.items.0'));

        $transactions = $this->getJson('/v1/benefits/transactions')->assertOk();
        $this->assertArrayNotHasKey('base_amount_cents', $transactions->json('data.items.0'));
        $this->assertArrayNotHasKey('employee_amount_cents', $transactions->json('data.items.0'));
        $this->assertArrayNotHasKey('employer_amount_cents', $transactions->json('data.items.0'));
        $this->assertArrayNotHasKey('total_amount_cents', $transactions->json('data.items.0'));
    }

    public function test_benefit_tables_have_one_declared_owner(): void
    {
        $owners = ModuleBoundaryScanner::TABLE_OWNERS['Payroll'];

        foreach (['benefit_plans', 'benefit_enrollments', 'benefit_transactions'] as $table) {
            $this->assertContains($table, $owners);
        }
    }

    private int $benefitEmploymentId;

    private function benefitEmployment(): void
    {
        $personId = (int) DB::table('persons')->insertGetId([
            'legal_name' => 'Synthetic Benefit Employee',
            'birth_date' => '1990-01-01',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->benefitEmploymentId = (int) DB::table('employments')->insertGetId([
            'person_id' => $personId,
            'employee_no' => 'E-BENEFIT',
            'employment_type' => 'FULL_TIME',
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'hire_date' => '2026-01-01',
            'seniority_start_date' => '2026-01-01',
            'recorded_at' => now('UTC'),
            'source_type' => 'TEST',
        ]);

        $this->authorizer->fieldProfiles['benefit.read'] = FieldProfile::P3_RESTRICTED;
        $this->actAs(userId: 1001, employmentId: $this->benefitEmploymentId);
    }
}
