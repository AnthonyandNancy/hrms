<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Models\StatutoryParameter;
use App\Modules\Payroll\Services\TaxParameterResolver;
use App\Shared\Exceptions\DomainException;
use Illuminate\Console\Command;

/**
 * Seeds synthetic DEMO parameters. These values are deliberately not legal
 * advice and must never be presented as authoritative for any jurisdiction.
 */
final class SeedPayrollParametersCommand extends Command
{
    protected $signature = 'payroll:seed-parameters
        {--legal-entity= : Legal entity id}
        {--year= : Tax year to seed}
        {--approved-by= : Approver user id (required evidence)}';

    protected $description = 'Seed DEMO-only, versioned statutory parameters for one legal entity';

    public function handle(TaxParameterResolver $parameters): int
    {
        $legalEntityId = (int) $this->option('legal-entity');
        $year = (int) $this->option('year');
        $approvedBy = (int) $this->option('approved-by');

        if ($legalEntityId < 1 || $year < 2000 || $year > 9999) {
            $this->error('Both --legal-entity and a four-digit --year are required.');

            return self::FAILURE;
        }

        if ($approvedBy < 1) {
            $this->error('--approved-by is required: DEMO parameters still record approval evidence.');

            return self::FAILURE;
        }

        $tenantId = \App\Shared\RequestContext::tenantId();
        $tenantId = $tenantId > 0 ? $tenantId : 1;

        // These are obviously synthetic placeholders, not any jurisdiction's
        // real tax or contribution schedule.
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $version = 'DEMO-'.$year.'-V1';
        $evidence = 'DEMO-SYNTHETIC-NOT-LEGAL-ADVICE';

        $already = StatutoryParameter::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('version', $version)
            ->exists();

        if ($already) {
            $this->info('DEMO parameters already exist for this legal entity and year.');

            return self::SUCCESS;
        }

        try {
            $parameters->createVersion($tenantId, $legalEntityId, [
                'parameter_set' => 'TAX',
                'version' => $version,
                'effective_from' => $from,
                'effective_to' => $to,
                'THRESHOLD_CENTS' => 500_000,
                'brackets' => [
                    ['lower_bound_cents' => 0, 'upper_bound_cents' => 1_000_000, 'rate_bps' => 3, 'quick_deduction_cents' => 0],
                    ['lower_bound_cents' => 1_000_000, 'upper_bound_cents' => 3_000_000, 'rate_bps' => 10, 'quick_deduction_cents' => 70_000],
                    ['lower_bound_cents' => 3_000_000, 'upper_bound_cents' => null, 'rate_bps' => 20, 'quick_deduction_cents' => 370_000],
                ],
            ], $approvedBy, true, $evidence);

            $parameters->createVersion($tenantId, $legalEntityId, [
                'parameter_set' => 'SOCIAL_INSURANCE',
                'version' => $version,
                'effective_from' => $from,
                'effective_to' => $to,
                'MIN_BASE_CENTS' => 400_000,
                'MAX_BASE_CENTS' => 3_000_000,
                'EMPLOYEE_RATE' => '8.000000',
                'EMPLOYER_RATE' => '16.000000',
            ], $approvedBy, true, $evidence);

            $parameters->createVersion($tenantId, $legalEntityId, [
                'parameter_set' => 'HOUSING_FUND',
                'version' => $version,
                'effective_from' => $from,
                'effective_to' => $to,
                'MIN_BASE_CENTS' => 400_000,
                'MAX_BASE_CENTS' => 3_000_000,
                'EMPLOYEE_RATE' => '7.000000',
                'EMPLOYER_RATE' => '7.000000',
            ], $approvedBy, true, $evidence);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('DEMO parameter seeding failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->warn('Seeded DEMO statutory parameters. These are synthetic values, not legal advice, and must not be used for real payroll.');

        return self::SUCCESS;
    }
}
