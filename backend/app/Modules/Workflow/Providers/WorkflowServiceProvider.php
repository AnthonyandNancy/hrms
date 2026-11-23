<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Providers;

use App\Modules\Workflow\Contracts\ActorDirectory;
use App\Modules\Workflow\Contracts\AssigneeDirectory;
use App\Modules\Workflow\Contracts\AssigneeEligibility;
use App\Modules\Workflow\Contracts\CaseCommandPort;
use App\Modules\Workflow\Contracts\DomainEffectHandler;
use App\Modules\Workflow\Contracts\EffectHandler;
use App\Modules\Workflow\Contracts\PayrollApprovalWorkflow;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Modules\Workflow\Contracts\WorkflowDelegationRevoker;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Modules\Workflow\Domain\EffectRegistry;
use App\Modules\Workflow\Services\CaseCommandService;
use App\Modules\Workflow\Services\DefaultActorDirectory;
use App\Modules\Workflow\Services\DefaultAssigneeDirectory;
use App\Modules\Workflow\Services\DefaultAssigneeEligibility;
use App\Modules\Workflow\Services\DefaultWorkflowPrincipalResolver;
use App\Modules\Workflow\Services\DomainEffectHandlerAdapter;
use App\Modules\Workflow\Services\NullEffectHandler;
use App\Modules\Workflow\Services\PayrollApprovalEffectHandler;
use App\Modules\Workflow\Services\PayrollApprovalWorkflowService;
use App\Modules\Workflow\Services\WorkflowCaseGatewayService;
use App\Modules\Workflow\Services\WorkflowDelegationRevocationService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class WorkflowServiceProvider extends ServiceProvider
{
    /**
     * Effect handlers are registered here so the catalog is code-owned. Task 5+
     * replaces or extends these bindings with domain-specific handlers.
     *
     * @var list<class-string<EffectHandler>>
     */
    public const DEFAULT_EFFECT_HANDLERS = [
        NullEffectHandler::class,
        PayrollApprovalEffectHandler::class,
    ];

    /**
     * Contract-only handlers registered by domain modules. They are adapted
     * inside Workflow so no domain module imports Workflow models.
     *
     * @var list<class-string<DomainEffectHandler>>
     */
    public const DEFAULT_DOMAIN_EFFECT_HANDLERS = [];

    /**
     * Explicit effect type catalog. Unknown types in a template are rejected
     * before a Decision is committed.
     *
     * @var list<string>
     */
    public const DEFAULT_EFFECT_TYPES = [
        'workflow.noop',
        'workflow.notify.external',
        'workflow.assignment.activate',
        'workflow.leave.reserve',
        'workflow.contract.renew',
        'workflow.payroll.publish',
    ];

    public function register(): void
    {
        $this->app->bindIf(ActorDirectory::class, DefaultActorDirectory::class);
        $this->app->bindIf(AssigneeDirectory::class, DefaultAssigneeDirectory::class);
        $this->app->bindIf(AssigneeEligibility::class, DefaultAssigneeEligibility::class);
        $this->app->bindIf(WorkflowPrincipalResolver::class, DefaultWorkflowPrincipalResolver::class);
        $this->app->bind(CaseCommandPort::class, CaseCommandService::class);
        $this->app->bind(WorkflowCaseGateway::class, WorkflowCaseGatewayService::class);
        $this->app->bind(WorkflowDelegationRevoker::class, WorkflowDelegationRevocationService::class);
        $this->app->bind(PayrollApprovalWorkflow::class, PayrollApprovalWorkflowService::class);

        $this->app->singleton(EffectRegistry::class, function (Application $app): EffectRegistry {
            $handlers = [];

            foreach (self::DEFAULT_EFFECT_HANDLERS as $handlerClass) {
                $handlers[] = $app->make($handlerClass);
            }

            foreach ((array) config('workflow.effect_handlers', []) as $handlerClass) {
                if (is_string($handlerClass) && ! in_array($handlerClass, self::DEFAULT_EFFECT_HANDLERS, true)) {
                    $handlers[] = $app->make($handlerClass);
                }
            }

            foreach ((array) config('workflow.domain_effect_handlers', []) as $handlerClass) {
                if (is_string($handlerClass)) {
                    $handlers[] = new DomainEffectHandlerAdapter($app->make($handlerClass));
                }
            }

            $types = array_values(array_unique([
                ...self::DEFAULT_EFFECT_TYPES,
                ...(array) config('workflow.effect_types', []),
            ]));

            return new EffectRegistry($handlers, $types);
        });
    }
}
