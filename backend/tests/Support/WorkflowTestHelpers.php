<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Workflow\Contracts\ActorDirectory;
use App\Modules\Workflow\Contracts\ActorSnapshot;
use App\Modules\Workflow\Contracts\EffectHandler;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Modules\Workflow\Domain\EffectRegistry;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Modules\Workflow\Services\TemplateService;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;

trait WorkflowTestHelpers
{
    protected FakeWorkflowPrincipalResolver $principalResolver;

    protected FakeActorDirectory $actorDirectory;

    protected FrozenClock $clock;

    /**
     * @param  list<string>  $capabilities
     */
    protected function bootWorkflow(int $userId = 10, int $tenantId = 1, array $capabilities = []): void
    {
        $this->principalResolver = new FakeWorkflowPrincipalResolver;
        $this->actorDirectory = new FakeActorDirectory;
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z'));

        if ($capabilities === []) {
            $capabilities = [
                'workflow.case.read',
                'workflow.case.create',
                'workflow.case.decide',
                'workflow.case.admin',
            ];
        }

        $this->principalResolver->setPrincipal(new WorkflowPrincipal(
            tenantId: $tenantId,
            userId: $userId,
            employmentId: null,
            authVersion: 3,
            isPrivileged: false,
            roles: ['HR_ADMIN'],
            capabilities: $capabilities,
        ));
        $this->actorDirectory->add(new ActorSnapshot(
            tenantId: $tenantId,
            userId: $userId,
            employmentId: null,
            authVersion: 3,
            isPrivileged: false,
            roles: ['HR_ADMIN'],
            capabilities: $capabilities,
        ));

        $this->app->instance(WorkflowPrincipalResolver::class, $this->principalResolver);
        $this->app->instance(ActorDirectory::class, $this->actorDirectory);
        $this->app->instance(Clock::class, $this->clock);

        $this->bindIamBridge();

        $assignees = new FakeAssigneeDirectory;
        $this->app->instance(\App\Modules\Workflow\Contracts\AssigneeDirectory::class, $assignees);
        $this->app->instance(\App\Modules\Workflow\Contracts\AssigneeEligibility::class, new FakeAssigneeEligibility);
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function actAs(int $userId, int $tenantId = 1, array $capabilities = []): void
    {
        if ($capabilities === []) {
            $capabilities = [
                'workflow.case.read',
                'workflow.case.create',
                'workflow.case.decide',
                'workflow.case.admin',
            ];
        }

        $this->principalResolver->setPrincipal(new WorkflowPrincipal(
            tenantId: $tenantId,
            userId: $userId,
            employmentId: null,
            authVersion: 3,
            isPrivileged: false,
            roles: ['EMPLOYEE'],
            capabilities: $capabilities,
        ));

        $this->bindIamBridge();

        if (! $this->actorDirectory->has($tenantId, $userId)) {
            $this->actorDirectory->add(new ActorSnapshot(
                tenantId: $tenantId,
                userId: $userId,
                employmentId: null,
                authVersion: 3,
                isPrivileged: false,
                roles: ['EMPLOYEE'],
                capabilities: $capabilities,
            ));
        }
    }

    /**
     * Mirror the workflow fixture into IAM's request-scoped identity seams so
     * the shared route-level `capability` middleware evaluates the same
     * principal the Workflow controllers use.
     */
    protected function bindIamBridge(): void
    {
        $resolved = $this->principalResolver->resolve();

        if ($resolved === null) {
            return;
        }

        $principal = new TestPrincipal(
            tenantId: $resolved->tenantId,
            userId: $resolved->userId,
            employmentId: $resolved->employmentId,
            authVersion: $resolved->authVersion,
            privileged: $resolved->isPrivileged,
            mfa: true,
        );

        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);
        $this->app->instance(\App\Shared\Authorization\Authorizer::class, new WorkflowAuthorizerBridge($this->principalResolver));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>|null  $definition
     */
    protected function createTemplate(array $overrides = [], ?array $definition = null): WorkflowTemplate
    {
        return app(TemplateService::class)->create(1, 10, array_merge([
            'code' => 'generic.approval',
            'name' => 'Generic approval',
            'version' => 1,
            'status' => 'PUBLISHED',
            'definition' => $definition ?? $this->singleApprovalDefinition(),
        ], $overrides));
    }

    /**
     * @param  array<int, array<string, mixed>>  $effects
     * @param  array<string, mixed>  $assigneeExtra
     * @return array<string, mixed>
     */
    protected function singleApprovalDefinition(array $effects = [], string $assigneeRuleType = 'USER', array $assigneeExtra = []): array
    {
        $assigneeRule = array_merge(['type' => $assigneeRuleType], $assigneeRuleType === 'USER' && ! isset($assigneeExtra['user_id']) ? ['user_id' => 10] : [], $assigneeExtra);

        return [
            'nodes' => [
                ['key' => 'start', 'name' => 'Start', 'type' => 'START', 'sequence' => 1],
                ['key' => 'manager_approval', 'name' => 'Manager approval', 'type' => 'APPROVAL', 'sequence' => 2, 'assignee_rule' => $assigneeRule, 'required_count' => 1, 'sla_hours' => 24],
                ['key' => 'end', 'name' => 'End', 'type' => 'END', 'sequence' => 3],
            ],
            'effects' => $effects,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function submittedCase(WorkflowTemplate $template, array $overrides = []): \App\Modules\Workflow\Models\CaseModel
    {
        $this->createCaseThroughApi($template, array_merge(['submit' => true], $overrides))->assertCreated();

        return \App\Modules\Workflow\Models\CaseModel::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    protected function createCaseThroughApi(WorkflowTemplate $template, array $overrides = [], bool $submit = false): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/v1/cases', array_merge([
            'type' => 'leave.request',
            'subject_type' => 'employment',
            'subject_id' => 100,
            'title' => 'Annual leave',
            'payload' => ['days' => 2, 'amount_minor' => 1000],
            'template_id' => $template->id,
            'business_date' => '2026-11-23',
            'submit' => $submit,
        ], $overrides), [
            'Idempotency-Key' => 'case-key-'.bin2hex(random_bytes(4)),
        ]);
    }

    protected function bindEffectHandler(EffectHandler $handler, string $type): void
    {
        $this->app->instance(EffectRegistry::class, new EffectRegistry([$handler], [$type]));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
     */
    protected function decideThroughApi(int $caseId, string $idempotencyKey, string $action = 'APPROVE', array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/v1/cases/{$caseId}/decisions", array_merge([
            'action' => $action,
            'comment' => 'Approved.',
        ], $body), [
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }
}
