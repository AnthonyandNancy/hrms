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

    /**
     * An appeal is a dispute against a frozen publication, never against a
     * mutable draft. The cycle status is therefore part of the transition.
     */
    public function canAppeal(string $reviewStatus, ?string $cycleStatus = null): bool
    {
        return $cycleStatus === 'PUBLISHED'
            && in_array($reviewStatus, ['SUBMITTED', 'APPEALED'], true);
    }

    public function assertCanAppeal(string $reviewStatus, ?string $cycleStatus = null): void
    {
        if ($cycleStatus !== 'PUBLISHED') {
            throw new ConflictException('An appeal requires a published result.', [
                'reason' => 'APPEAL_REQUIRES_PUBLISHED_RESULT',
                'cycle_status' => $cycleStatus,
            ]);
        }

        if (! in_array($reviewStatus, ['SUBMITTED', 'APPEALED'], true)) {
            throw new ConflictException('Only a published submitted review can be appealed.', [
                'reason' => 'INVALID_APPEAL_TRANSITION',
                'status' => $reviewStatus,
            ]);
        }
    }
}
