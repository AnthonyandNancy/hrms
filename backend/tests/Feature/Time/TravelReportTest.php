<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\BusinessTripRecord;
use App\Modules\Time\Models\TravelExpense;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TravelReportTest extends TimeHttpTestCase
{
    public function test_report_counts_approved_and_pending_inside_the_scope(): void
    {
        $trip = $this->createApprovedTrip();

        BusinessTripRecord::query()->create([
            'employment_id' => 1,
            'from_date' => '2026-12-10',
            'to_date' => '2026-12-11',
            'destination' => 'Synthetic Pending City',
            'purpose' => 'Synthetic pending report trip.',
            'status' => 'PENDING_APPROVAL',
            'created_at' => $this->clock->now(),
        ]);

        TravelExpense::query()->create([
            'employment_id' => 1,
            'travel_id' => (int) $trip->id,
            'category' => 'TRANSPORT',
            'amount_cents' => 12345,
            'status' => 'CLAIMED',
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: ['time.travel.read'],
        ));

        $this->getJson('/v1/travel/reports/summary?from=2026-12-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('data.rows.0.approved_trips', 1)
            ->assertJsonPath('data.rows.0.pending_trips', 1)
            ->assertJsonPath('data.rows.0.approved_days', 2)
            ->assertJsonPath('data.rows.0.expense_cents', 12345)
            ->assertJsonPath('data.rows.0.employment_count', 1)
            ->assertJsonStructure(['data' => ['rows', 'total', 'as_of', 'source_hash']]);
    }

    public function test_self_scope_cannot_read_another_employee_trip_count(): void
    {
        BusinessTripRecord::query()->create([
            'employment_id' => 2,
            'from_date' => '2026-12-10',
            'to_date' => '2026-12-11',
            'destination' => 'Synthetic Other City',
            'purpose' => 'Synthetic other employee trip.',
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'SELF',
            capabilities: ['time.travel.read'],
        ));

        $this->getJson('/v1/travel/reports/summary?from=2026-12-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->getJson('/v1/travel/reports/summary?from=2026-12-01&to=2026-12-31&employment_id=999')
            ->assertNotFound();
    }

    public function test_department_outside_scope_is_indistinguishable_from_missing(): void
    {
        $this->people->add(TimeTestFixtures::employment(2));
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'MANAGED',
            capabilities: ['time.travel.read'],
            managedEmploymentIds: [1],
        ));

        $this->getJson('/v1/travel/reports/summary?from=2026-12-01&to=2026-12-31&department_id=999')
            ->assertNotFound();
    }

    public function test_report_requires_read_capability_and_valid_dates(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'SELF',
            capabilities: ['time.travel.request'],
        ));

        $this->getJson('/v1/travel/reports/summary?from=2026-12-01&to=2026-12-31')
            ->assertForbidden();

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: ['time.travel.read'],
        ));
        $this->getJson('/v1/travel/reports/summary?from=2026-12-31&to=2026-12-01')
            ->assertUnprocessable();
    }
}
