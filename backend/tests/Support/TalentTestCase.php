<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Talent\Models\PerformanceCycle;
use App\Modules\Talent\Models\PerformanceReview;
use App\Modules\Talent\Providers\TalentServiceProvider;
use App\Modules\Workflow\Domain\EffectRegistry;
use App\Modules\Workflow\Services\DomainEffectHandlerAdapter;
use App\Modules\Workflow\Services\NullEffectHandler;

abstract class TalentTestCase extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $handlers = [new NullEffectHandler];

        foreach ([\App\Modules\Lifecycle\Providers\LifecycleServiceProvider::EFFECT_HANDLERS, TalentServiceProvider::EFFECT_HANDLERS] as $classes) {
            foreach ($classes as $class) {
                $handlers[] = new DomainEffectHandlerAdapter(app($class));
            }
        }

        $types = [
            'workflow.noop',
            ...\App\Modules\Lifecycle\Providers\LifecycleServiceProvider::EFFECT_TYPES,
            ...TalentServiceProvider::EFFECT_TYPES,
        ];

        $this->app->instance(EffectRegistry::class, new EffectRegistry($handlers, $types));
    }

    protected function createPerformanceCycle(): PerformanceCycle
    {
        return PerformanceCycle::query()->create([
            'tenant_id' => 1,
            'name' => 'Synthetic 2026 cycle',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);
    }

    protected function createReview(PerformanceCycle $cycle, int $employmentId, string $selfRating = '3'): PerformanceReview
    {
        return PerformanceReview::query()->create([
            'tenant_id' => 1,
            'cycle_id' => (int) $cycle->id,
            'employment_id' => $employmentId,
            'status' => 'DRAFT',
            'self_rating' => $selfRating,
            'recorded_at' => $this->clock->now(),
        ]);
    }
}
