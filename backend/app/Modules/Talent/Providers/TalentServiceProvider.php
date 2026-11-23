<?php

declare(strict_types=1);

namespace App\Modules\Talent\Providers;

use App\Modules\Talent\Contracts\OfferIntegrityReader;
use App\Modules\Talent\Services\OfferIntegrityReaderService;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use Illuminate\Support\ServiceProvider;

final class TalentServiceProvider extends ServiceProvider
{
    /** @var list<class-string<DomainEffectHandler>> */
    public const EFFECT_HANDLERS = [
        \App\Modules\Talent\EffectHandlers\RequisitionApprovalEffectHandler::class,
        \App\Modules\Talent\EffectHandlers\OfferApprovalEffectHandler::class,
    ];

    /** @var list<string> */
    public const EFFECT_TYPES = [
        'talent.requisition.approve',
        'talent.offer.approve',
    ];

    public function register(): void
    {

        $this->app->bind(OfferIntegrityReader::class, OfferIntegrityReaderService::class);

        /** @var list<string> $existing */
        $existing = (array) config('workflow.domain_effect_handlers', []);
        config()->set('workflow.domain_effect_handlers', array_values(array_unique([...$existing, ...self::EFFECT_HANDLERS])));

        /** @var list<string> $types */
        $types = (array) config('workflow.effect_types', []);
        config()->set('workflow.effect_types', array_values(array_unique([...$types, ...self::EFFECT_TYPES])));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Talent\Console\SeedTalentProgramsCommand::class,
            ]);
        }
    }
}
