<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Workflow\Contracts\EffectHandler;
use App\Modules\Workflow\Domain\EffectResult;
use App\Modules\Workflow\Models\Effect;

final class CountingEffectHandler implements EffectHandler
{
    public int $calls = 0;

    /** @var list<string> */
    public array $types;

    public function __construct(string ...$types)
    {
        $this->types = $types;
    }

    public function supports(string $effectType): bool
    {
        return in_array($effectType, $this->types, true);
    }

    public function apply(Effect $effect): EffectResult
    {
        $this->calls++;

        return EffectResult::applied();
    }

    public function reverse(Effect $effect): EffectResult
    {
        return EffectResult::reversed();
    }
}
