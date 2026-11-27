<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\TravelExpense;
use App\Modules\Time\Models\TravelPolicy;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TravelPolicyExpenseTest extends TimeHttpTestCase
{
    public function test_policy_versions_are_effective_dated_and_publishing_never_mutates_history(): void
    {
        $this->actingAsTravelManager();

        $first = $this->postJson('/v1/travel/policies', [
            'city_tier' => 'A',
            'hotel_cap_cents' => 80000,
            'transport_cap_cents' => 30000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'tp-1'])->assertCreated();

        $this->assertSame(1, $first->json('data.version'));
        $this->assertSame(80000, $first->json('data.hotel_cap_cents'));

        $second = $this->postJson('/v1/travel/policies', [
            'city_tier' => 'A',
            'hotel_cap_cents' => 90000,
            'transport_cap_cents' => 35000,
            'effective_from' => '2026-07-01',
        ], ['Idempotency-Key' => 'tp-2'])->assertCreated();

        $this->assertSame(2, $second->json('data.version'));

        $listed = $this->getJson('/v1/travel/policies')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.version', 2)
            ->assertJsonPath('data.items.1.version', 1)
            ->assertJsonPath('data.items.1.hotel_cap_cents', 80000);

        $this->assertSame(1, $listed->json('data.page'));
        $this->assertSame('LIVE', $listed->json('data.freshness'));

        $this->assertSame(2, TravelPolicy::query()->count());
        $this->assertSame(80000, (int) TravelPolicy::query()->where('version', 1)->value('hotel_cap_cents'));
        $this->assertSame(90000, (int) TravelPolicy::query()->where('version', 2)->value('hotel_cap_cents'));

        $this->postJson('/v1/travel/policies', [
            'city_tier' => 'A',
            'hotel_cap_cents' => 100000,
            'effective_from' => '2025-12-01',
        ], ['Idempotency-Key' => 'tp-backdated'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'POLICY_EFFECTIVE_DATE_NOT_MONOTONIC');
    }

    public function test_expense_requires_an_approved_trip_and_rejection_blocks_claims(): void
    {
        $this->actingAsTravelManager();

        $pendingTripId = $this->createPendingTrip();

        $this->postJson('/v1/travel/expenses', [
            'travel_id' => $pendingTripId,
            'amount_cents' => 12000,
            'category' => 'TRANSPORT',
        ], ['Idempotency-Key' => 'te-pending'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRIP_NOT_APPROVED');

        $created = $this->postJson('/v1/travel/requests', [
            'from_date' => '2026-12-05',
            'to_date' => '2026-12-07',
            'destination' => 'Synthetic City',
            'purpose' => 'Synthetic supplier audit.',
        ], ['Idempotency-Key' => 'travel-case-create'])->assertCreated();

        $travelId = (int) $created->json('data.travel_id');
        $caseId = (int) $created->json('data.case_id');
        $this->assertGreaterThan(0, $caseId);
        $this->assertSame('SUBMITTED', $this->workflow->case($caseId)?->status);

        $this->postJson('/v1/travel/expenses', [
            'travel_id' => $travelId,
            'amount_cents' => 12000,
            'category' => 'TRANSPORT',
        ], ['Idempotency-Key' => 'te-pending-case'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRIP_NOT_APPROVED');

        $approved = $this->postJson("/v1/travel/requests/{$travelId}/approve", [
            'decision' => 'APPROVE',
            'comment' => 'Synthetic manager approval.',
        ], ['Idempotency-Key' => 'travel-approve-1'])
            ->assertOk()
            ->assertJsonPath('data.travel_id', $travelId)
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.application_status', 'APPROVED');

        $this->assertSame('APPROVED', $approved->json('data.application_status'));
        $this->assertSame('APPROVED', $this->workflow->case($caseId)?->status);
        $this->assertDatabaseHas('business_trip_records', [
            'id' => $travelId,
            'status' => 'APPROVED',
            'case_id' => $caseId,
        ]);

        $expense = $this->postJson('/v1/travel/expenses', [
            'travel_id' => $travelId,
            'amount_cents' => 12000,
            'category' => 'TRANSPORT',
            'note' => 'Synthetic airport transfer.',
        ], ['Idempotency-Key' => 'te-approved'])
            ->assertCreated()
            ->assertJsonPath('data.travel_id', $travelId)
            ->assertJsonPath('data.amount_cents', 12000)
            ->assertJsonPath('data.status', 'CLAIMED');

        $this->assertIsInt($expense->json('data.amount_cents'));
        $this->assertDatabaseHas('travel_expenses', [
            'travel_id' => $travelId,
            'amount_cents' => 12000,
            'status' => 'CLAIMED',
        ]);

        $rejected = $this->postJson('/v1/travel/requests', [
            'from_date' => '2026-12-10',
            'to_date' => '2026-12-11',
            'destination' => 'Synthetic Elsewhere',
            'purpose' => 'Synthetic rejected trip.',
        ], ['Idempotency-Key' => 'travel-reject-create'])->assertCreated();

        $rejectedTripId = (int) $rejected->json('data.travel_id');

        $this->postJson("/v1/travel/requests/{$rejectedTripId}/approve", [
            'decision' => 'REJECT',
            'comment' => 'Synthetic business reason.',
        ], ['Idempotency-Key' => 'travel-reject-decide'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.application_status', 'REJECTED');

        $this->postJson('/v1/travel/expenses', [
            'travel_id' => $rejectedTripId,
            'amount_cents' => 5000,
            'category' => 'MEALS',
        ], ['Idempotency-Key' => 'te-rejected'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRIP_NOT_APPROVED');

        $this->assertSame(1, TravelExpense::query()->count());
    }

    public function test_approval_uses_the_workflow_case_and_a_direct_status_flip_is_not_enough(): void
    {
        $this->actingAsTravelManager();

        $tripId = $this->createPendingTrip();

        $this->postJson("/v1/travel/requests/{$tripId}/approve", [
            'decision' => 'APPROVE',
        ], ['Idempotency-Key' => 'travel-no-case'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRAVEL_CASE_MISSING');

        $this->assertDatabaseHas('business_trip_records', [
            'id' => $tripId,
            'status' => 'PENDING_APPROVAL',
            'case_id' => null,
        ]);

        BusinessTripRecord::query()->whereKey($tripId)->update(['status' => 'APPROVED']);

        $this->postJson('/v1/travel/expenses', [
            'travel_id' => $tripId,
            'amount_cents' => 3000,
            'category' => 'OTHER',
        ], ['Idempotency-Key' => 'te-no-case'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRIP_APPROVAL_EVIDENCE_MISSING');

        $this->assertSame(0, TravelExpense::query()->count());
    }

    public function test_travel_commands_require_an_idempotency_key_and_replay_without_duplicates(): void
    {
        $this->actingAsTravelManager();

        $this->postJson('/v1/travel/policies', [
            'city_tier' => 'B',
            'hotel_cap_cents' => 60000,
            'effective_from' => '2026-01-01',
        ])->assertUnprocessable()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $pendingTripId = $this->createPendingTrip();
        $this->postJson('/v1/travel/expenses', [
            'travel_id' => $pendingTripId,
            'amount_cents' => 1000,
            'category' => 'OTHER',
        ])->assertUnprocessable()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $payload = [
            'city_tier' => 'C',
            'hotel_cap_cents' => 40000,
            'effective_from' => '2026-02-01',
        ];

        $this->postJson('/v1/travel/policies', $payload, ['Idempotency-Key' => 'tp-replay'])
            ->assertCreated();

        $replay = $this->postJson('/v1/travel/policies', $payload, ['Idempotency-Key' => 'tp-replay'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, $replay->json('data.version'));
        $this->assertSame(1, TravelPolicy::query()->count());
    }

    public function test_expenses_are_integer_minor_unit_claims_and_lists_use_the_projection_envelope(): void
    {
        $this->actingAsTravelManager();

        $trip = $this->createApprovedTrip();

        $created = $this->postJson('/v1/travel/expenses', [
            'travel_id' => (int) $trip->id,
            'amount_cents' => 12050,
            'category' => 'LODGING',
        ], ['Idempotency-Key' => 'te-cents'])->assertCreated();

        $this->assertIsInt($created->json('data.amount_cents'));
        $this->assertSame(12050, $created->json('data.amount_cents'));
        $this->assertSame('CLAIM_ONLY', $created->json('data.payment_status'));
        $this->assertSame(12050, (int) TravelExpense::query()->value('amount_cents'));

        $this->postJson('/v1/travel/expenses', [
            'travel_id' => (int) $trip->id,
            'amount_cents' => 120.5,
            'category' => 'MEALS',
        ], ['Idempotency-Key' => 'te-float'])->assertUnprocessable();

        $this->getJson('/v1/travel/expenses?travel_id='.$trip->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.amount_cents', 12050)
            ->assertJsonPath('data.freshness', 'LIVE')
            ->assertJsonPath('data.page', 1);

        $this->assertDatabaseCount('travel_expenses', 1);
        $this->assertDatabaseCount('payroll_runs', 0);
    }

    public function test_manager_approval_queue_and_decisions_are_scoped_to_managed_employments(): void
    {
        $this->people->add(TimeTestFixtures::employment(2));
        $manager = TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'MANAGED',
            capabilities: [
                'time.travel.read',
                'time.travel.approve',
                'time.travel.expense.manage',
            ],
            managedEmploymentIds: [2],
        );
        $this->actAsHttp($manager);

        $caseId = $this->workflow->createCase(
            requesterUserId: 20,
            type: 'TRAVEL_REQUEST',
            subjectType: 'employment',
            subjectId: 2,
            payload: ['title' => 'Synthetic managed travel request'],
            businessDate: '2026-12-01',
        );

        $managedTrip = BusinessTripRecord::query()->create([
            'employment_id' => 2,
            'from_date' => '2026-12-03',
            'to_date' => '2026-12-04',
            'destination' => 'Synthetic Managed City',
            'purpose' => 'Synthetic managed trip.',
            'status' => 'PENDING_APPROVAL',
            'case_id' => $caseId,
            'created_at' => $this->clock->now(),
        ]);

        $unmanagedTrip = BusinessTripRecord::query()->create([
            'employment_id' => 1,
            'from_date' => '2026-12-05',
            'to_date' => '2026-12-06',
            'destination' => 'Synthetic Own City',
            'purpose' => 'Synthetic own trip.',
            'status' => 'PENDING_APPROVAL',
            'created_at' => $this->clock->now(),
        ]);

        $this->getJson('/v1/travel/requests?view=my_approvals')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.travel_id', (int) $managedTrip->id);

        $this->postJson("/v1/travel/requests/{$unmanagedTrip->id}/approve", [
            'decision' => 'APPROVE',
        ], ['Idempotency-Key' => 'travel-unmanaged-approve'])->assertNotFound();

        $this->assertDatabaseHas('business_trip_records', [
            'id' => (int) $unmanagedTrip->id,
            'status' => 'PENDING_APPROVAL',
        ]);
    }

    public function test_expense_projection_is_scoped_to_the_actors_employments(): void
    {
        $this->actingAsTravelManager();
        $trip = $this->createApprovedTrip();

        TravelExpense::query()->create([
            'employment_id' => 1,
            'travel_id' => (int) $trip->id,
            'category' => 'OTHER',
            'amount_cents' => 1000,
            'status' => 'CLAIMED',
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);
        TravelExpense::query()->create([
            'employment_id' => 2,
            'travel_id' => 999,
            'category' => 'OTHER',
            'amount_cents' => 2000,
            'status' => 'CLAIMED',
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        $this->getJson('/v1/travel/expenses')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.amount_cents', 1000);
    }

    public function test_travel_capabilities_are_enforced_on_each_new_route(): void
    {
        $employee = TimeTestFixtures::actor(employmentId: 1, scope: 'SELF', capabilities: [
            'time.travel.read',
            'time.travel.request',
        ]);
        $this->actAsHttp($employee);

        $this->getJson('/v1/travel/policies')->assertForbidden();
        $this->postJson('/v1/travel/policies', [
            'city_tier' => 'A',
            'hotel_cap_cents' => 80000,
            'effective_from' => '2026-01-01',
        ], ['Idempotency-Key' => 'tp-denied'])->assertForbidden();
        $this->postJson('/v1/travel/requests/1/approve', [
            'decision' => 'APPROVE',
        ], ['Idempotency-Key' => 'approve-denied'])->assertForbidden();
        $this->getJson('/v1/travel/expenses')->assertForbidden();
        $this->postJson('/v1/travel/expenses', [
            'travel_id' => 1,
            'amount_cents' => 1000,
            'category' => 'OTHER',
        ], ['Idempotency-Key' => 'expense-denied'])->assertForbidden();
    }
}
