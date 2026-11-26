<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Contracts\DomainEffectCommand;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\DomainEffectResult;
use App\Modules\Workflow\Contracts\EffectHandler;
use App\Modules\Workflow\Domain\EffectResult;
use App\Modules\Workflow\Enums\EffectStatus;
use App\Modules\Workflow\Models\Effect;

/**
 * Adapts a contract-only domain handler to the Workflow executor's internal
 * handler port without exposing Workflow models to the domain module.
 */
final class DomainEffectHandlerAdapter implements EffectHandler
{
    public function __construct(private readonly DomainEffectHandler $handler) {}

    public function supports(string $effectType): bool
    {
        return $this->handler->supports($effectType);
    }

    public function apply(Effect $effect): EffectResult
    {
        $result = $this->handler->apply($this->command($effect));

        return new EffectResult($this->status($result->status), $result->outboxEvents);
    }

    public function reverse(Effect $effect): EffectResult
    {
        $result = $this->handler->reverse($this->command($effect));

        return new EffectResult($this->status($result->status), $result->outboxEvents);
    }

    private function command(Effect $effect): DomainEffectCommand
    {
        return new DomainEffectCommand(
            effectId: (int) $effect->id,
            caseId: (int) $effect->case_id,
            decisionId: $effect->decision_id !== null ? (int) $effect->decision_id : null,
            effectType: (string) $effect->effect_type,
            aggregateType: (string) $effect->aggregate_type,
            aggregateId: (string) $effect->aggregate_id,
            effectSeq: (int) $effect->effect_seq,
            payload: (array) ($effect->payload_json ?? []),
        );
    }

    private function status(string $status): EffectStatus
    {
        return match ($status) {
            DomainEffectResult::SCHEDULED => EffectStatus::SCHEDULED,
            DomainEffectResult::REVERSED => EffectStatus::REVERSED,
            default => EffectStatus::APPLIED,
        };
    }
}
