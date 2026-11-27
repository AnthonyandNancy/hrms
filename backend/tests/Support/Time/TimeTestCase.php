<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\LeaveAccount;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\LegalEntityCalendar;
use App\Modules\Time\Models\ShiftAssignment;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Time\Services\LeaveAccountService;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPrincipal;
use Tests\TestCase;

abstract class TimeTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeEmploymentDirectory $people;

    protected FakeWorkflowCasePort $workflow;

    protected FakeTimeActorResolver $actors;

    protected FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock('2026-11-23T09:00:00Z');
        $this->people = new FakeEmploymentDirectory;
        $this->workflow = new FakeWorkflowCasePort;
        $this->actors = new FakeTimeActorResolver;

        $this->app->instance(FakeEmploymentDirectory::class, $this->people);
        $this->app->instance(\App\Modules\Time\Contracts\EmploymentDirectory::class, $this->people);
        $this->app->instance(FakeWorkflowCasePort::class, $this->workflow);
        $this->app->instance(\App\Modules\Time\Contracts\WorkflowCasePort::class, $this->workflow);
        $this->app->instance(FakeTimeActorResolver::class, $this->actors);
        $this->app->instance(\App\Modules\Time\Contracts\TimeActorResolver::class, $this->actors);
        $this->app->instance(\App\Shared\Time\Clock::class, $this->clock);

        $this->people->add(TimeTestFixtures::employment(1));
        $this->people->bindUser(10, 1);
    }

    /**
     * @param  list<int>  $workweek
     */
    protected function calendar(
        string $timezone = 'Asia/Shanghai',
        array $workweek = [1, 2, 3, 4, 5],
    ): LegalEntityCalendar {
        return LegalEntityCalendar::query()->create([
            'timezone' => $timezone,
            'workweek_json' => $workweek,
            'standard_day_minutes' => 480,
            'created_at' => $this->clock->now(),
        ]);
    }

    protected function shift(
        string $code = 'DAY',
        string $start = '09:00:00',
        string $end = '18:00:00',
        bool $crossesMidnight = false,
        int $breakMinutes = 60,
        int $lateGraceMinutes = 0,
        int $earlyGraceMinutes = 0,
        string $validFrom = '2020-01-01',
        string $validTo = '9999-12-31',
    ): ShiftTemplate {
        return ShiftTemplate::query()->create([
            'code' => $code,
            'name' => $code,
            'start_local_time' => $start,
            'end_local_time' => $end,
            'crosses_midnight' => $crossesMidnight,
            'break_minutes' => $breakMinutes,
            'late_grace_minutes' => $lateGraceMinutes,
            'early_grace_minutes' => $earlyGraceMinutes,
            'standard_hours_decimal' => '8.000000',
            'version' => 1,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'source_type' => 'TEST',
            'source_id' => null,
        ]);
    }

    protected function assignShift(
        int $employmentId,
        ShiftTemplate $shift,
        string $validFrom = '2020-01-01',
        string $validTo = '9999-12-31',
    ): ShiftAssignment {
        return ShiftAssignment::query()->create([
            'employment_id' => $employmentId,
            'shift_template_id' => $shift->id,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'source' => 'TEST',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function leaveType(
        array $overrides = [],
    ): LeaveType {
        return LeaveType::query()->create(array_merge([
            'code' => 'ANNUAL',
            'name' => 'Annual leave',
            'is_statutory' => true,
            'is_annual' => true,
            'unit' => 'DAY',
            'annual_entitlement_days' => '15.00',
            'requires_attachment' => false,
            'allow_negative' => false,
            'expiry_rule_json' => null,
            'legal_basis' => 'State Council Order 514',
            'created_at' => $this->clock->now(),
        ], $overrides));
    }

    protected function leaveAccount(LeaveType $type, int $employmentId = 1): LeaveAccount
    {
        return app(LeaveAccountService::class)->ensureAccount(
            employmentId: $employmentId,
            leaveTypeId: $type->id,
            openedOn: '2026-01-01',
        );
    }

    protected function actAsTimeActor(\App\Modules\Time\Contracts\TimeActor $actor): static
    {
        $this->actors->setActor($actor);

        return $this;
    }

    protected function actingAsTravelManager(): static
    {
        $this->app->instance(\App\Shared\Authorization\Authorizer::class, new TimeTestAuthorizer($this->actors));

        $actor = TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'MANAGED',
            capabilities: [
                'time.travel.read',
                'time.travel.request',
                'time.travel.policy.read',
                'time.travel.policy.manage',
                'time.travel.approve',
                'time.travel.expense',
                'time.travel.expense.manage',
            ],
            managedEmploymentIds: [1],
        );
        $this->actAsTimeActor($actor);

        $principal = new TestPrincipal(
            userId: $actor->userId,
            employmentId: $actor->employmentId,
        );

        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);

        return $this;
    }

    protected function createPendingTrip(): int
    {
        $trip = BusinessTripRecord::query()->create([
            'employment_id' => 1,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'destination' => 'Synthetic City',
            'purpose' => 'Synthetic pending trip.',
            'status' => 'PENDING_APPROVAL',
            'created_at' => $this->clock->now(),
        ]);

        return (int) $trip->id;
    }

    protected function createApprovedTrip(): BusinessTripRecord
    {
        $caseId = $this->workflow->createCase(
            requesterUserId: 10,
            type: 'TRAVEL_REQUEST',
            subjectType: 'employment',
            subjectId: 1,
            payload: ['title' => 'Synthetic travel request'],
            businessDate: '2026-12-01',
        );
        $this->workflow->approve($caseId);

        return BusinessTripRecord::query()->create([
            'employment_id' => 1,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'destination' => 'Synthetic City',
            'purpose' => 'Synthetic approved trip.',
            'status' => 'APPROVED',
            'case_id' => $caseId,
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * Bind the authenticated principal and the Time actor together for HTTP
     * tests. The capability engine is the same Time-scoped test authorizer so
     * the route guard and the row scope cannot silently diverge.
     *
     * @param  list<string>  $capabilities
     */
    protected function actingAsTimeEmployee(array $capabilities = [
        'time.overtime.read',
        'time.overtime.request',
        'time.overtime.confirm',
        'time.overtime.settle',
        'time.timeoff.read',
    ]): static
    {
        $this->app->instance(\App\Shared\Authorization\Authorizer::class, new TimeTestAuthorizer($this->actors));

        $actor = TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'SELF',
            capabilities: $capabilities,
        );
        $this->actAsTimeActor($actor);

        $principal = new TestPrincipal(
            userId: $actor->userId,
            employmentId: $actor->employmentId,
        );

        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);

        return $this;
    }
}
