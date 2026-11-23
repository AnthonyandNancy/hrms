<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollItemType: string
{
    case EARNING = 'EARNING';
    case DEDUCTION = 'DEDUCTION';
    case EMPLOYER_COST = 'EMPLOYER_COST';
    case INFO = 'INFO';
}
