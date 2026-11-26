<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Payroll\Contracts\PayrollApprovalEffectPort;
use App\Modules\Workflow\Contracts\EffectHandler;
use App\Modules\Workflow\Domain\EffectResult;
use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Models\Effect;

/**
 * Local domain effect for payroll approval. It delegates through a Payroll
 * contract so Workflow owns the decision/effect evidence while Payroll owns the
 * run state.
 */
final class PayrollApprovalEffectHandler implements EffectHandler
{
    public function __construct(private readonly PayrollApprovalEffectPort $payroll) {}

    public function supports(string $effectType): bool
    {
        return $effectType === 'workflow.payroll.publish';
    }

    public function apply(Effect $effect): EffectResult
    {
        $runId = (int) $effect->aggregate_id;

        if ($runId < 1) {
            throw new \RuntimeException('The payroll approval effect has no payroll run aggregate id.');
        }

        $decision = Decision::query()
            ->whereKey((int) $effect->decision_id)
            ->first();

        if ($decision === null) {
            throw new \RuntimeException('The payroll approval effect has no decision actor.');
        }

        $this->payroll->applyApproval($runId, (int) $decision->actor_user_id);

        return EffectResult::applied();
    }

    public function reverse(Effect $effect): EffectResult
    {
        // Publication is irreversible; reversal requires a correction run.
        return EffectResult::reversed();
    }
}
