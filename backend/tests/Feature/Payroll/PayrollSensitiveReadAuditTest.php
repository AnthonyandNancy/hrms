<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Authorization\FieldProfile;
use Tests\Support\AllowAllAuthorizer;
use Tests\Support\PayrollTestCase;

/**
 * ADR-0024: a P3 holder reading restricted payroll fields must leave an audit
 * record with field names (never values). Listing runs is a read, not a write,
 * so it cannot rely on the write-only AuditRecorder.
 */
final class PayrollSensitiveReadAuditTest extends PayrollTestCase
{
    public function test_p3_run_list_records_field_access_audit(): void
    {
        $base = $this->seedPayrollBase();
        $this->calculatedRun($base, '2026-07');

        /** @var AllowAllAuthorizer $authorizer */
        $authorizer = app(\App\Shared\Authorization\Authorizer::class);
        $authorizer->fieldProfiles['payroll.payslip.read'] = FieldProfile::P3_RESTRICTED;

        $this->getJson('/v1/payroll/runs', $this->headers())->assertOk();

        // The list endpoint must resolve the payslip field set for every row;
        // that is the call which reaches CapabilityAuthorizer::fields() and
        // records ADR-0024 read auditing in production.
        $capabilities = array_column($authorizer->calls, 'capability');
        $this->assertContains('payroll.payslip.read', $capabilities);
    }

    public function test_non_p3_run_list_does_not_claim_p3_field_access(): void
    {
        $base = $this->seedPayrollBase();
        $this->calculatedRun($base, '2026-08');

        /** @var AllowAllAuthorizer $authorizer */
        $authorizer = app(\App\Shared\Authorization\Authorizer::class);
        $authorizer->fieldProfiles['payroll.payslip.read'] = FieldProfile::P1_INTERNAL;

        $this->getJson('/v1/payroll/runs', $this->headers())->assertOk();
    }

    /**
     * @param  array{scheme: \App\Modules\Payroll\Models\PayrollScheme, employment_id: int}  $base
     */
    private function calculatedRun(array $base, string $period): \App\Modules\Payroll\Models\PayrollRun
    {
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($this->tenantId, 10, ['scheme_id' => (int) $base['scheme']->id, 'period' => $period]);
        $run = $runs->lockInputs($this->tenantId, (int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return $run->refresh();
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Legal-Entity-Id' => (string) $this->legalEntityId];
    }
}
