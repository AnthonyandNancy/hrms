<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Modules\Time\Models\TimeoffLedgerEntry;
use LogicException;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class OvertimeSettlementTest extends TimeHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Existing single-settlement tests run against an open payroll period.
        $this->app->instance(PayrollPeriodLockReader::class, new class implements PayrollPeriodLockReader
        {
            public function isFrozen(int $employmentId, string $period): bool
            {
                return false;
            }
        });
    }

    public function test_overtime_can_be_settled_only_once_either_as_pay_or_timeoff(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.overtime.read', 'time.overtime.settle']));
        $record = OvertimeRecord::query()->create([
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

        // Different key, same semantic request: service-level replay returns
        // the existing settlement as a 200 rather than creating a second row.
        $second = $this->postJson("/v1/overtime/records/{$record->id}/settlements", [
            'method' => 'TIMEOFF',
            'reason' => 'Synthetic time-off settlement.',
        ], ['Idempotency-Key' => 'ot-settle-2'])->assertOk();

        $this->assertSame($first->json('data.settlement_id'), $second->json('data.settlement_id'));
        $this->assertDatabaseCount('overtime_settlements', 1);
    }

    public function test_timeoff_settlement_writes_one_append_only_ledger_entry(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.overtime.read', 'time.overtime.settle']));
        $record = OvertimeRecord::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-22',
            'minutes' => 45,
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);

        $response = $this->postJson("/v1/overtime/records/{$record->id}/settlements", [
            'method' => 'TIMEOFF',
            'reason' => 'Synthetic timeoff ledger entry.',
        ], ['Idempotency-Key' => 'ot-ledger-timeoff'])->assertCreated();

        $settlement = OvertimeSettlement::query()->findOrFail((int) $response->json('data.settlement_id'));
        $entry = TimeoffLedgerEntry::query()->where('overtime_settlement_id', $settlement->id)->firstOrFail();
        $this->assertSame(TimeoffLedgerEntry::TYPE_EARN, $entry->entry_type);
        $this->assertSame(45, (int) $entry->quantity_minutes);
        $this->assertSame('2026-11-22', $entry->effective_on->format('Y-m-d'));
        $this->assertSame((int) $entry->id, (int) $settlement->leave_ledger_entry_id);

        $this->expectException(LogicException::class);
        $entry->forceFill(['quantity_minutes' => 0])->save();
    }

    public function test_unknown_overtime_record_cannot_be_settled(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: ['time.overtime.read', 'time.overtime.settle']));
        $this->postJson('/v1/overtime/records/999999/settlements', [
            'method' => 'PAY',
            'reason' => 'Unknown record attempt.',
        ], ['Idempotency-Key' => 'ot-unknown'])->assertNotFound();
    }
}
