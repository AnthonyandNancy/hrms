<?php

declare(strict_types=1);

namespace Tests\Feature\Time\Http;

use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\PunchRecord;
use App\Modules\Time\Services\LeaveBalanceService;
use App\Modules\Time\Services\LeaveLedgerService;
use App\Modules\Time\Services\LeavePolicyService;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TimeEndpointsTest extends TimeHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar();
    }

    public function test_punch_import_is_idempotent_by_payload_hash(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(scope: 'COMPANY'));

        $payload = [
            'employment_id' => 1,
            'punches' => [
                ['punched_at_utc' => '2026-11-23T01:00:00Z', 'device_id' => 'D1'],
                ['punched_at_utc' => '2026-11-23T10:00:00Z', 'device_id' => 'D1'],
            ],
        ];

        $first = $this->postJson('/v1/time/punches/import', $payload)->assertOk();
        $second = $this->postJson('/v1/time/punches/import', $payload)->assertOk();

        $this->assertSame(2, PunchRecord::query()->count());
        $this->assertSame(2, $first->json('data.created'));
        $this->assertSame(0, $second->json('data.created'));
        $this->assertSame(2, $second->json('data.total'));
    }

    public function test_attendance_month_read_returns_frontend_contract(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor());

        AttendanceDay::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-23',
            'scheduled_minutes' => 480,
            'worked_minutes' => 470,
            'status' => 'LATE',
            'rule_version' => 'TEST',
            'source_hash' => hash('sha256', 'day'),
            'calculated_at' => $this->clock->now(),
        ]);

        $response = $this->getJson('/v1/time/attendance?month=2026-11')
            ->assertOk()
            ->assertJsonPath('data.month', '2026-11')
            ->assertJsonPath('data.timezone', 'Asia/Shanghai')
            ->assertJsonPath('data.days.0.date', '2026-11-01');

        $this->assertIsArray($response->json('data.summary'));
        $days = (array) $response->json('data.days');
        $this->assertSame(1, count(array_filter($days, static fn (array $day): bool => $day['status'] === 'LATE')));
    }

    public function test_attendance_scoped_read_returns_projection_rows(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(scope: 'COMPANY'));
        AttendanceDay::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-23',
            'scheduled_minutes' => 480,
            'worked_minutes' => 480,
            'status' => 'NORMAL',
            'rule_version' => 'TEST',
            'source_hash' => hash('sha256', 'normal'),
            'calculated_at' => $this->clock->now(),
        ]);

        $this->getJson('/v1/time/attendance/1?from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.status', 'NORMAL')
            ->assertJsonPath('data.items.0.worked_minutes', 480);
    }

    public function test_attendance_correction_creates_case_and_keeps_raw_punches(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor());

        $response = $this->postJson('/v1/time/attendance/corrections', [
            'date' => '2026-11-23',
            'correctionType' => 'MISSING_PUNCH',
            'firstIn' => '09:00',
            'lastOut' => '18:00',
            'reason' => 'Device missed the punch',
        ])->assertOk()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.businessDate', '2026-11-23');

        $caseId = (int) $response->json('data.caseId');
        $this->assertGreaterThan(0, $caseId);
        $this->assertSame('SUBMITTED', $this->workflow->case($caseId)?->status);
        $this->assertSame(0, PunchRecord::query()->count());
    }

    public function test_leave_balances_and_preview_use_ledger_projection(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->ensureLeavePolicy((int) $type->id);

        $this->actAsHttp(TimeTestFixtures::actor());

        $this->getJson('/v1/time/leave/balances?as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.0.leaveTypeCode', 'ANNUAL')
            ->assertJsonPath('data.0.available', 10);

        $this->postJson('/v1/time/leave/preview', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-11-24',
            'endDate' => '2026-11-25',
            'halfDay' => false,
            'reason' => 'Family trip',
        ])->assertOk()
            ->assertJsonPath('data.requestedDays', 2)
            ->assertJsonPath('data.availableBefore', 10)
            ->assertJsonPath('data.availableAfter', 8);
    }

    public function test_leave_request_reserves_and_cancel_releases_exactly_once(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->ensureLeavePolicy((int) $type->id);

        $this->actAsHttp(TimeTestFixtures::actor());

        $response = $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-11-24',
            'endDate' => '2026-11-25',
            'halfDay' => false,
            'reason' => 'Family trip',
        ], ['Idempotency-Key' => 'leave-request-1'])->assertCreated();

        $caseId = (int) $response->json('data.caseId');
        $this->assertGreaterThan(0, $caseId);
        $this->assertDatabaseHas('leave_ledger_entries', ['case_id' => $caseId, 'account' => 'RESERVE', 'quantity_minutes' => -960]);

        $this->postJson("/v1/time/leave/requests/{$caseId}/cancel", ['reason' => 'Plans changed'], ['Idempotency-Key' => 'leave-cancel-1'])
            ->assertOk()
            ->assertJsonPath('data.state', 'CANCELLED')
            ->assertJsonPath('data.replayed', false);

        $this->postJson("/v1/time/leave/requests/{$caseId}/cancel", ['reason' => 'Plans changed'], ['Idempotency-Key' => 'leave-cancel-2'])
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.releasedMinutes', 0);

        $this->assertSame(1, \App\Modules\Time\Models\LeaveLedgerEntry::query()->where('case_id', $caseId)->where('account', 'RELEASE')->count());
        $this->assertSame(4800, app(LeaveBalanceService::class)->available(1, $type->id, '2026-11-23'));
    }

    public function test_leave_ledger_drilldown_matches_balance_equation(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        $ledger = app(LeaveLedgerService::class);
        $ledger->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $ledger->reserve(1, $type->id, 960, '2026-11-23', 'LEAVE_REQUEST', 'case-1', 'Leave');
        $ledger->consume(1, $type->id, 960, '2026-11-23', 'LEAVE_REQUEST', 'case-1', 'Approved');

        $this->actAsHttp(TimeTestFixtures::actor());

        $balance = app(LeaveBalanceService::class)->forType(1, $type->id, '2026-11-23');
        $response = $this->getJson('/v1/time/leave/ledger?leave_type=ANNUAL&as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.leave_type_code', 'ANNUAL')
            ->assertJsonPath('data.summary.balance', $balance['balance'])
            ->assertJsonPath('data.closing_minutes', $balance['balance']);

        $this->assertCount(4, $response->json('data.entries'));
        $this->assertSame([4800, 3840, 4800, 3840], $response->json('data.balance_after'));
    }

    public function test_calendar_and_shift_reads_are_company_scoped(): void
    {
        $shift = $this->shift();
        $this->actAsHttp(TimeTestFixtures::actor());

        $this->getJson('/v1/time/calendars?year=2026')
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Shanghai')
            ->assertJsonPath('data.standard_day_minutes', 480);

        $this->getJson('/v1/time/shifts')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $shift->id);
    }

    public function test_brief_leave_alias_routes_are_company_scoped(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');

        $this->actAsHttp(TimeTestFixtures::actor(scope: 'COMPANY'));

        $this->getJson('/v1/leave/balances/1?as_of=2026-11-23')->assertOk()->assertJsonPath('data.0.leaveTypeCode', 'ANNUAL');
        $this->getJson('/v1/leave/ledger/1?leave_type_id='.$type->id.'&as_of=2026-11-23')->assertOk()->assertJsonPath('data.leave_type_code', 'ANNUAL');
    }

    public function test_out_of_scope_employment_balance_is_not_visible_as_404(): void
    {
        $this->people->add(TimeTestFixtures::employment(88));
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $this->getJson('/v1/leave/balances/88?as_of=2026-11-23')->assertNotFound();
    }

    private function ensureLeavePolicy(int $leaveTypeId): void
    {
        app(LeavePolicyService::class)->append([
            'leave_type_id' => $leaveTypeId,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'accrual' => ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => 4800],
            'payroll_treatment' => 'PAID',
            'min_notice_days' => 0,
            'allow_half_day' => true,
        ], 10);
    }
}
