<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

enum PayslipLineCategory: string
{
    case EARNING = 'EARNING';
    case DEDUCTION = 'DEDUCTION';
    case EMPLOYER_COST = 'EMPLOYER_COST';
    case INFO = 'INFO';

    public function isEarning(): bool
    {
        return $this === self::EARNING;
    }

    public function isDeduction(): bool
    {
        return $this === self::DEDUCTION;
    }

    public function isEmployerCost(): bool
    {
        return $this === self::EMPLOYER_COST;
    }
}
