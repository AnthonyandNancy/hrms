<?php

declare(strict_types=1);

namespace Tests\Feature\Time\Http;

use App\Modules\Time\Models\LeaveAccount;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TimeHttpAuthorizationTest extends TimeHttpTestCase
{
    public function test_unauthenticated_request_is_401(): void
    {
        $this->getJson('/v1/time/leave/balances')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_missing_capability_is_403_even_if_actor_scope_matches(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(capabilities: []));

        $this->getJson('/v1/time/leave/balances')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_employee_sees_only_self_and_other_employment_is_404(): void
    {
        $this->people->add(TimeTestFixtures::employment(1, 2, 1));
        $type = $this->leaveType();
        $this->leaveAccount($type);

        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $this->getJson('/v1/time/leave/balances')
            ->assertOk()
            ->assertJsonPath('data.0.leaveTypeCode', 'ANNUAL');

        $this->getJson('/v1/leave/balances/2?as_of=2026-11-23')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_manager_sees_managed_employment_but_not_unrelated_employment(): void
    {
        $this->people->add(TimeTestFixtures::employment(1, 2, 1));
        $this->people->add(TimeTestFixtures::employment(1, 3, 1));
        $type = $this->leaveType();
        LeaveAccount::query()->create([
            'tenant_id' => 1,
            'employment_id' => 2,
            'leave_type_id' => $type->id,
            'opened_on' => '2026-01-01',
        ]);

        $managed = TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'MANAGED',
            managedEmploymentIds: [2],
            legalEntityIds: [1],
        );
        $this->actAsHttp($managed);

        $this->getJson('/v1/leave/balances/2?as_of=2026-11-23')->assertOk();
        $this->getJson('/v1/leave/balances/3?as_of=2026-11-23')->assertNotFound();
    }

    public function test_hr_is_limited_to_authorized_legal_entities(): void
    {
        $this->people->add(TimeTestFixtures::employment(1, 2, 2));

        $hr = TimeTestFixtures::actor(
            employmentId: null,
            scope: 'LEGAL_ENTITY',
            legalEntityIds: [1],
        );
        $this->actAsHttp($hr);

        $this->getJson('/v1/time/attendance/1?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->getJson('/v1/time/attendance/2?from=2026-11-01&to=2026-11-30')->assertNotFound();
    }

    public function test_cross_tenant_employment_is_404_without_enumeration(): void
    {
        $this->people->add(TimeTestFixtures::employment(2, 88, 8));

        $hr = TimeTestFixtures::actor(
            employmentId: null,
            scope: 'LEGAL_ENTITY',
            legalEntityIds: [1, 8],
        );
        $this->actAsHttp($hr);

        $this->getJson('/v1/time/attendance/88?from=2026-11-01&to=2026-11-30')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_self_actor_cannot_import_punches_for_another_employment(): void
    {
        $this->people->add(TimeTestFixtures::employment(1, 2, 1));

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'SELF',
            capabilities: ['time.punch.import', 'time.attendance.read'],
        ));

        $this->postJson('/v1/time/punches/import', [
            'employment_id' => 2,
            'punches' => [['punched_at_utc' => '2026-11-23T01:00:00Z']],
        ])->assertNotFound();
    }
}
