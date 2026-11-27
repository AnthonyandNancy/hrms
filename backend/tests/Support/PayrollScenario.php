<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payroll\Models\PayrollScheme;
use App\Shared\Time\Clock;

/**
 * Synthetic payroll fixtures. Base salary is deliberately round and fake.
 */
final class PayrollScenario
{
    private PeopleScenario $people;

    public function __construct(private readonly Clock $clock)
    {
        $this->people = new PeopleScenario($clock);
    }

    public function employment(string $employeeNo, int $baseSalaryCents): \App\Modules\People\Models\Employment
    {
        $existing = \App\Modules\People\Models\Employment::query()
            ->where('employee_no', $employeeNo)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $person = $this->people->person(['legal_name' => 'Synthetic Payroll Person '.$employeeNo]);
        $employment = $this->people->employment($person, $employeeNo);
        $this->people->terms($employment, 1, [
            'base_salary_minor' => $baseSalaryCents,
            'salary_currency' => 'CNY',
            'salary_scheme_code' => 'SCHEME-A',
        ]);

        return $employment;
    }

    public function scheme(): PayrollScheme
    {
        $existing = PayrollScheme::query()
            ->where('code', 'SCHEME-A')
            ->where('version', 1)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return PayrollScheme::query()->create([
            'code' => 'SCHEME-A',
            'name' => 'Synthetic monthly scheme',
            'frequency' => 'MONTHLY',
            'currency' => 'CNY',
            'version' => 1,
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'proration_divisor' => 'ANNUAL_DAYS_365',
            'rounding_mode' => 'HALF_UP',
            'overtime_multiplier' => '1.500000',
            'allow_negative_net' => false,
            'base_salary_cents' => 0,
        ]);
    }
}
