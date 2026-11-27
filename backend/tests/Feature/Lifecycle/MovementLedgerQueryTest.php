<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\People\Models\Assignment;
use Tests\Support\LifecycleTestCase;

/**
 * M07 Task 1 contract: one server-side ledger query covers type, status,
 * employee, effective-date range, keyword and page. The old implementation
 * filtered intent fields only and sliced the response on the client.
 */
final class MovementLedgerQueryTest extends LifecycleTestCase
{
    public function test_ledger_supports_one_query_with_type_status_employee_and_effective_date(): void
    {
        $scenario = $this->peopleScenario;
        $from = $scenario->orgUnit('LEDGER-FROM');
        $to = $scenario->orgUnit('LEDGER-TO');
        $employment = $scenario->employment($scenario->person(), 'LEDGER-0001', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $from, ['valid_from' => '2026-01-01']);

        $matching = $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $to->id,
            'effective_date' => '2026-11-15',
            'reason' => 'Synthetic ledger matching transfer',
            'submit' => true,
        ], 'ledger-matching-create')->assertCreated();

        $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $to->id,
            'effective_date' => '2026-12-15',
            'reason' => 'Synthetic ledger out-of-range transfer',
            'submit' => true,
        ], 'ledger-out-of-range-create')->assertCreated();

        $matchingCaseId = (int) $matching->json('data.case_id');

        $this->getJson('/v1/lifecycle/cases?case_type=ASSIGNMENT_CHANGE&status=SUBMITTED&employment_id='.$employment->id.'&effective_from=2026-11-01&effective_to=2026-11-30&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.case_id', $matchingCaseId)
            ->assertJsonPath('data.items.0.case_type', 'ASSIGNMENT_CHANGE')
            ->assertJsonPath('data.items.0.kind', 'transfer')
            ->assertJsonPath('data.items.0.employee_id', $employment->id)
            ->assertJsonPath('data.items.0.employee_no', 'LEDGER-0001')
            ->assertJsonPath('data.items.0.effective_date', '2026-11-15')
            ->assertJsonPath('data.items.0.application_status', 'SUBMITTED')
            ->assertJsonPath('data.items.0.lane', 'PENDING_APPROVAL');
    }

    public function test_keyword_matches_employee_no_and_employee_name_through_people_projection(): void
    {
        $scenario = $this->peopleScenario;
        $org = $scenario->orgUnit('LEDGER-KW');
        $person = $scenario->person(['legal_name' => 'Ledger Searchable']);
        $employment = $scenario->employment($person, 'KW-7788', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $org, ['valid_from' => '2026-01-01']);

        $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $org->id,
            'effective_date' => '2026-12-01',
            'reason' => 'Synthetic keyword transfer',
            'submit' => true,
        ], 'ledger-keyword-create')->assertCreated();

        $this->getJson('/v1/lifecycle/cases?q=KW-7788')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employee_no', 'KW-7788');

        $this->getJson('/v1/lifecycle/cases?q=Searchable')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employee_name', 'Ledger Searchable');
    }

    public function test_pagination_happens_after_server_filters_not_on_the_client(): void
    {
        $scenario = $this->peopleScenario;
        $org = $scenario->orgUnit('LEDGER-PAGE');
        $employment = $scenario->employment($scenario->person(), 'PAGE-0001', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $org, ['valid_from' => '2026-01-01']);

        foreach (['2026-12-01', '2026-12-02', '2026-12-03'] as $index => $date) {
            $this->createLifecycleCase('transfers', [
                'employment_id' => $employment->id,
                'org_unit_id' => $org->id,
                'effective_date' => $date,
                'reason' => 'Synthetic page transfer '.$index,
                'submit' => true,
            ], 'ledger-page-create-'.$index)->assertCreated();
        }

        $first = $this->getJson('/v1/lifecycle/cases?kind=transfer&page=1&page_size=2')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 2);
        $this->assertCount(2, (array) $first->json('data.items'));

        $second = $this->getJson('/v1/lifecycle/cases?kind=transfer&page=2&page_size=2')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.page', 2);
        $this->assertCount(1, (array) $second->json('data.items'));
        $this->assertNotSame(
            $first->json('data.items.0.case_id'),
            $second->json('data.items.0.case_id'),
        );
    }

    public function test_unified_ledger_includes_secondment_assignment_rows_and_calendar_lanes(): void
    {
        [$employment, $home, $host] = $this->seedEmploymentWithOrg();

        $this->postJson('/v1/lifecycle/secondments', [
            'employment_id' => $employment->id,
            'host_org_unit_id' => $host->id,
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-03-31',
            'reason' => 'Synthetic calendar secondment',
        ], ['Idempotency-Key' => 'ledger-secondment-create'])->assertCreated();

        $this->getJson('/v1/lifecycle/cases?kind=secondment&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.kind', 'secondment')
            ->assertJsonPath('data.items.0.case_id', null)
            ->assertJsonPath('data.items.0.lane', 'SCHEDULED')
            ->assertJsonPath('data.items.0.application_status', 'SCHEDULED')
            ->assertJsonPath('data.items.0.employee_id', $employment->id);

        $assignment = Assignment::query()
            ->where('employment_id', $employment->id)
            ->where('assignment_type', 'SECONDMENT')
            ->firstOrFail();

        $this->getJson('/v1/lifecycle/cases?kind=secondment&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.items.0.assignment_id', (int) $assignment->id);
    }

    public function test_invalid_effective_range_is_rejected_before_query(): void
    {
        $this->getJson('/v1/lifecycle/cases?effective_from=2026-12-31&effective_to=2026-12-01')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('data.reason', 'EFFECTIVE_DATE_RANGE_INVALID');

        $this->assertSame(0, LifecycleIntent::query()->count());
    }

    public function test_ledger_returns_effective_lane_and_pending_approval_never_reports_applied(): void
    {
        $scenario = $this->peopleScenario;
        $from = $scenario->orgUnit('LEDGER-LANE-FROM');
        $to = $scenario->orgUnit('LEDGER-LANE-TO');
        $employment = $scenario->employment($scenario->person(), 'LANE-0001', ['valid_from' => '2026-01-01']);
        $scenario->assignment($employment, $from, ['valid_from' => '2026-01-01']);

        $created = $this->createLifecycleCase('transfers', [
            'employment_id' => $employment->id,
            'org_unit_id' => $to->id,
            'effective_date' => '2026-12-01',
            'reason' => 'Synthetic lane transfer',
            'submit' => true,
        ], 'ledger-lane-create')->assertCreated();

        $this->getJson('/v1/lifecycle/cases?kind=transfer')
            ->assertOk()
            ->assertJsonPath('data.items.0.lane', 'PENDING_APPROVAL')
            ->assertJsonPath('data.items.0.effect_status', 'NONE');

        $this->approveCase((int) $created->json('data.case_id'), 'ledger-lane-approve')
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        // The frozen clock is 2026-11-23 and the effective date is 2026-12-01,
        // so an applied effect for a future business date belongs in the
        // SCHEDULED lane, not the already-effective lane.
        $this->getJson('/v1/lifecycle/cases?kind=transfer')
            ->assertOk()
            ->assertJsonPath('data.items.0.lane', 'SCHEDULED')
            ->assertJsonPath('data.items.0.effect_status', 'APPLIED');
    }
}
