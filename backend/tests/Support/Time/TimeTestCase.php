<?php

declare(strict_types=1);

namespace Tests\Support\Time;

use App\Modules\Time\Models\LeaveAccount;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\LegalEntityCalendar;
use App\Modules\Time\Models\ShiftAssignment;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Time\Services\LeaveAccountService;
use App\Shared\Time\FrozenClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class TimeTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeEmploymentDirectory $people;

    protected FakeWorkflowCasePort $workflow;

    protected FakeTimeActorResolver $actors;

    protected FrozenClock $clock;

    protected int $tenantId = 1;

    protected int $legalEntityId = 1;

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

        $this->people->add(TimeTestFixtures::employment($this->tenantId, 1, $this->legalEntityId));
        $this->people->bindUser($this->tenantId, 10, 1);
    }

    /**
     * @param  list<int>  $workweek
     */
    protected function calendar(
        int $tenantId = 1,
        int $legalEntityId = 1,
        string $timezone = 'Asia/Shanghai',
        array $workweek = [1, 2, 3, 4, 5],
    ): LegalEntityCalendar {
        return LegalEntityCalendar::query()->create([
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'timezone' => $timezone,
            'workweek_json' => $workweek,
            'standard_day_minutes' => 480,
            'created_at' => $this->clock->now(),
        ]);
    }

    protected function shift(
        int $tenantId = 1,
        int $legalEntityId = 1,
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
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
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
            'tenant_id' => $shift->tenant_id,
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
        int $tenantId = 1,
    ): LeaveType {
        return LeaveType::query()->create(array_merge([
            'tenant_id' => $tenantId,
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

    protected function leaveAccount(LeaveType $type, int $employmentId = 1, int $tenantId = 1): LeaveAccount
    {
        return app(LeaveAccountService::class)->ensureAccount(
            tenantId: $tenantId,
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
            tenantId: $this->tenantId,
            employmentId: 1,
            scope: 'SELF',
            capabilities: $capabilities,
        );
        $this->actAsTimeActor($actor);

        $principal = new \Tests\Support\TestPrincipal(
            tenantId: $actor->tenantId,
            userId: $actor->userId,
            employmentId: $actor->employmentId,
        );

        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);

        return $this;
    }
}
