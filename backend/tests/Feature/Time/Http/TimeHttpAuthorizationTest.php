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
        $this->people->add(TimeTestFixtures::employment(2));
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
        $this->people->add(TimeTestFixtures::employment(2));
        $this->people->add(TimeTestFixtures::employment(3));
        $type = $this->leaveType();
        LeaveAccount::query()->create([
            'employment_id' => 2,
            'leave_type_id' => $type->id,
            'opened_on' => '2026-01-01',
        ]);

        $managed = TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'MANAGED',
            managedEmploymentIds: [2],
        );
        $this->actAsHttp($managed);

        $this->getJson('/v1/leave/balances/2?as_of=2026-11-23')->assertOk();
        $this->getJson('/v1/leave/balances/3?as_of=2026-11-23')->assertNotFound();
    }

    public function test_company_actor_can_read_every_company_employment_but_not_unknown_ids(): void
    {
        $this->people->add(TimeTestFixtures::employment(2));

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: null,
            scope: 'COMPANY',
        ));

        $this->getJson('/v1/time/attendance/1?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->getJson('/v1/time/attendance/2?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->getJson('/v1/time/attendance/88?from=2026-11-01&to=2026-11-30')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_unknown_employment_is_404_without_enumeration(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: null,
            scope: 'COMPANY',
        ));

        $this->getJson('/v1/time/attendance/88?from=2026-11-01&to=2026-11-30')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_self_actor_cannot_import_punches_for_another_employment(): void
    {
        $this->people->add(TimeTestFixtures::employment(2));

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
