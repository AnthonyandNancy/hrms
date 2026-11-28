<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use Tests\Support\AllowAllAuthorizer;
use Tests\Support\PayrollTestCase;

final class PayrollHttpLifecycleTest extends PayrollTestCase
{
    public function test_create_lock_calculate_review_publish_flow_over_http(): void
    {
        $base = $this->seedPayrollBase();
        $schemeId = (int) $base['scheme']->id;

        $create = $this->postJson('/v1/payroll/runs', [
            'scheme_id' => $schemeId,
            'period' => '2026-01',
        ], $this->headers())->assertCreated();

        $runId = (int) $create->json('data.id');
        $this->assertSame('DRAFT', $create->json('data.status'));

        $this->postJson('/v1/payroll/runs/'.$runId.'/lock-inputs', [], $this->headers())->assertOk();
        $this->postJson('/v1/payroll/runs/'.$runId.'/calculate', [], $this->headers())->assertOk();

        $this->actAs(11);
        $this->postJson('/v1/payroll/runs/'.$runId.'/review', [], $this->headers())->assertOk();

        $this->actAs(12);
        $this->postJson('/v1/payroll/runs/'.$runId.'/approve', [], $this->headers())->assertOk();

        $this->actAs(13);
        $publish = $this->postJson('/v1/payroll/runs/'.$runId.'/publish', [], $this->headers())->assertOk();
        $this->assertSame('PUBLISHED', $publish->json('data.status'));
    }

    public function test_run_member_can_be_preassigned_over_http_and_is_projected_as_an_allowed_action(): void
    {
        $base = $this->seedPayrollBase();
        $run = app(PayrollRunService::class)->createRun(10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-09',
        ]);
        $this->registerRunMembership($run, 10);
        $this->actAs(10);

        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/members', [
            'user_id' => 11,
            'member_role' => 'REVIEWER',
            'reason' => 'Preassigned reviewer for four-eyes control.',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.member_role', 'REVIEWER')
            ->assertJsonPath('data.user_id', 11);

        $this->assertDatabaseHas('payroll_run_members', [
            'run_id' => (int) $run->id,
            'user_id' => 11,
            'member_role' => 'REVIEWER',
        ]);

        $detail = $this->getJson('/v1/payroll/runs/'.(int) $run->id, $this->headers())->assertOk();
        $this->assertContains('payroll.run.detail.assign-member', (array) $detail->json('data.allowed_actions'));
    }

    public function test_self_payslip_list_is_scoped_and_cross_employee_detail_is_404(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->publishedRun($base, '2026-02');

        $own = \App\Modules\Payroll\Models\Payslip::query()->where('run_id', $run->id)->firstOrFail();

        $this->actAs(20, (int) $own->employment_id);
        $list = $this->getJson('/v1/payroll/payslips', $this->headers())->assertOk()
            ->assertJsonPath('data.items.0.period', '2026-02')
            ->assertJsonPath('data.items.0.pay_date', '2026-02-28');
        $this->assertCount(1, $list->json('data.items'));

        $this->getJson('/v1/payroll/payslips/'.(int) $own->id, $this->headers())->assertOk();

        // Another employee id cannot read the same payslip.
        $this->actAs(21, 999_999);
        $this->getJson('/v1/payroll/payslips/'.(int) $own->id, $this->headers())->assertNotFound();
    }

    public function test_pre_publish_payslips_are_not_visible_to_self_service(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->calculatedRun($base, '2026-03');
        $payslip = \App\Modules\Payroll\Models\Payslip::query()->where('run_id', $run->id)->firstOrFail();

        $this->actAs(20, (int) $payslip->employment_id);
        $this->getJson('/v1/payroll/payslips/'.(int) $payslip->id, $this->headers())->assertNotFound();
        $this->getJson('/v1/payroll/payslips', $this->headers())->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_capability_denial_is_403(): void
    {
        $this->seedPayrollBase();

        /** @var AllowAllAuthorizer $authorizer */
        $authorizer = app(\App\Shared\Authorization\Authorizer::class);
        $authorizer->deniedCapabilities = ['payroll.run.read'];

        $this->getJson('/v1/payroll/runs', $this->headers())->assertForbidden();
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [];
    }

    /**
     * @param  array{scheme: \App\Modules\Payroll\Models\PayrollScheme, employment_id: int}  $base
     */
    private function calculatedRun(array $base, string $period): PayrollRun
    {
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => $period]);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return $run->refresh();
    }

    /**
     * @param  array{scheme: \App\Modules\Payroll\Models\PayrollScheme, employment_id: int}  $base
     */
    private function publishedRun(array $base, string $period): PayrollRun
    {
        $run = $this->calculatedRun($base, $period);
        $approval = app(ApprovalService::class);
        $approval->review((int) $run->id, 11);
        $approval->approve((int) $run->id, 12);

        return $approval->publish((int) $run->id, 13);
    }
}
