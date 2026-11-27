<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\TravelAttendanceLinkReader;
use App\Modules\Time\Models\TravelAttendanceLink;
use Tests\Support\Time\TimeHttpTestCase;

final class TravelAttendanceLinkTest extends TimeHttpTestCase
{
    public function test_approval_creates_a_calendar_link_without_fabricating_attendance(): void
    {
        $this->actingAsTravelManager();

        $created = $this->postJson('/v1/travel/requests', [
            'from_date' => '2026-12-12',
            'to_date' => '2026-12-14',
            'destination' => 'Synthetic Link City',
            'purpose' => 'Synthetic attendance link trip.',
        ], ['Idempotency-Key' => 'travel-link-create'])->assertCreated();

        $travelId = (int) $created->json('data.travel_id');
        $caseId = (int) $created->json('data.case_id');

        $this->postJson("/v1/travel/requests/{$travelId}/approve", [
            'decision' => 'APPROVE',
        ], ['Idempotency-Key' => 'travel-link-approve'])
            ->assertOk()
            ->assertJsonPath('data.attendance_link.status', 'ACTIVE')
            ->assertJsonPath('data.attendance_link.from_date', '2026-12-12');

        $this->assertDatabaseHas('travel_attendance_links', [
            'travel_id' => $travelId,
            'employment_id' => 1,
            'from_date' => '2026-12-12',
            'to_date' => '2026-12-14',
            'source_case_id' => $caseId,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseCount('attendance_days', 0);
        $this->assertDatabaseCount('punch_records', 0);
    }

    public function test_cancellation_retires_the_link_and_records_the_reason(): void
    {
        $this->actingAsTravelManager();
        $trip = $this->createApprovedTrip();
        TravelAttendanceLink::query()->create([
            'travel_id' => (int) $trip->id,
            'employment_id' => 1,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'source_case_id' => (int) $trip->case_id,
            'status' => 'ACTIVE',
            'created_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/travel/requests/{$trip->id}/cancel", [
            'reason' => 'Synthetic trip cancelled after approval.',
        ], ['Idempotency-Key' => 'travel-link-cancel'])->assertOk();

        $this->assertDatabaseHas('travel_attendance_links', [
            'travel_id' => (int) $trip->id,
            'status' => 'RETIRED',
            'retire_reason' => 'Synthetic trip cancelled after approval.',
        ]);
        $this->assertNotNull(
            TravelAttendanceLink::query()->where('travel_id', (int) $trip->id)->value('retired_at'),
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'time.travel.attendance_retired',
            'aggregate_type' => 'business_trip',
            'aggregate_id' => (string) $trip->id,
        ]);
    }

    public function test_link_reader_is_employment_scoped_and_returns_only_active_rows(): void
    {
        TravelAttendanceLink::query()->create([
            'travel_id' => 12,
            'employment_id' => 1,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-03',
            'source_case_id' => 1001,
            'status' => 'ACTIVE',
            'created_at' => $this->clock->now(),
        ]);
        TravelAttendanceLink::query()->create([
            'travel_id' => 13,
            'employment_id' => 1,
            'from_date' => '2026-12-04',
            'to_date' => '2026-12-05',
            'source_case_id' => 1002,
            'status' => 'RETIRED',
            'retired_at' => $this->clock->now(),
            'retire_reason' => 'Synthetic retirement.',
            'created_at' => $this->clock->now(),
        ]);
        TravelAttendanceLink::query()->create([
            'travel_id' => 14,
            'employment_id' => 2,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-03',
            'source_case_id' => 1003,
            'status' => 'ACTIVE',
            'created_at' => $this->clock->now(),
        ]);

        $reader = $this->app->make(TravelAttendanceLinkReader::class);
        $rows = $reader->forEmployment(1, '2026-12-01', '2026-12-31');

        $this->assertCount(1, $rows);
        $this->assertSame(12, $rows[0]['travel_id']);
        $this->assertSame('ACTIVE', $rows[0]['status']);
    }
}
