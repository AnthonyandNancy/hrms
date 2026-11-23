<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Enums;

enum DecisionAction: string
{
    case APPROVE = 'APPROVE';
    case REJECT = 'REJECT';
    case RETURN = 'RETURN';
    case WITHDRAW = 'WITHDRAW';
    case DELEGATE = 'DELEGATE';
    case CC = 'CC';

    public function closesCase(): bool
    {
        return in_array($this, [self::REJECT, self::WITHDRAW], true);
    }
}
