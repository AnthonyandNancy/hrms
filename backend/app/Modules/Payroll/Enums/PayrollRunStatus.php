<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollRunStatus: string
{
    case DRAFT = 'DRAFT';
    case INPUT_LOCKED = 'INPUT_LOCKED';
    case CALCULATED = 'CALCULATED';
    case CALCULATED_WITH_BLOCKERS = 'CALCULATED_WITH_BLOCKERS';
    case REVIEWED = 'REVIEWED';
    case APPROVED = 'APPROVED';
    case PUBLISHED = 'PUBLISHED';
    case PAID = 'PAID';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    public function isSealed(): bool
    {
        return in_array($this, [self::PUBLISHED, self::PAID, self::CLOSED], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CLOSED, self::CANCELLED], true);
    }

    public function isPrePublish(): bool
    {
        return in_array($this, [self::DRAFT, self::INPUT_LOCKED, self::CALCULATED, self::CALCULATED_WITH_BLOCKERS, self::REVIEWED, self::APPROVED], true);
    }
}
