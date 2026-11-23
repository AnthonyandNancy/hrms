<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Shared\Exceptions\ValidationException;
use Carbon\CarbonImmutable;

/**
 * Statutory annual leave floor (D-17): cumulative service >=1y => 5 days,
 * >=10y => 10 days, >=20y => 15 days. Configuration cannot go below the
 * applicable floor for a statutory annual type.
 */
final class StatutoryAnnualLeaveFloor
{
    public function minimumDays(string $seniorityStartDate, string $asOf): int
    {
        $start = CarbonImmutable::parse($seniorityStartDate)->startOfDay();
        $on = CarbonImmutable::parse($asOf)->startOfDay();

        if ($on->lessThan($start)) {
            return 0;
        }

        $serviceYears = (int) $start->diffInYears($on);

        return match (true) {
            $serviceYears >= 20 => 15,
            $serviceYears >= 10 => 10,
            $serviceYears >= 1 => 5,
            default => 0,
        };
    }

    public function assertEntitlementAtLeastFloor(
        bool $isStatutory,
        string $configuredDays,
        string $seniorityStartDate,
        string $asOf,
    ): void {
        if (! $isStatutory) {
            return;
        }

        $floor = $this->minimumDays($seniorityStartDate, $asOf);
        $configuredHundredths = $this->humanDaysToHundredths($configuredDays);

        if ($configuredHundredths < $floor * 100) {
            throw new ValidationException([
                'annual_entitlement_days' => [
                    sprintf(
                        'Statutory annual leave cannot be below the legal floor of %d days for this service length.',
                        $floor,
                    ),
                ],
            ]);
        }
    }

    public function check(string $configuredDays, string $seniorityStartDate, string $asOf): void
    {
        $this->assertEntitlementAtLeastFloor(true, $configuredDays, $seniorityStartDate, $asOf);
    }

    private function humanDaysToHundredths(string $days): int
    {
        if (preg_match('/^\d+(\.\d{1,2})?$/', $days) !== 1) {
            throw new ValidationException(['annual_entitlement_days' => ['Days must be a non-negative decimal.']]);
        }

        [$whole, $fraction] = array_pad(explode('.', $days, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        return (int) $whole * 100 + (int) $fraction;
    }
}
