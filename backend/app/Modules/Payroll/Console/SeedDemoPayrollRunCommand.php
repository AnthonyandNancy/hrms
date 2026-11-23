<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Models\StatutoryParameter;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\People\Contracts\EmploymentTermsDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use Illuminate\Console\Command;

/**
 * Builds one synthetic, fully-calculated DEMO payroll run so that the
 * reconciliation and parallel-run gates have real rows to compare instead of
 * reporting "no calculated payroll runs matched".
 *
 * It deliberately stops at CALCULATED: review, approval, publish and mark-paid
 * are separation-of-duties steps that a demo seeder must not auto-sign.
 */
final class SeedDemoPayrollRunCommand extends Command
{
    protected $signature = 'payroll:seed-demo-run
        {--tenant=1 : Tenant id}
        {--legal-entity=1 : Legal entity id}
        {--period=2026-11 : Payroll period YYYY-MM}
        {--actor=1 : Acting user id recorded as the preparer}';

    protected $description = 'Create one synthetic CALCULATED DEMO payroll run (no approvals are auto-recorded)';

    public function handle(
        PayrollRunService $runs,
        CalculationService $calculator,
        PeopleDirectory $people,
        EmploymentTermsDirectory $terms,
    ): int {
        $tenantId = max(1, (int) $this->option('tenant'));
        $legalEntityId = max(1, (int) $this->option('legal-entity'));
        $period = (string) $this->option('period');
        $actor = max(1, (int) $this->option('actor'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            $this->error('--period must be YYYY-MM.');

            return self::INVALID;
        }

        $hasParameters = StatutoryParameter::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->exists();

        if (! $hasParameters) {
            $this->error('No statutory parameters exist for this legal entity. Run payroll:seed-parameters first.');

            return self::FAILURE;
        }

        // Payroll may not import People models (arch:test); resolve the eligible
        // population through People's published Contracts instead.
        $employmentIds = $people->employmentIdsForLegalEntities($tenantId, [$legalEntityId], $period.'-28');
        $withSalary = [];

        foreach (array_unique($employmentIds) as $employmentId) {
            $snapshot = $terms->termsAsOf($tenantId, (int) $employmentId, $period.'-28');

            if ($snapshot !== null && $snapshot->baseSalaryMinor !== null && $snapshot->baseSalaryMinor > 0) {
                $withSalary[] = (int) $employmentId;
            }
        }

        if ($withSalary === []) {
            $this->error('No employment with a positive base salary exists for this legal entity.');

            return self::FAILURE;
        }

        $scheme = PayrollScheme::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('status', 'ACTIVE')
            ->orderBy('version')
            ->first();

        if ($scheme === null) {
            $scheme = PayrollScheme::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => $legalEntityId,
                'code' => 'DEMO-MONTHLY',
                'name' => 'Synthetic demo monthly scheme',
                'frequency' => 'MONTHLY',
                'currency' => 'CNY',
                'version' => 1,
                'status' => 'ACTIVE',
                'valid_from' => '2026-01-01',
                'valid_to' => '9999-12-31',
            ]);
        }

        $existing = PayrollRun::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('scheme_id', $scheme->id)
            ->where('period', $period)
            ->first();

        if ($existing !== null) {
            $this->info("Demo payroll run for {$period} already exists (id={$existing->id}, status={$existing->status->value}).");

            return self::SUCCESS;
        }

        $run = $runs->createRun($tenantId, $actor, [
            'scheme_id' => (int) $scheme->id,
            'period' => $period,
        ]);

        $run = $runs->lockInputs($tenantId, (int) $run->id, $actor);
        $result = $calculator->calculate($run, $actor);
        $calculated = $run->refresh();

        $this->info(sprintf(
            'Created DEMO payroll run id=%d period=%s status=%s gross=%d net=%d calculated=%d failed=%d.',
            (int) $calculated->id,
            $period,
            $calculated->status->value,
            (int) $result['gross_cents'],
            (int) $result['net_cents'],
            (int) $result['calculated'],
            (int) $result['failed'],
        ));
        $this->warn('The run stops at CALCULATED. Review, approval, publish and mark-paid are separation-of-duties steps and are NOT auto-recorded.');

        return self::SUCCESS;
    }
}
