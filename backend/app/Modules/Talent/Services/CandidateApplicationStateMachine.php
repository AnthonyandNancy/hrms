<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Shared\Exceptions\ConflictException;

/**
 * Guarded stage graph for one candidate application. Conversion is the only
 * command allowed to reach CONVERTED; a generic stage move can never fake an
 * offer acceptance or an employment.
 */
final class CandidateApplicationStateMachine
{
    /** Ordered happy-path stages. REJECTED is reachable from any active stage. */
    private const ORDER = ['NEW', 'SCREENING', 'INTERVIEW', 'OFFER', 'OFFER_ACCEPTED'];

    /** @var list<string> */
    public const MANUAL_STAGES = ['NEW', 'SCREENING', 'INTERVIEW', 'OFFER', 'OFFER_ACCEPTED', 'REJECTED'];

    public function assertManualTransition(string $from, string $to): void
    {
        if (! in_array($to, self::MANUAL_STAGES, true)) {
            throw new ConflictException('Only active pipeline stages may be moved explicitly.', [
                'reason' => 'INVALID_APPLICATION_TRANSITION',
                'from' => $from,
                'to' => $to,
            ]);
        }

        if ($from === $to) {
            return;
        }

        if (in_array($from, ['CONVERTED', 'REJECTED'], true)) {
            throw new ConflictException('A terminal application cannot be moved.', [
                'reason' => 'APPLICATION_TERMINAL',
                'status' => $from,
            ]);
        }

        if ($to === 'REJECTED') {
            return;
        }

        $fromIndex = array_search($from, self::ORDER, true);
        $toIndex = array_search($to, self::ORDER, true);

        if ($fromIndex === false || $toIndex === false || $toIndex < $fromIndex) {
            throw new ConflictException('The requested application stage transition is not allowed.', [
                'reason' => 'INVALID_APPLICATION_TRANSITION',
                'from' => $from,
                'to' => $to,
            ]);
        }
    }

    public function assertCanConvert(string $stage, string $status): void
    {
        if ($status === 'CONVERTED') {
            return;
        }

        if (! in_array($stage, ['OFFER_ACCEPTED', 'CONVERTED'], true)) {
            throw new ConflictException('Only an accepted offer can be converted into an employment.', [
                'reason' => 'CANDIDATE_NOT_ACCEPTED',
                'stage' => $stage,
            ]);
        }
    }
}
