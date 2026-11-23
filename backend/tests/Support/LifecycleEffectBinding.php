<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Lifecycle\Providers\LifecycleServiceProvider;
use App\Modules\Workflow\Domain\EffectRegistry;
use App\Modules\Workflow\Services\DomainEffectHandlerAdapter;
use App\Modules\Workflow\Services\NullEffectHandler;

trait LifecycleEffectBinding
{
    protected function bindLifecycleEffects(): void
    {
        $handlers = [new NullEffectHandler];

        foreach (LifecycleServiceProvider::EFFECT_HANDLERS as $class) {
            $handlers[] = new DomainEffectHandlerAdapter(app($class));
        }

        $types = [
            'workflow.noop',
            ...LifecycleServiceProvider::EFFECT_TYPES,
        ];

        $this->app->instance(EffectRegistry::class, new EffectRegistry($handlers, $types));
    }
}
