<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\OvertimeRequest;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TravelRequestTest extends TimeHttpTestCase
{
    public function test_employee_creates_and_lists_own_travel_request(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: [
            'time.travel.read',
            'time.travel.request',
        ]));

        $created = $this->postJson('/v1/travel/requests', [
            'from_date' => '2026-12-05',
            'to_date' => '2026-12-07',
            'destination' => 'Synthetic City',
            'purpose' => 'Synthetic supplier visit.',
        ], ['Idempotency-Key' => 'travel-create-1'])->assertCreated();

        $id = (int) $created->json('data.travel_id');
        $this->assertDatabaseHas('business_trip_records', [
            'id' => $id,
            'employment_id' => 1,
            'status' => 'PENDING_APPROVAL',
        ]);

        $this->getJson('/v1/travel/requests')
            ->assertOk()
            ->assertJsonPath('data.items.0.travel_id', $id)
            ->assertJsonPath('data.items.0.destination', 'Synthetic City');
    }

    public function test_overlapping_travel_request_is_rejected(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: [
            'time.travel.read',
            'time.travel.request',
        ]));
        BusinessTripRecord::query()->create([
            'employment_id' => 1,
            'from_date' => '2026-12-05',
            'to_date' => '2026-12-08',
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);

        $this->postJson('/v1/travel/requests', [
            'from_date' => '2026-12-07',
            'to_date' => '2026-12-09',
            'destination' => 'Synthetic City',
            'purpose' => 'Overlapping trip.',
        ], ['Idempotency-Key' => 'travel-overlap'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'TRAVEL_OVERLAP');
    }

    public function test_travel_overlapping_approved_overtime_is_rejected(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: [
            'time.travel.read',
            'time.travel.request',
        ]));
        OvertimeRequest::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-12-06',
            'declared_minutes' => 60,
            'status' => OvertimeRequest::STATUS_APPROVED,
            'reason' => 'Synthetic overtime conflict.',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->postJson('/v1/travel/requests', [
            'from_date' => '2026-12-05',
            'to_date' => '2026-12-07',
            'destination' => 'Synthetic City',
            'purpose' => 'Overlapping overtime.',
        ], ['Idempotency-Key' => 'travel-overtime-conflict'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'TRAVEL_TIME_CONFLICT')
            ->assertJsonPath('data.conflict_type', 'OVERTIME');
    }

    public function test_travel_overlapping_recorded_attendance_is_rejected(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: [
            'time.travel.read',
            'time.travel.request',
        ]));
        AttendanceDay::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-12-06',
            'scheduled_minutes' => 480,
            'worked_minutes' => 480,
            'overtime_minutes' => 0,
            'status' => 'NORMAL',
            'rule_version' => 'SYNTHETIC',
            'source_hash' => str_repeat('a', 64),
            'calculated_at' => $this->clock->now(),
        ]);

        $this->postJson('/v1/travel/requests', [
            'from_date' => '2026-12-05',
            'to_date' => '2026-12-07',
            'destination' => 'Synthetic City',
            'purpose' => 'Overlapping attendance.',
        ], ['Idempotency-Key' => 'travel-attendance-conflict'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'TRAVEL_TIME_CONFLICT')
            ->assertJsonPath('data.conflict_type', 'ATTENDANCE');
    }

    public function test_cancellation_is_audited_and_cannot_cancel_other_employee(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, capabilities: [
            'time.travel.read',
            'time.travel.request',
        ]));
        $other = BusinessTripRecord::query()->create([
            'employment_id' => 2,
            'from_date' => '2026-12-05',
            'to_date' => '2026-12-06',
            'status' => 'PENDING_APPROVAL',
            'created_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/travel/requests/{$other->id}/cancel", [
            'reason' => 'Trying another employee trip.',
        ], ['Idempotency-Key' => 'travel-cancel-other'])->assertNotFound();
    }
}
