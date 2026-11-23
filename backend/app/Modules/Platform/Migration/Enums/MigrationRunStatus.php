<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Enums;

enum MigrationRunStatus: string
{
    case RUNNING = 'RUNNING';
    case VALIDATED = 'VALIDATED';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case APPLIED = 'APPLIED';
    case APPLY_FAILED = 'APPLY_FAILED';
    case FAILED = 'FAILED';

    public function isSuccessful(): bool
    {
        return in_array($this, [self::VALIDATED, self::APPLIED], true);
    }

    public function isTerminal(): bool
    {
        return $this !== self::RUNNING;
    }
}
