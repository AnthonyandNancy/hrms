<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Modules\Workflow\Contracts\EffectHandler;
use App\Modules\Workflow\Domain\EffectRegistry;
use App\Modules\Workflow\Domain\EffectResult;
use App\Modules\Workflow\Models\Effect;
use App\Shared\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class EffectRegistryTest extends TestCase
{
    public function test_resolves_handler_by_supported_effect_type(): void
    {
        $leave = new class implements EffectHandler
        {
            public function supports(string $effectType): bool
            {
                return $effectType === 'leave.reserve';
            }

            public function apply(Effect $effect): EffectResult
            {
                return EffectResult::applied();
            }

            public function reverse(Effect $effect): EffectResult
            {
                return EffectResult::reversed();
            }
        };

        $registry = new EffectRegistry([$leave]);

        $this->assertTrue($registry->has('leave.reserve'));
        $this->assertSame($leave, $registry->resolve('leave.reserve'));
    }

    public function test_unknown_effect_type_is_rejected_at_decision_time(): void
    {
        $registry = new EffectRegistry([]);

        try {
            $registry->resolve('unknown.effect');
            $this->fail('Unknown effect type must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('UNKNOWN_EFFECT_TYPE', $exception->details()['reason'] ?? null);
        }
    }

    public function test_two_handlers_supporting_the_same_type_is_a_configuration_error(): void
    {
        $handler = new class implements EffectHandler
        {
            public function supports(string $effectType): bool
            {
                return true;
            }

            public function apply(Effect $effect): EffectResult
            {
                return EffectResult::applied();
            }

            public function reverse(Effect $effect): EffectResult
            {
                return EffectResult::reversed();
            }
        };

        $this->expectException(\LogicException::class);

        new EffectRegistry([$handler, $handler]);
    }

    public function test_effect_types_are_known_from_registered_handlers(): void
    {
        $handler = new class implements EffectHandler
        {
            /** @var list<string> */
            public array $types = ['leave.reserve', 'leave.release'];

            public function supports(string $effectType): bool
            {
                return in_array($effectType, $this->types, true);
            }

            public function apply(Effect $effect): EffectResult
            {
                return EffectResult::applied();
            }

            public function reverse(Effect $effect): EffectResult
            {
                return EffectResult::reversed();
            }
        };

        $registry = new EffectRegistry([$handler], ['leave.reserve', 'leave.release']);

        $this->assertSame(['leave.reserve', 'leave.release'], $registry->supportedTypes());
    }
}
