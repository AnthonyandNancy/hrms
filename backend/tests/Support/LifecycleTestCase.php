<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Workflow\Domain\EffectRegistry;
use App\Shared\Authorization\Authorizer;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class LifecycleTestCase extends TestCase
{
    use LifecycleEffectBinding;
    use RefreshDatabase;

    protected FrozenClock $clock;

    protected AllowAllAuthorizer $authorizer;

    protected PeopleScenario $peopleScenario;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hrms.default_timezone', 'Asia/Shanghai');
        config()->set('hrms.pii_hmac_key', 'test-pii-hmac-key');
        config()->set('lifecycle.probation_max_days', 180);

        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T01:00:00Z'));
        $this->app->instance(Clock::class, $this->clock);
        $this->authorizer = new AllowAllAuthorizer;
        $this->app->instance(Authorizer::class, $this->authorizer);
        $this->app->forgetInstance(EffectRegistry::class);
        $this->bindLifecycleEffects();

        $this->actAs(10, 1);
        $this->peopleScenario = new PeopleScenario(1, $this->clock);
        $this->bindWorkflowPrincipal(1, 10);
        $this->app->instance(Authorizer::class, $this->authorizer);
    }

    protected function actAs(int $userId, int $tenantId = 1, ?int $employmentId = null, bool $privileged = true): TestPrincipal
    {
        $principal = new TestPrincipal(
            tenantId: $tenantId,
            userId: $userId,
            employmentId: $employmentId,
            authVersion: 1,
            privileged: $privileged,
        );
        $this->app->instance(Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);

        if ($this->app->bound(\App\Modules\IAM\Auth\AuthenticationContext::class)) {
            $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);
        }

        return $principal;
    }

    /**
     * @return array{0: \App\Modules\People\Models\Employment, 1: \App\Modules\Organization\Models\OrgUnit, 2: \App\Modules\Organization\Models\OrgUnit}
     */
    protected function seedEmploymentWithOrg(): array
    {
        $entity = $this->peopleScenario->legalEntity();
        $home = $this->peopleScenario->orgUnit($entity, 'HOME-ORG');
        $host = $this->peopleScenario->orgUnit($entity, 'HOST-ORG');
        $employment = $this->peopleScenario->employment($this->peopleScenario->person(), $entity, 'SEC-0001');
        $this->peopleScenario->assignment($employment, $home);

        return [$employment, $home, $host];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function createLifecycleCase(string $path, array $payload, string $key): TestResponse
    {
        return $this->postJson("/v1/lifecycle/{$path}", $payload, ['Idempotency-Key' => $key]);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function approveCase(int $caseId, string $key = 'approve-key'): TestResponse
    {
        return $this->postJson("/v1/cases/{$caseId}/decisions", [
            'action' => 'APPROVE',
            'comment' => 'Approved by HR.',
        ], ['Idempotency-Key' => $key]);
    }

    protected function bindWorkflowPrincipal(int $tenantId, int $userId, ?int $employmentId = null): void
    {
        $resolver = new FakeWorkflowPrincipalResolver;
        $resolver->setPrincipal(new \App\Modules\Workflow\Contracts\WorkflowPrincipal(
            tenantId: $tenantId,
            userId: $userId,
            employmentId: $employmentId,
            authVersion: 1,
            isPrivileged: false,
            capabilities: [
                'workflow.case.read',
                'workflow.case.create',
                'workflow.case.decide',
                'workflow.case.admin',
            ],
        ));
        $this->app->instance(\App\Modules\Workflow\Contracts\WorkflowPrincipalResolver::class, $resolver);
        $this->app->instance(\App\Modules\Workflow\Contracts\ActorDirectory::class, new FakeActorDirectory);
        $this->app->instance(\App\Modules\Workflow\Contracts\AssigneeDirectory::class, new class implements \App\Modules\Workflow\Contracts\AssigneeDirectory
        {
            public function managerUserIdForSubject(int $tenantId, string $subjectType, int $subjectId, string $businessDate): ?int
            {
                return null;
            }

            public function orgHeadUserIdsForSubject(int $tenantId, string $subjectType, int $subjectId, string $businessDate): array
            {
                return [];
            }

            public function userIdsWithRole(int $tenantId, string $roleCode, string $businessDate): array
            {
                return [10];
            }

            public function reassignmentCandidates(int $tenantId, int $caseId, int $currentAssigneeId, string $businessDate): array
            {
                return [];
            }
        });
        $this->app->instance(\App\Modules\Workflow\Contracts\AssigneeEligibility::class, new FakeAssigneeEligibility);
        $this->app->instance(\App\Modules\Workflow\Contracts\WorkflowPrincipalResolver::class, $resolver);
    }
}
