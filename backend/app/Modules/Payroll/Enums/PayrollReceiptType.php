<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollReceiptType: string
{
    case BANK = 'BANK';
    case TAX = 'TAX';
}
