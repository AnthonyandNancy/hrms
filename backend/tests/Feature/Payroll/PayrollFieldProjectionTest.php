<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldProfile;
use Tests\Support\AllowAllAuthorizer;
use Tests\Support\PayrollTestCase;

final class PayrollFieldProjectionTest extends PayrollTestCase
{
    public function test_a_run_reader_without_payslip_rights_sees_no_amounts(): void
    {
        $run = $this->calculatedRunForProjection();
        /** @var AllowAllAuthorizer $authorizer */
        $authorizer = app(Authorizer::class);
        $authorizer->fieldProfiles['payroll.payslip.read'] = FieldProfile::P2_SENSITIVE;

        $response = $this->getJson('/v1/payroll/runs/'.(int) $run->id.'/employees', $this->payrollHeaders())->assertOk();

        $this->assertNotEmpty($response->json('data.items'));

        foreach ($response->json('data.items') as $row) {
            $this->assertArrayNotHasKey('gross_cents', $row);
            $this->assertArrayNotHasKey('net_cents', $row);
            $this->assertArrayHasKey('employment_id', $row);
            $this->assertArrayHasKey('status', $row);
        }
    }

    public function test_a_p3_payslip_reader_sees_amounts(): void
    {
        $run = $this->calculatedRunForProjection();
        /** @var AllowAllAuthorizer $authorizer */
        $authorizer = app(Authorizer::class);
        $authorizer->fieldProfiles['payroll.payslip.read'] = FieldProfile::P3_RESTRICTED;

        $response = $this->getJson('/v1/payroll/runs/'.(int) $run->id.'/employees', $this->payrollHeaders())->assertOk();

        $this->assertNotEmpty($response->json('data.items'));
        $this->assertArrayHasKey('gross_cents', $response->json('data.items.0'));
        $this->assertArrayHasKey('net_cents', $response->json('data.items.0'));
    }

    public function test_a_run_reader_without_any_payslip_grant_gets_no_money_keys(): void
    {
        $run = $this->calculatedRunForProjection();

        $response = $this->getJson('/v1/payroll/runs/'.(int) $run->id.'/employees', $this->payrollHeaders())->assertOk();

        foreach ($response->json('data.items') as $row) {
            $this->assertArrayNotHasKey('gross_cents', $row);
            $this->assertArrayNotHasKey('net_cents', $row);
        }
    }

    private function calculatedRunForProjection(): \App\Modules\Payroll\Models\PayrollRun
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($this->tenantId, 10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-10',
        ]);
        $run = $runs->lockInputs($this->tenantId, (int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return $run->refresh();
    }

    /**
     * @return array<string, string>
     */
    public function test_run_detail_omits_amounts_for_a_p2_only_reader(): void
    {
        $run = $this->calculatedRunForProjection();
        /** @var AllowAllAuthorizer $authorizer */
        $authorizer = app(Authorizer::class);
        $authorizer->fieldProfiles['payroll.payslip.read'] = FieldProfile::P2_SENSITIVE;
        // canSeeAmounts uses permits(); deny the capability so the run summary
        // is still visible (run.read) but payslip amounts are not.
        $authorizer->deniedCapabilities = ['payroll.payslip.read'];

        $response = $this->getJson('/v1/payroll/runs/'.(int) $run->id, $this->payrollHeaders())->assertOk();

        $this->assertArrayNotHasKey('gross_cents', $response->json('data'));
        $this->assertArrayNotHasKey('net_cents', $response->json('data'));
        $this->assertArrayHasKey('status', $response->json('data'));
    }

    private function payrollHeaders(): array
    {
        return [
            'X-Legal-Entity-Id' => (string) $this->legalEntityId,
            'X-CSRF-Token' => 'test-csrf-token',
        ];
    }
}
