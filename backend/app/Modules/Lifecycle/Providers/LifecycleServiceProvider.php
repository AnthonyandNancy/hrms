<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Providers;

use App\Modules\Lifecycle\Contracts\ExportTaskCanceller;
use App\Modules\Lifecycle\Contracts\TalentOnboardingDraftPort;
use App\Modules\Lifecycle\Contracts\TurnoverDirectory;
use App\Modules\Lifecycle\Services\NullExportTaskCanceller;
use App\Modules\Lifecycle\Services\TalentOnboardingDraftService;
use App\Modules\Lifecycle\Services\UnavailableTurnoverDirectory;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use Illuminate\Support\ServiceProvider;

/**
 * Registers lifecycle domain effect handlers through Workflow's contract-only
 * adapter, plus the export-cancellation and reporting ports.
 */
final class LifecycleServiceProvider extends ServiceProvider
{
    /** @var list<class-string<DomainEffectHandler>> */
    public const EFFECT_HANDLERS = [
        \App\Modules\Lifecycle\EffectHandlers\OnboardingEffectHandler::class,
        \App\Modules\Lifecycle\EffectHandlers\ProbationConfirmEffectHandler::class,
        \App\Modules\Lifecycle\EffectHandlers\TransferEffectHandler::class,
        \App\Modules\Lifecycle\EffectHandlers\TermsChangeEffectHandler::class,
        \App\Modules\Lifecycle\EffectHandlers\OffboardEffectHandler::class,
    ];

    /** @var list<string> */
    public const EFFECT_TYPES = [
        'lifecycle.onboarding',
        'lifecycle.probation_confirm',
        'lifecycle.assignment_change',
        'lifecycle.terms_change',
        'lifecycle.offboard',
        'lifecycle.contract_renew',
        'lifecycle.rehire',
    ];

    public function register(): void
    {
        $this->app->bind(ExportTaskCanceller::class, NullExportTaskCanceller::class);
        $this->app->bind(TalentOnboardingDraftPort::class, TalentOnboardingDraftService::class);
        $this->app->bindIf(TurnoverDirectory::class, UnavailableTurnoverDirectory::class);

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
                \App\Modules\Lifecycle\Console\SeedLifecycleTemplatesCommand::class,
                \App\Modules\Lifecycle\Console\LifecycleDemoCommand::class,
                \App\Modules\Lifecycle\Console\CheckLifecycleIntegrityCommand::class,
            ]);
        }
    }
}
