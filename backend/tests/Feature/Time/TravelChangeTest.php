<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\TravelAttendanceLink;
use App\Modules\Time\Models\TravelRequestChange;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TravelChangeTest extends TimeHttpTestCase
{
    public function test_change_is_pending_until_approved_and_updates_attendance_link(): void
    {
        $this->actingAsTravelManager();
        $trip = $this->createApprovedTrip();
        $this->travelAttendanceLink((int) $trip->id);

        $change = $this->postJson("/v1/travel/requests/{$trip->id}/change", [
            'from_date' => '2026-12-03',
            'to_date' => '2026-12-05',
            'destination' => 'Synthetic Changed City',
            'purpose' => 'Synthetic customer onsite extension.',
            'reason' => 'Synthetic customer schedule changed.',
        ], ['Idempotency-Key' => 'travel-change-1'])->assertCreated();

        $caseId = (int) $change->json('data.case_id');
        $this->assertGreaterThan(0, $caseId);
        $this->assertSame('PENDING', $change->json('data.change_status'));
        $this->assertDatabaseHas('business_trip_records', [
            'id' => (int) $trip->id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
        ]);
        $this->assertDatabaseHas('travel_request_changes', [
            'travel_id' => (int) $trip->id,
            'case_id' => $caseId,
            'status' => 'PENDING',
        ]);
        $this->assertSame('SUBMITTED', $this->workflow->case($caseId)?->status);

        $this->postJson("/v1/travel/requests/{$trip->id}/approve", [
            'decision' => 'APPROVE',
            'comment' => 'Synthetic change approval.',
        ], ['Idempotency-Key' => 'travel-change-approve'])->assertOk();

        $this->assertDatabaseHas('business_trip_records', [
            'id' => (int) $trip->id,
            'from_date' => '2026-12-03',
            'to_date' => '2026-12-05',
            'destination' => 'Synthetic Changed City',
        ]);
        $this->assertDatabaseHas('travel_request_changes', [
            'case_id' => $caseId,
            'status' => 'APPLIED',
        ]);
        $this->assertDatabaseHas('travel_attendance_links', [
            'travel_id' => (int) $trip->id,
            'from_date' => '2026-12-03',
            'to_date' => '2026-12-05',
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseCount('travel_attendance_links', 1);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'time.travel.change_applied',
            'aggregate_type' => 'business_trip',
            'aggregate_id' => (string) $trip->id,
        ]);
    }

    public function test_rejected_change_leaves_original_trip_and_link_unchanged(): void
    {
        $this->actingAsTravelManager();
        $trip = $this->createApprovedTrip();
        $this->travelAttendanceLink((int) $trip->id);

        $change = $this->postJson("/v1/travel/requests/{$trip->id}/change", [
            'from_date' => '2026-12-08',
            'to_date' => '2026-12-09',
            'destination' => 'Synthetic Rejected City',
            'purpose' => 'Synthetic rejected trip change.',
            'reason' => 'Synthetic planning check.',
        ], ['Idempotency-Key' => 'travel-change-reject'])->assertCreated();

        $this->postJson("/v1/travel/requests/{$trip->id}/approve", [
            'decision' => 'REJECT',
            'comment' => 'Synthetic rejection.',
        ], ['Idempotency-Key' => 'travel-change-reject-approve'])->assertOk();

        $this->assertDatabaseHas('travel_request_changes', [
            'case_id' => (int) $change->json('data.case_id'),
            'status' => 'REJECTED',
        ]);
        $this->assertDatabaseHas('business_trip_records', [
            'id' => (int) $trip->id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'destination' => 'Synthetic City',
            'status' => 'APPROVED',
        ]);
        $this->assertDatabaseHas('travel_attendance_links', [
            'travel_id' => (int) $trip->id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_only_one_pending_change_is_allowed_and_other_employee_is_not_found(): void
    {
        $this->actingAsTravelManager();
        $trip = $this->createApprovedTrip();

        $payload = [
            'from_date' => '2026-12-06',
            'to_date' => '2026-12-07',
            'destination' => 'Synthetic Change City',
            'purpose' => 'Synthetic pending change.',
            'reason' => 'Synthetic reason.',
        ];
        $this->postJson("/v1/travel/requests/{$trip->id}/change", $payload, [
            'Idempotency-Key' => 'travel-change-first',
        ])->assertCreated();

        $this->postJson("/v1/travel/requests/{$trip->id}/change", [
            ...$payload,
            'destination' => 'Synthetic Second City',
        ], ['Idempotency-Key' => 'travel-change-second'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'TRAVEL_CHANGE_ALREADY_PENDING');

        $other = BusinessTripRecord::query()->create([
            'employment_id' => 2,
            'from_date' => '2026-12-10',
            'to_date' => '2026-12-11',
            'destination' => 'Synthetic Other City',
            'purpose' => 'Synthetic other employee trip.',
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/travel/requests/{$other->id}/change", $payload, [
            'Idempotency-Key' => 'travel-change-other',
        ])->assertNotFound();

        $this->assertSame(0, TravelRequestChange::query()->where('travel_id', (int) $other->id)->count());
    }

    public function test_change_replays_for_the_same_idempotency_key_without_a_second_case(): void
    {
        $this->actingAsTravelManager();
        $trip = $this->createApprovedTrip();
        $payload = [
            'from_date' => '2026-12-18',
            'to_date' => '2026-12-19',
            'destination' => 'Synthetic Replay City',
            'purpose' => 'Synthetic replay change.',
            'reason' => 'Synthetic replay reason.',
        ];

        $first = $this->postJson("/v1/travel/requests/{$trip->id}/change", $payload, [
            'Idempotency-Key' => 'travel-change-replay',
        ])->assertCreated();

        $second = $this->postJson("/v1/travel/requests/{$trip->id}/change", $payload, [
            'Idempotency-Key' => 'travel-change-replay',
        ])->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.change_id'), $second->json('data.change_id'));
        $this->assertSame(1, TravelRequestChange::query()->where('travel_id', (int) $trip->id)->count());
    }

    public function test_change_command_requires_an_idempotency_key(): void
    {
        $this->actingAsTravelManager();
        $trip = $this->createApprovedTrip();

        $this->postJson("/v1/travel/requests/{$trip->id}/change", [
            'from_date' => '2026-12-06',
            'to_date' => '2026-12-07',
            'destination' => 'Synthetic Change City',
            'purpose' => 'Synthetic change.',
            'reason' => 'Synthetic reason.',
        ])->assertUnprocessable()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, TravelRequestChange::query()->count());
    }

    public function test_employee_can_change_own_trip_but_cannot_change_another_employee_trip(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF', capabilities: [
            'time.travel.read',
            'time.travel.request',
        ]));
        $trip = $this->createApprovedTrip();

        $this->postJson("/v1/travel/requests/{$trip->id}/change", [
            'from_date' => '2026-12-06',
            'to_date' => '2026-12-07',
            'destination' => 'Synthetic Change City',
            'purpose' => 'Synthetic change.',
            'reason' => 'Synthetic reason.',
        ], ['Idempotency-Key' => 'travel-change-own'])->assertCreated();

        $other = BusinessTripRecord::query()->create([
            'employment_id' => 2,
            'from_date' => '2026-12-12',
            'to_date' => '2026-12-13',
            'destination' => 'Synthetic Other City',
            'purpose' => 'Synthetic other trip.',
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/travel/requests/{$other->id}/change", [
            'from_date' => '2026-12-14',
            'to_date' => '2026-12-15',
            'destination' => 'Synthetic Hijack City',
            'purpose' => 'Synthetic hijack.',
            'reason' => 'Synthetic reason.',
        ], ['Idempotency-Key' => 'travel-change-other-employee'])->assertNotFound();
    }

    public function test_change_is_forbidden_without_the_request_capability(): void
    {
        $this->actingAsTravelManager();
        $trip = $this->createApprovedTrip();

        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF', capabilities: [
            'time.travel.read',
        ]));

        $this->postJson("/v1/travel/requests/{$trip->id}/change", [
            'from_date' => '2026-12-06',
            'to_date' => '2026-12-07',
            'destination' => 'Synthetic Change City',
            'purpose' => 'Synthetic change.',
            'reason' => 'Synthetic reason.',
        ], ['Idempotency-Key' => 'travel-change-forbidden'])->assertForbidden();
    }

    private function travelAttendanceLink(int $travelId): void
    {
        TravelAttendanceLink::query()->create([
            'travel_id' => $travelId,
            'employment_id' => 1,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'source_case_id' => 1001,
            'status' => 'ACTIVE',
            'created_at' => $this->clock->now(),
        ]);
    }
}
