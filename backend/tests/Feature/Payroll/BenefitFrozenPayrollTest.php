<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\BenefitPlan;
use App\Modules\Payroll\Models\BenefitTransaction;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use Tests\Support\PayrollTestCase;

/**
 * A benefit ledger row may reference an in-flight payroll run, but never a
 * run whose financial facts are already published, paid or closed.
 */
final class BenefitFrozenPayrollTest extends PayrollTestCase
{
    public function test_transaction_cannot_link_to_published_payroll_run_or_be_rewritten(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->publishedRun((int) $base['scheme']->id, '2026-11');
        $enrollmentId = $this->benefitEnrollment((int) $base['employment_id']);

        $this->postJson('/v1/benefits/transactions', [
            'enrollment_id' => $enrollmentId,
            'period' => '2026-11',
            'payroll_run_id' => (int) $run->id,
        ], ['Idempotency-Key' => 'benefit-frozen-run'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT')
            ->assertJsonPath('data.reason', 'BENEFIT_PAYROLL_RUN_FROZEN')
            ->assertJsonPath('data.payroll_run_id', (int) $run->id);

        $this->assertSame(0, BenefitTransaction::query()->count());
    }

    public function test_transaction_still_accepts_an_optional_unlinked_or_unknown_run_reference(): void
    {
        $base = $this->seedPayrollBase();
        $enrollmentId = $this->benefitEnrollment((int) $base['employment_id']);

        $this->postJson('/v1/benefits/transactions', [
            'enrollment_id' => $enrollmentId,
            'period' => '2026-11',
        ], ['Idempotency-Key' => 'benefit-unlinked-run'])
            ->assertCreated()
            ->assertJsonPath('data.payroll_run_id', null)
            ->assertJsonPath('data.employee_amount_cents', 80_000)
            ->assertJsonPath('data.employer_amount_cents', 160_000);
    }

    private function benefitEnrollment(int $employmentId): int
    {
        $planId = (int) $this->postJson('/v1/benefits/plans', [
            'code' => 'SOCIAL_INSURANCE',
            'name' => 'Synthetic social insurance',
            'employer_rate' => '0.160000',
            'employee_rate' => '0.080000',
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-frozen-plan'])->assertCreated()->json('data.id');

        $this->assertSame(1, BenefitPlan::query()->count());

        return (int) $this->postJson('/v1/benefits/enrollments', [
            'plan_id' => $planId,
            'employment_id' => $employmentId,
            'base_amount_cents' => 1_000_000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'benefit-frozen-enrollment'])->assertCreated()->json('data.id');
    }

    private function publishedRun(int $schemeId, string $period): PayrollRun
    {
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => $schemeId, 'period' => $period]);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        $approval = app(ApprovalService::class);
        $approval->review((int) $run->id, 11);
        $approval->approve((int) $run->id, 12);

        return $approval->publish((int) $run->id, 13);
    }
}
