<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Enums;

enum TaskStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case DELEGATED = 'DELEGATED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';

    public function isOpen(): bool
    {
        return $this === self::PENDING;
    }
}
