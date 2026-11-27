<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\OvertimePolicyVersion;
use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Services\LeavePolicyService;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TimeCatalogsTest extends TimeHttpTestCase
{
    public function test_leave_types_are_listed_for_the_company(): void
    {
        $type = LeaveType::query()->create([
            'code' => 'ANNUAL',
            'name' => 'Annual leave',
            'is_statutory' => true,
            'is_annual' => true,
            'unit' => 'DAY',
            'requires_attachment' => false,
            'allow_negative' => false,
            'version' => 1,
            'created_at' => $this->clock->now(),
        ]);
        app(LeavePolicyService::class)->append([
            'leave_type_id' => (int) $type->id,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'accrual' => ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => 7200],
            'min_notice_days' => 0,
            'allow_half_day' => true,
        ], 10);

        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.leave.read']));

        $this->getJson('/v1/time/leave/types')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'ANNUAL')
            ->assertJsonPath('data.items.0.entitlement_minutes', 7200)
            ->assertJsonPath('data.items.0.policy_version', 1);
    }

    public function test_overtime_records_list_settlement_state(): void
    {
        $record = OvertimeRecord::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-20',
            'minutes' => 120,
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);

        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.overtime.read']));

        $this->getJson('/v1/time/overtime/records')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $record->id)
            ->assertJsonPath('data.items.0.settlement_method', null);
    }

    public function test_overtime_policy_read_returns_company_versions_only_and_never_fabricates_defaults(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.overtime.read']));

        $this->getJson('/v1/time/overtime/policies')
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.source', 'COMPANY_VERSION');

        OvertimePolicyVersion::query()->create([
            'version' => 1,
            'status' => OvertimePolicyVersion::STATUS_PUBLISHED,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'time_unit_minutes' => 15,
            'multiplier' => '2.000000',
            'statutory_holiday_multiplier' => '3.000000',
            'allow_pay' => true,
            'allow_timeoff' => false,
            'allocation' => 'PAY',
            'rounding' => 'HALF_UP',
            'legal_basis' => 'Synthetic policy',
            'published_at' => $this->clock->now(),
            'published_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        $this->getJson('/v1/time/overtime/policies?as_of=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.items.0.version', 1)
            ->assertJsonPath('data.items.0.multiplier', '2.000000')
            ->assertJsonPath('data.items.0.allow_timeoff', false)
            ->assertJsonPath('data.current.version', 1)
            ->assertJsonPath('data.source', 'COMPANY_VERSION');
    }
}
