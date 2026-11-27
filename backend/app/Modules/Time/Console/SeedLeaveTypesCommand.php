<?php

declare(strict_types=1);

namespace App\Modules\Time\Console;

use App\Modules\Time\Models\LeaveType;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

/**
 * Reference leave-type catalog. A company with no leave types cannot open a
 * leave account or migrate opening balances, so this command is the supported
 * way to create the baseline catalog outside tests.
 *
 * The statutory annual entitlement is deliberately NOT invented here: it is a
 * per-jurisdiction decision (S-02 in the approved plan). Only the code, unit
 * and whether the type is statutory/annual are seeded; the entitlement must be
 * supplied via --annual-days or configured by an administrator.
 */
final class SeedLeaveTypesCommand extends Command
{
    protected $signature = 'time:seed-leave-types
        {--annual-days= : Statutory annual entitlement in days; required for the ANNUAL type}
        {--legal-basis= : Legal basis text recorded on statutory types}';

    protected $description = 'Create the reference leave-type catalog for the company (idempotent)';

    public function handle(Clock $clock): int
    {
        $annualDays = $this->option('annual-days');
        $legalBasis = $this->option('legal-basis');

        if ($annualDays !== null && ! preg_match('/^\d{1,3}(\.\d{1,2})?$/', (string) $annualDays)) {
            $this->error('--annual-days must be a non-negative decimal with at most two places.');

            return self::INVALID;
        }

        $catalog = [
            [
                'code' => 'ANNUAL',
                'name' => 'Annual leave',
                'is_statutory' => true,
                'is_annual' => true,
                'unit' => 'DAY',
                'annual_entitlement_days' => $annualDays !== null ? (string) $annualDays : null,
                'requires_attachment' => false,
                'allow_negative' => false,
                'legal_basis' => $legalBasis !== null ? (string) $legalBasis : null,
            ],
            [
                'code' => 'SICK',
                'name' => 'Sick leave',
                'is_statutory' => true,
                'is_annual' => false,
                'unit' => 'DAY',
                'annual_entitlement_days' => null,
                'requires_attachment' => true,
                'allow_negative' => false,
                'legal_basis' => $legalBasis !== null ? (string) $legalBasis : null,
            ],
            [
                'code' => 'PERSONAL',
                'name' => 'Personal leave',
                'is_statutory' => false,
                'is_annual' => false,
                'unit' => 'DAY',
                'annual_entitlement_days' => null,
                'requires_attachment' => false,
                'allow_negative' => true,
                'legal_basis' => null,
            ],
        ];

        $created = 0;

        foreach ($catalog as $definition) {
            $existing = LeaveType::query()
                ->where('code', $definition['code'])
                ->first();

            if ($existing !== null) {
                continue;
            }

            LeaveType::query()->create([
                ...$definition,
                'expiry_rule_json' => null,
                'version' => 1,
                'created_at' => $clock->now(),
                'updated_at' => $clock->now(),
            ]);
            $created++;
        }

        $this->info("Seeded {$created} leave type(s) for the company.");

        if ($created > 0 && $annualDays === null) {
            $this->warn('ANNUAL was created without an entitlement. Set it per jurisdiction (S-02) before granting balances.');
        }

        return self::SUCCESS;
    }
}
