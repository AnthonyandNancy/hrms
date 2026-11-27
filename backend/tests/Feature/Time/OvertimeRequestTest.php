<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Models\OvertimePolicyVersion;
use App\Modules\Time\Models\OvertimeRequest;
use App\Modules\Time\Models\OvertimeSettlement;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Support\Time\TimeTestCase;

final class OvertimeRequestTest extends TimeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PayrollPeriodLockReader::class, new class implements PayrollPeriodLockReader
        {
            public function isFrozen(int $employmentId, string $period): bool
            {
                return false;
            }
        });

        OvertimePolicyVersion::query()->create([
            'version' => 1,
            'status' => OvertimePolicyVersion::STATUS_PUBLISHED,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'time_unit_minutes' => 30,
            'multiplier' => '1.500000',
            'statutory_holiday_multiplier' => '3.000000',
            'allow_pay' => true,
            'allow_timeoff' => true,
            'allocation' => 'EMPLOYEE_CHOICE',
            'rounding' => 'HALF_UP',
            'legal_basis' => 'Synthetic overtime rule',
            'published_at' => $this->clock->now(),
            'published_by' => 10,
            'created_at' => $this->clock->now(),
        ]);
    }

    public function test_confirmation_is_separate_from_application_and_settlement_is_unique(): void
    {
        $this->actingAsTimeEmployee();

        $created = $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-20',
            'declared_minutes' => 120,
            'reason' => 'Synthetic release support',
        ], ['Idempotency-Key' => 'ot-1'])->assertCreated();

        $id = (int) $created->json('data.id');

        $this->postJson("/v1/time/overtime/requests/{$id}/confirm", [
            'confirmed_minutes' => 90,
        ], ['Idempotency-Key' => 'ot-confirm-1'])->assertOk();

        $this->postJson("/v1/time/overtime/requests/{$id}/confirm", [
            'confirmed_minutes' => 90,
        ], ['Idempotency-Key' => 'ot-confirm-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'ALREADY_CONFIRMED');

        $this->assertDatabaseHas('overtime_requests', [
            'id' => $id,
            'declared_minutes' => 120,
            'confirmed_minutes' => 90,
            'status' => 'CONFIRMED',
        ]);
    }

    public function test_declared_and_confirmed_minutes_are_stored_in_separate_columns_and_create_one_record(): void
    {
        $this->actingAsTimeEmployee();

        $created = $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-21',
            'declared_minutes' => 120,
            'reason' => 'Synthetic month-end support',
        ], ['Idempotency-Key' => 'ot-separate-create'])->assertCreated();

        $id = (int) $created->json('data.id');
        $confirmed = $this->postJson("/v1/time/overtime/requests/{$id}/confirm", [
            'confirmed_minutes' => 75,
        ], ['Idempotency-Key' => 'ot-separate-confirm'])->assertOk();

        $recordId = (int) $confirmed->json('data.overtime_record_id');
        $this->assertGreaterThan(0, $recordId);
        $this->assertDatabaseHas('overtime_records', [
            'id' => $recordId,
            'employment_id' => 1,
            'business_date' => '2026-11-21',
            'minutes' => 75,
            'status' => 'APPROVED',
        ]);
        $this->assertDatabaseHas('overtime_requests', [
            'id' => $id,
            'declared_minutes' => 120,
            'confirmed_minutes' => 75,
            'overtime_record_id' => $recordId,
        ]);
    }

    public function test_confirmation_requires_an_approved_request_and_a_new_key_never_replays_a_state_conflict(): void
    {
        $this->actingAsTimeEmployee();
        $request = OvertimeRequest::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-22',
            'declared_minutes' => 60,
            'confirmed_minutes' => null,
            'status' => 'SUBMITTED',
            'reason' => 'Synthetic pending request',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/time/overtime/requests/{$request->id}/confirm", [
            'confirmed_minutes' => 60,
        ], ['Idempotency-Key' => 'ot-not-approved'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'OVERTIME_REQUEST_NOT_APPROVED');
    }

    public function test_same_day_application_is_unique_in_the_database_and_service(): void
    {
        $this->actingAsTimeEmployee();

        $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-23',
            'declared_minutes' => 60,
            'reason' => 'Synthetic duplicate guard',
        ], ['Idempotency-Key' => 'ot-unique-1'])->assertCreated();

        $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-23',
            'declared_minutes' => 30,
            'reason' => 'Synthetic duplicate guard second',
        ], ['Idempotency-Key' => 'ot-unique-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'OVERTIME_REQUEST_DUPLICATE');

        $this->expectException(UniqueConstraintViolationException::class);
        OvertimeRequest::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-23',
            'declared_minutes' => 10,
            'confirmed_minutes' => null,
            'status' => 'APPROVED',
            'reason' => 'Synthetic direct duplicate',
            'recorded_at' => $this->clock->now(),
        ]);
    }

    public function test_one_overtime_record_settles_exactly_once_and_timeoff_balance_is_derived(): void
    {
        $this->actingAsTimeEmployee();

        $first = $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-24',
            'declared_minutes' => 120,
            'reason' => 'Synthetic time-off settlement',
        ], ['Idempotency-Key' => 'ot-loop-timeoff'])->assertCreated();

        $firstId = (int) $first->json('data.id');
        $confirmed = $this->postJson("/v1/time/overtime/requests/{$firstId}/confirm", [
            'confirmed_minutes' => 90,
        ], ['Idempotency-Key' => 'ot-loop-timeoff-confirm'])->assertOk();
        $recordId = (int) $confirmed->json('data.overtime_record_id');

        $this->postJson("/v1/overtime/records/{$recordId}/settlements", [
            'method' => 'TIMEOFF',
            'reason' => 'Synthetic time-off compensation',
        ], ['Idempotency-Key' => 'ot-loop-timeoff-settle'])->assertCreated();

        $this->postJson("/v1/overtime/records/{$recordId}/settlements", [
            'method' => 'PAY',
            'reason' => 'Synthetic double settlement',
        ], ['Idempotency-Key' => 'ot-loop-pay-settle'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'ALREADY_SETTLED');

        $this->assertDatabaseCount('overtime_settlements', 1);

        $this->getJson('/v1/time/overtime/timeoff-balances?as_of=2026-11-30')
            ->assertOk()
            ->assertJsonPath('data.employment_id', 1)
            ->assertJsonPath('data.available_minutes', 90)
            ->assertJsonPath('data.settlement_count', 1)
            ->assertJsonPath('data.items.0.minutes', 90)
            ->assertJsonPath('data.items.0.overtime_record_id', $recordId);

        $this->expectException(UniqueConstraintViolationException::class);
        OvertimeSettlement::query()->create([
            'overtime_record_id' => $recordId,
            'employment_id' => 1,
            'method' => 'PAY',
            'minutes' => 90,
            'reason' => 'Synthetic direct double settlement',
            'recorded_at' => $this->clock->now(),
        ]);
    }

    public function test_overtime_write_requires_idempotency_key_capability_and_employment_scope(): void
    {
        $this->actingAsTimeEmployee(['time.overtime.read']);

        $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-25',
            'declared_minutes' => 60,
            'reason' => 'Synthetic forbidden request',
        ], ['Idempotency-Key' => 'ot-forbidden'])->assertForbidden();

        $this->actingAsTimeEmployee();
        $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-25',
            'declared_minutes' => 60,
            'reason' => 'Synthetic missing key',
        ])->assertStatus(422)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $outOfScope = OvertimeRequest::query()->create([
            'employment_id' => 99,
            'business_date' => '2026-11-25',
            'declared_minutes' => 60,
            'confirmed_minutes' => null,
            'status' => 'APPROVED',
            'reason' => 'Synthetic out-of-scope employment',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/time/overtime/requests/{$outOfScope->id}/confirm", [
            'confirmed_minutes' => 60,
        ], ['Idempotency-Key' => 'ot-out-of-scope'])->assertNotFound();
    }

    public function test_unauthenticated_overtime_request_is_rejected(): void
    {
        $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-25',
            'declared_minutes' => 60,
            'reason' => 'Synthetic unauthenticated request',
        ], ['Idempotency-Key' => 'ot-unauthenticated'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_idempotent_application_replay_returns_the_original_body_with_header(): void
    {
        $this->actingAsTimeEmployee();
        $payload = [
            'business_date' => '2026-11-26',
            'declared_minutes' => 45,
            'reason' => 'Synthetic replay request',
        ];

        $first = $this->postJson('/v1/time/overtime/requests', $payload, ['Idempotency-Key' => 'ot-replay'])
            ->assertCreated();
        $second = $this->postJson('/v1/time/overtime/requests', $payload, ['Idempotency-Key' => 'ot-replay'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('overtime_requests', 1);
    }
}
