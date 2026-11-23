<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Enums;

/**
 * Case-level projection of all effects. PENDING/PARTIAL describe multiple
 * effects and are never an application status.
 */
enum CaseEffectStatus: string
{
    case NONE = 'NONE';
    case PENDING = 'PENDING';
    case SCHEDULED = 'SCHEDULED';
    case APPLIED = 'APPLIED';
    case PARTIAL = 'PARTIAL';
    case FAILED = 'FAILED';
    case REVERSED = 'REVERSED';
}
