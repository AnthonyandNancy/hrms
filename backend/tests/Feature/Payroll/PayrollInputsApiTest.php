<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldProfile;
use Tests\Support\AllowAllAuthorizer;
use Tests\Support\PayrollTestCase;

final class PayrollInputsApiTest extends PayrollTestCase
{
    public function test_run_inputs_return_snapshot_rows_for_the_company(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->calculatedRun($base, '2026-04');

        $snapshots = PayrollInputSnapshot::query()->where('run_id', $run->id)->get();
        $this->assertNotEmpty($snapshots);

        $response = $this->getJson('/v1/payroll/runs/'.(int) $run->id.'/inputs', $this->headers())->assertOk();
        $this->assertGreaterThanOrEqual(1, count($response->json('data.items')));
        $this->assertArrayHasKey('employment_id', $response->json('data.items.0'));
        $this->assertArrayHasKey('snapshot_type', $response->json('data.items.0'));
        $this->assertArrayHasKey('snapshot_version', $response->json('data.items.0'));
        $this->assertArrayHasKey('source_hash', $response->json('data.items.0'));
    }

    public function test_run_inputs_hide_amount_keys_without_p3_payslip_grant(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->calculatedRun($base, '2026-05');

        /** @var AllowAllAuthorizer $authorizer */
        $authorizer = app(Authorizer::class);
        $authorizer->fieldProfiles['payroll.payslip.read'] = FieldProfile::P2_SENSITIVE;

        $response = $this->getJson('/v1/payroll/runs/'.(int) $run->id.'/inputs', $this->headers())->assertOk();

        foreach ($response->json('data.items') as $row) {
            $payload = $row['payload'];
            $this->assertIsArray($payload);
            $this->assertArrayNotHasKey('gross_cents', $payload);
            $this->assertArrayNotHasKey('net_cents', $payload);
            $this->assertArrayNotHasKey('base_salary_minor', $payload);
        }
    }

    public function test_run_inputs_are_run_scoped(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->calculatedRun($base, '2026-06');

        $this->getJson('/v1/payroll/runs/999999/inputs', $this->headers())->assertNotFound();
    }

    /**
     * @param  array{scheme: \App\Modules\Payroll\Models\PayrollScheme, employment_id: int}  $base
     */
    private function calculatedRun(array $base, string $period): \App\Modules\Payroll\Models\PayrollRun
    {
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => $period]);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        return $run->refresh();
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [];
    }
}
