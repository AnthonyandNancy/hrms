<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\TaxParameterResolver;
use Tests\Support\PayrollTestCase;

/**
 * Statutory parameter versions are append-only facts. A later version must not
 * alter a published run or the parameter version recorded in its input snapshot.
 */
final class BenefitVersionHistoryTest extends PayrollTestCase
{
    public function test_new_parameter_version_does_not_change_published_run_or_snapshot_versions(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->publishedRun((int) $base['scheme']->id, '2026-11');

        $before = [
            'gross_cents' => (int) $run->gross_cents,
            'net_cents' => (int) $run->net_cents,
            'input_hash' => (string) $run->input_hash,
            'snapshot_count' => PayrollInputSnapshot::query()->where('run_id', $run->id)->count(),
        ];

        $snapshot = PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail();
        $lockedVersion = (string) $snapshot->payload_json['social_insurance_parameters_version'];
        $this->assertSame('DEMO-SOCIAL_INSURANCE-2026', $lockedVersion);

        app(TaxParameterResolver::class)->createVersion(
            [
                'parameter_set' => 'SOCIAL_INSURANCE',
                'version' => 'DEMO-SOCIAL_INSURANCE-2027',
                'effective_from' => '2027-01-01',
                'MIN_BASE_CENTS' => 450_000,
                'MAX_BASE_CENTS' => 3_200_000,
                'EMPLOYEE_RATE' => '8.000000',
                'EMPLOYER_RATE' => '16.000000',
            ],
            99,
            true,
            'DEMO-SYNTHETIC-NOT-LEGAL-ADVICE-2027',
        );

        $fresh = PayrollRun::query()->whereKey($run->id)->firstOrFail();
        $freshSnapshot = PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail();

        $this->assertSame($before['gross_cents'], (int) $fresh->gross_cents);
        $this->assertSame($before['net_cents'], (int) $fresh->net_cents);
        $this->assertSame($before['input_hash'], (string) $fresh->input_hash);
        $this->assertSame($before['snapshot_count'], PayrollInputSnapshot::query()->where('run_id', $run->id)->count());
        $this->assertSame($lockedVersion, (string) $freshSnapshot->payload_json['social_insurance_parameters_version']);
        $this->assertSame('DEMO-SOCIAL_INSURANCE-2026', app(TaxParameterResolver::class)->versionFor('SOCIAL_INSURANCE', '2026-11-23'));
        $this->assertSame('DEMO-SOCIAL_INSURANCE-2027', app(TaxParameterResolver::class)->versionFor('SOCIAL_INSURANCE', '2027-01-15'));
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
