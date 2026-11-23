<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

use App\Modules\Workflow\Domain\EffectResult;
use App\Modules\Workflow\Models\Effect;

/**
 * A deterministic domain effect. Local handlers participate in the approval
 * transaction; external handlers must only record an outbox event in it.
 */
interface EffectHandler
{
    public function supports(string $effectType): bool;

    public function apply(Effect $effect): EffectResult;

    /**
     * Reversal is optional at the handler level and may throw when the effect
     * type cannot be reversed automatically.
     */
    public function reverse(Effect $effect): EffectResult;
}
