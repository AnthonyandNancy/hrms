<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\PeopleTestCase;

final class AsOfQueryTest extends PeopleTestCase
{
    public function test_t_minus_one_t_and_t_plus_one_for_employment_assignment_and_terms(): void
    {
        $scenario = $this->scenario();
        $a = $scenario->orgUnit('A');
        $b = $scenario->orgUnit('B');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'ASOF-1', ['valid_from' => '2026-01-01', 'valid_to' => '9999-12-31']);
        $scenario->assignment($employment, $a, ['valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);
        $scenario->assignment($employment, $b, ['valid_from' => '2026-06-01', 'valid_to' => '9999-12-31']);
        $scenario->terms($employment, 1, ['effective_from' => '2026-01-01', 'effective_to' => '2026-06-01', 'work_location' => 'Shanghai']);
        $scenario->terms($employment, 2, ['effective_from' => '2026-06-01', 'effective_to' => '9999-12-31', 'work_location' => 'Singapore']);

        $before = $this->getJson("/v1/employees/{$employment->id}?as_of=2026-05-31")->assertOk();
        $on = $this->getJson("/v1/employees/{$employment->id}?as_of=2026-06-01")->assertOk();
        $after = $this->getJson("/v1/employees/{$employment->id}?as_of=2026-06-02")->assertOk();

        $before->assertJsonPath('data.current_assignment.org_unit_id', $a->id)
            ->assertJsonPath('data.terms.version', 1)
            ->assertJsonPath('data.terms.work_location', 'Shanghai');
        $on->assertJsonPath('data.current_assignment.org_unit_id', $b->id)
            ->assertJsonPath('data.terms.version', 2)
            ->assertJsonPath('data.terms.work_location', 'Singapore');
        $after->assertJsonPath('data.current_assignment.org_unit_id', $b->id)
            ->assertJsonPath('data.terms.version', 2);
    }

    public function test_terminated_employee_is_absent_after_the_termination_date(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('TERM');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'TERM-1', [
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-06-01',
            'status' => 'TERMINATED',
            'termination_date' => '2026-06-01',
        ]);
        $scenario->assignment($employment, $org, ['valid_from' => '2026-01-01', 'valid_to' => '2026-06-01']);

        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-05-31")->assertOk();
        $this->getJson('/v1/employees?as_of=2026-05-31')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', $employment->id);
        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-06-01")->assertNotFound();
        $this->getJson('/v1/employees?as_of=2026-06-01')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_future_hire_is_absent_before_hire_date(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('FUTURE');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'FUT-1', ['valid_from' => '2026-12-01']);
        $scenario->assignment($employment, $org, ['valid_from' => '2026-12-01']);

        $this->getJson('/v1/employees?as_of=2026-11-30')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->getJson('/v1/employees?as_of=2026-12-01')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', $employment->id);
    }

    public function test_invalid_as_of_returns_422_and_never_scopes_silently(): void
    {
        $this->getJson('/v1/employees?as_of=2026-02-30')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['data' => ['fields' => ['as_of']]]);
        $this->getJson('/v1/employees?as_of=not-a-date')->assertUnprocessable();
    }

    public function test_list_and_detail_agree_at_the_same_as_of(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('CONSISTENT');
        $person = $scenario->person();
        $employment = $scenario->employment($person, 'CON-1');
        $scenario->assignment($employment, $org);

        $list = $this->getJson('/v1/employees?as_of=2026-11-23')->assertOk();
        $detail = $this->getJson("/v1/employees/{$employment->id}?as_of=2026-11-23")->assertOk();

        $this->assertSame($detail->json('data.current_assignment.id'), $list->json('data.items.0.current_assignment.id'));
        $this->assertSame($detail->json('data.person_id'), $list->json('data.items.0.person_id'));
        $this->assertSame($detail->json('data.employee_no'), $list->json('data.items.0.employee_no'));
    }
}
