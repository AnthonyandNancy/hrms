<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use Tests\Support\LifecycleTestCase;

final class LifecycleAuthorizationTest extends LifecycleTestCase
{
    public function test_unauthenticated_and_capability_denied_requests_are_rejected(): void
    {
        $this->app->forgetInstance(\App\Modules\IAM\Contracts\Principal::class);
        $this->postJson('/v1/lifecycle/onboarding', [], ['Idempotency-Key' => 'anon'])->assertUnauthorized();

        $this->actAs(11);
        $this->authorizer->deniedCapabilities[] = 'lifecycle.onboarding.manage';
        $this->postJson('/v1/lifecycle/onboarding', ['legal_entity_id' => 1], ['Idempotency-Key' => 'denied'])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_manager_cannot_onboard_into_another_legal_entity(): void
    {
        $entity = $this->peopleScenario->legalEntity();
        $org = $this->peopleScenario->orgUnit($entity, 'AUTH-ORG');
        $this->actAs(11);

        $this->authorizer->deniedCapabilities[] = 'lifecycle.onboarding.manage';

        $this->postJson('/v1/lifecycle/onboarding', [
            'person' => ['legal_name' => 'Unauthorized'],
            'legal_entity_id' => 2,
            'employee_no' => 'AUTH-0001',
            'org_unit_id' => $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => ['work_location' => 'Shanghai', 'work_schedule' => 'STANDARD'],
        ], ['Idempotency-Key' => 'cross-entity-denied'])->assertForbidden();

        $this->assertSame([], \App\Modules\People\Models\Employment::query()->get()->all());
    }

    public function test_self_capability_does_not_grant_lifecycle_case_read(): void
    {
        $this->actAs(12, employmentId: 99);
        $this->authorizer->deniedCapabilities[] = 'lifecycle.case.read';

        $this->getJson('/v1/lifecycle/cases/1')
            ->assertForbidden()
            ->assertJsonPath('data.capability', 'lifecycle.case.read');
    }
}
