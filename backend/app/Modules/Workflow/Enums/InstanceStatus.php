<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Enums;

enum InstanceStatus: string
{
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
}
