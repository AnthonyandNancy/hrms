<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Enums;

enum EffectStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case APPLIED = 'APPLIED';
    case FAILED = 'FAILED';
    case REVERSED = 'REVERSED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::APPLIED, self::REVERSED], true);
    }
}
