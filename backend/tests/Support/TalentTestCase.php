<?php

declare(strict_types=1);

namespace Tests\Support;

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
}
