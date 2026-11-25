<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\OvertimeRecord;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class OvertimeSettlementTest extends TimeHttpTestCase
{
    public function test_overtime_can_be_settled_only_once_either_as_pay_or_timeoff(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.overtime.read', 'time.overtime.settle']));
        $record = OvertimeRecord::query()->create([
            'tenant_id' => 1,
            'employment_id' => 1,
            'business_date' => '2026-11-20',
            'minutes' => 120,
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/overtime/records/{$record->id}/settlements", [
            'method' => 'PAY',
            'reason' => 'Synthetic payroll settlement.',
        ], ['Idempotency-Key' => 'ot-settle-pay'])->assertCreated();

        $this->postJson("/v1/overtime/records/{$record->id}/settlements", [
            'method' => 'TIMEOFF',
            'reason' => 'Synthetic time-off settlement.',
        ], ['Idempotency-Key' => 'ot-settle-timeoff'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'ALREADY_SETTLED');
    }

    public function test_overtime_settlement_is_idempotent_for_the_same_method(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.overtime.read', 'time.overtime.settle']));
        $record = OvertimeRecord::query()->create([
            'tenant_id' => 1,
            'employment_id' => 1,
            'business_date' => '2026-11-21',
            'minutes' => 60,
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);

        $first = $this->postJson("/v1/overtime/records/{$record->id}/settlements", [
            'method' => 'TIMEOFF',
            'reason' => 'Synthetic time-off settlement.',
        ], ['Idempotency-Key' => 'ot-settle-1'])->assertCreated();

        $second = $this->postJson("/v1/overtime/records/{$record->id}/settlements", [
            'method' => 'TIMEOFF',
            'reason' => 'Synthetic time-off settlement.',
        ], ['Idempotency-Key' => 'ot-settle-2'])->assertCreated();

        $this->assertSame($first->json('data.settlement_id'), $second->json('data.settlement_id'));
        $this->assertDatabaseCount('overtime_settlements', 1);
    }

    public function test_cross_tenant_overtime_cannot_be_settled(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(tenantId: 1, employmentId: 1, capabilities: ['time.overtime.read', 'time.overtime.settle']));
        $record = OvertimeRecord::query()->create([
            'tenant_id' => 2,
            'employment_id' => 9,
            'business_date' => '2026-11-20',
            'minutes' => 60,
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/overtime/records/{$record->id}/settlements", [
            'method' => 'PAY',
            'reason' => 'Cross tenant attempt.',
        ], ['Idempotency-Key' => 'ot-cross'])->assertNotFound();
    }
}
