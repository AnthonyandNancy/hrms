<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\BusinessTripRecord;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class TravelRequestFilterTest extends TimeHttpTestCase
{
    public function test_ledger_filters_by_status_dates_and_scope_with_pagination(): void
    {
        BusinessTripRecord::query()->create([
            'employment_id' => 1,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'destination' => 'Synthetic Approved City',
            'purpose' => 'Synthetic approved filter trip.',
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);
        BusinessTripRecord::query()->create([
            'employment_id' => 1,
            'from_date' => '2026-12-10',
            'to_date' => '2026-12-11',
            'destination' => 'Synthetic Cancelled City',
            'purpose' => 'Synthetic cancelled filter trip.',
            'status' => 'CANCELLED',
            'created_at' => $this->clock->now(),
        ]);

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'SELF',
            capabilities: ['time.travel.read'],
        ));

        $this->getJson('/v1/travel/requests?view=my&status=APPROVED&from=2026-12-01&to=2026-12-31&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.destination', 'Synthetic Approved City')
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10);

        $this->getJson('/v1/travel/requests?view=my&from=2026-12-05&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.status', 'CANCELLED');
    }

    public function test_my_view_cannot_read_another_employee_rows_and_approval_view_is_managed_only(): void
    {
        $this->people->add(TimeTestFixtures::employment(2));
        BusinessTripRecord::query()->create([
            'employment_id' => 2,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'destination' => 'Synthetic Managed City',
            'purpose' => 'Synthetic managed trip.',
            'status' => 'PENDING_APPROVAL',
            'created_at' => $this->clock->now(),
        ]);

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'MANAGED',
            capabilities: ['time.travel.read'],
            managedEmploymentIds: [2],
        ));

        $this->getJson('/v1/travel/requests?view=my')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->getJson('/v1/travel/requests?view=team')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', 2);
    }
}
