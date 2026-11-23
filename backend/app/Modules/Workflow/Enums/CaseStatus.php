<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Enums;

enum CaseStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case IN_REVIEW = 'IN_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case WITHDRAWN = 'WITHDRAWN';
    case CANCELLED = 'CANCELLED';

    public function isOpen(): bool
    {
        return in_array($this, [self::DRAFT, self::SUBMITTED, self::IN_REVIEW], true);
    }

    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }
}
