<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollEmployeeStatus: string
{
    case PENDING = 'PENDING';
    case CALCULATED = 'CALCULATED';
    case EXCLUDED = 'EXCLUDED';
    case BLOCKED = 'BLOCKED';
    case FAILED = 'FAILED';
}
