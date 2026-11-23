<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollParameterSet: string
{
    case TAX = 'TAX';
    case SOCIAL_INSURANCE = 'SOCIAL_INSURANCE';
    case HOUSING_FUND = 'HOUSING_FUND';
    case ROUNDING = 'ROUNDING';
}
