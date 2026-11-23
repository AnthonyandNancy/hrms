<?php

declare(strict_types=1);

namespace App\Modules\Talent\Domain;

use App\Shared\Exceptions\ConflictException;

final class OfferStateMachine
{
    public const ACCEPTABLE = ['APPROVED'];

    public const TRANSITIONS = [
        'DRAFT' => ['PENDING_APPROVAL', 'WITHDRAWN'],
        'PENDING_APPROVAL' => ['APPROVED', 'REJECTED', 'WITHDRAWN'],
        'APPROVED' => ['ACCEPTED', 'DECLINED', 'SUPERSEDED', 'WITHDRAWN'],
        'ACCEPTED' => [],
        'DECLINED' => [],
        'REJECTED' => [],
        'SUPERSEDED' => [],
        'WITHDRAWN' => [],
    ];

    public function canAccept(string $status): bool
    {
        return in_array($status, self::ACCEPTABLE, true);
    }

    public function assertCanAccept(string $status): void
    {
        if (! $this->canAccept($status)) {
            throw new ConflictException('Only an approved offer can be accepted.', [
                'reason' => 'INVALID_OFFER_TRANSITION',
                'status' => $status,
            ]);
        }
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
