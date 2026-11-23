<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollCalculationType: string
{
    case FIXED = 'FIXED';
    case ATTENDANCE_PRORATE = 'ATTENDANCE_PRORATE';
    case PERCENT = 'PERCENT';
    case FORMULA_LIMITED = 'FORMULA_LIMITED';
}
