<?php

declare(strict_types=1);

namespace App\Modules\Talent\Domain;

use App\Shared\Exceptions\ConflictException;

final class PerformanceStateMachine
{
    public function canSubmit(string $status): bool
    {
        return $status === 'DRAFT';
    }

    public function assertCanSubmit(string $status): void
    {
        if (! $this->canSubmit($status)) {
            throw new ConflictException('Only a draft review can be submitted.', [
                'reason' => 'INVALID_REVIEW_TRANSITION',
                'status' => $status,
            ]);
        }
    }

    public function canAppeal(string $status): bool
    {
        return $status === 'SUBMITTED';
    }

    public function assertCanAppeal(string $status): void
    {
        if (! $this->canAppeal($status)) {
            throw new ConflictException('Only a submitted review can be appealed.', [
                'reason' => 'INVALID_APPEAL_TRANSITION',
                'status' => $status,
            ]);
        }
    }
}
