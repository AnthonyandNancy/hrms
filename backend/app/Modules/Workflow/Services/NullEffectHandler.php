<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\EffectHandler;
use App\Modules\Workflow\Domain\EffectResult;
use App\Modules\Workflow\Models\Effect;

/**
 * Deterministic local no-op used by generic templates and tests. It exists so
 * "APPROVED with APPLIED no-op" is explicit; it never stands in for a real
 * domain effect. Other built-in effect types are external/scheduled and are
 * implemented by later module tasks.
 */
final class NullEffectHandler implements EffectHandler
{
    public function supports(string $effectType): bool
    {
        return in_array($effectType, [
            'workflow.noop',
            'workflow.notify.external',
            'workflow.assignment.activate',
            'workflow.leave.reserve',
            'workflow.contract.renew',
            'workflow.payroll.publish',
        ], true);
    }

    public function apply(Effect $effect): EffectResult
    {
        if ($effect->effect_type !== 'workflow.noop') {
            // Reference baseline cannot mutate other modules' data. External
            // effects are delivered by outbox; local domain effects arrive in
            // the integration task. Failing closed beats pretending success.
            throw new \RuntimeException('No domain effect handler is bound for '.$effect->effect_type.'.');
        }

        return EffectResult::applied();
    }

    public function reverse(Effect $effect): EffectResult
    {
        return EffectResult::reversed();
    }
}
