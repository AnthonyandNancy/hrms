<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Enums\CaseEffectStatus;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Effect;
use App\Shared\Time\Clock;

/**
 * Derives the case-level effect projection. Application status is never
 * touched here; this is the only place that computes `cases.effect_status`.
 */
final class EffectProjectionService
{
    public function __construct(private readonly Clock $clock) {}

    public function refresh(CaseModel $case): CaseEffectStatus
    {
        $status = $this->compute($case);

        if ($case->effect_status !== $status) {
            $previous = $case->effect_status;
            $case->forceFill(['effect_status' => $status])->save();

            if ($previous !== $status) {
                \App\Modules\Workflow\Models\CaseEvent::query()->create([
                    'tenant_id' => $case->tenant_id,
                    'case_id' => $case->id,
                    'event_type' => 'effect.status_changed',
                    'actor_user_id' => null,
                    'payload_json' => [
                        'case_id' => $case->id,
                        'from' => $previous->value,
                        'to' => $status->value,
                    ],
                    'occurred_at' => $this->clock->now(),
                ]);
            }
        }

        return $status;
    }

    public function compute(CaseModel $case): CaseEffectStatus
    {
        $statuses = Effect::query()
            ->where('case_id', $case->id)
            ->where('tenant_id', $case->tenant_id)
            ->pluck('status')
            ->map(static fn (mixed $status): string => $status instanceof \App\Modules\Workflow\Enums\EffectStatus ? $status->value : (string) $status)
            ->all();

        if ($statuses === []) {
            return CaseEffectStatus::NONE;
        }

        if (in_array('FAILED', $statuses, true)) {
            return CaseEffectStatus::FAILED;
        }

        if (count(array_filter($statuses, static fn (string $status): bool => $status === 'APPLIED')) === count($statuses)) {
            return CaseEffectStatus::APPLIED;
        }

        if (count(array_filter($statuses, static fn (string $status): bool => $status === 'REVERSED')) === count($statuses)) {
            return CaseEffectStatus::REVERSED;
        }

        $scheduled = count(array_filter($statuses, static fn (string $status): bool => $status === 'SCHEDULED'));

        if ($scheduled > 0) {
            return $scheduled === count($statuses) ? CaseEffectStatus::SCHEDULED : CaseEffectStatus::PARTIAL;
        }

        return CaseEffectStatus::APPLIED;
    }
}
