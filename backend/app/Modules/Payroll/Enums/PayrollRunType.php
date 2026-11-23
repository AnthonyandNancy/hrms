<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollRunType: string
{
    case REGULAR = 'REGULAR';
    case CORRECTION = 'CORRECTION';
    case OFF_CYCLE = 'OFF_CYCLE';
    case BONUS = 'BONUS';

    public function requiresCorrectionReference(): bool
    {
        return $this === self::CORRECTION;
    }
}
