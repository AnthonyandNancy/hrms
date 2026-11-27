<?php

declare(strict_types=1);

namespace App\Modules\Time\Console;

use App\Modules\Time\Models\LeavePolicyVersion;
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

        $policyCount = 0;

        foreach (LeaveType::query()->orderBy('id')->get() as $type) {
            $hasPolicy = LeavePolicyVersion::query()
                ->where('leave_type_id', (int) $type->id)
                ->exists();

            if ($hasPolicy) {
                continue;
            }

            $entitlement = $type->annual_entitlement_days;
            $minutesPerYear = 0;

            if ($entitlement !== null && is_numeric((string) $entitlement)) {
                $minutesPerYear = (int) round((float) $entitlement * 480);
            }

            LeavePolicyVersion::query()->create([
                'leave_type_id' => (int) $type->id,
                'version' => 1,
                'status' => 'PUBLISHED',
                'effective_from' => '2026-01-01',
                'effective_to' => '9999-12-31',
                'applies_to_json' => ['scope' => 'COMPANY'],
                'accrual_json' => ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => $minutesPerYear],
                'carryover_json' => null,
                'min_notice_days' => 0,
                'max_consecutive_days' => null,
                'requires_attachment_over_days' => $type->requires_attachment ? 1 : null,
                'allow_half_day' => true,
                'legal_basis' => $type->legal_basis,
                'published_at' => $clock->now(),
                'published_by' => 1,
                'created_at' => $clock->now(),
            ]);
            $policyCount++;
        }

        $this->info("Seeded {$created} leave type(s) and {$policyCount} default policy version(s) for the company.");

        if ($created > 0 && $annualDays === null) {
            $this->warn('ANNUAL was created without an entitlement. Set it per jurisdiction (S-02) before granting balances.');
        }

        return self::SUCCESS;
    }
}
