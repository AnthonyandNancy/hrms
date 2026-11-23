<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Exceptions;

use App\Shared\Exceptions\ConflictException;

final class EffectFailedException extends ConflictException
{
    public function __construct(public readonly int $effectId, string $reason)
    {
        parent::__construct('The effect could not be applied.', [
            'reason' => 'EFFECT_FAILED',
            'effect_id' => $effectId,
            'failure_reason' => $reason,
        ]);
    }
}
