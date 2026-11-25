<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\OvertimeRecord;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TimeCatalogsTest extends TimeHttpTestCase
{
    public function test_leave_types_are_listed_for_the_tenant(): void
    {
        LeaveType::query()->create([
            'tenant_id' => 1,
            'code' => 'ANNUAL',
            'name' => 'Annual leave',
            'is_statutory' => true,
            'is_annual' => true,
            'unit' => 'DAY',
            'annual_entitlement_days' => '15.00',
            'requires_attachment' => false,
            'allow_negative' => false,
            'version' => 1,
            'created_at' => $this->clock->now(),
        ]);

        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.leave.read']));

        $this->getJson('/v1/time/leave/types')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'ANNUAL')
            ->assertJsonPath('data.items.0.annual_entitlement_days', '15.00');
    }

    public function test_overtime_records_list_settlement_state(): void
    {
        $record = OvertimeRecord::query()->create([
            'tenant_id' => 1,
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

    public function test_overtime_policy_read_returns_code_owned_defaults(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.overtime.read']));

        $this->getJson('/v1/time/overtime/policies')
            ->assertOk()
            ->assertJsonPath('data.multiplier', '1.500000')
            ->assertJsonPath('data.allow_pay', true)
            ->assertJsonPath('data.allow_timeoff', true);
    }
}
