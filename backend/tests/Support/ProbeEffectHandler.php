<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Workflow\Contracts\EffectHandler;
use App\Modules\Workflow\Domain\EffectResult;
use App\Modules\Workflow\Models\Effect;
use Illuminate\Support\Facades\DB;

/**
 * Test-only deterministic effect that writes to a probe table before throwing
 * so the savepoint/no-partial-write contract is observable.
 */
final class ProbeEffectHandler implements EffectHandler
{
    public int $calls = 0;

    public int $failuresRemaining = 0;

    public function __construct(private readonly string $probeTable = 'workflow_effect_probe') {}

    public function supports(string $effectType): bool
    {
        return $effectType === 'probe.local';
    }

    public function apply(Effect $effect): EffectResult
    {
        $this->calls++;

        DB::table($this->probeTable)->insert([
            'effect_id' => $effect->id,
            'marker' => $effect->aggregate_id,
            'created_at' => now('UTC'),
        ]);

        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new \RuntimeException('probe handler failed after writing');
        }

        return EffectResult::applied();
    }

    public function reverse(Effect $effect): EffectResult
    {
        return EffectResult::reversed();
    }
}
